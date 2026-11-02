// Package executor — загрузка задачи WB поверх исполнителя ядра
// (fetchkit/fetch): страницы детального отчёта с курсором rrdId в raw S3.
package executor

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strconv"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/fetch"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

// ResourceDaily — детальный отчёт о реализации за день МСК (period_key YYYY-MM-DD).
const ResourceDaily = "wb.finance.sales-report.daily"

// DefaultMaxPages — предел страниц дня: 50 × 100 000 строк — заведомо больше
// реального дня; превышение — зацикливание курсора, а не данные.
const DefaultMaxPages = 50

var errTooManyPages = errors.New("executor: page limit exceeded")

// Report — страница отчёта (wb.Client).
type Report interface {
	SalesReportPage(ctx context.Context, r wb.ReportRequest) (wb.ReportPage, error)
}

// Config — тайминги исполнителя и пределы.
type Config struct {
	Lease           time.Duration
	FinishTimeout   time.Duration
	BackoffBase     time.Duration
	BackoffMax      time.Duration
	RateLimitPause  time.Duration
	AuthRetryPause  time.Duration
	AuthSuspend     time.Duration
	NoCredentialsIn time.Duration
	NotReadyDelay   map[string]time.Duration
	MaxPages        int
	PageLimit       int
}

// DefaultConfig — как у ozon-fetcher; lease покрывает ожидание лимита (70 с) и
// страницу до 120 с.
func DefaultConfig() Config {
	return Config{
		Lease: 10 * time.Minute, FinishTimeout: 10 * time.Second,
		BackoffBase: time.Minute, BackoffMax: 6 * time.Hour,
		RateLimitPause: wb.DefaultRateLimitPause, AuthRetryPause: 10 * time.Minute, AuthSuspend: 6 * time.Hour,
		NoCredentialsIn: time.Hour, NotReadyDelay: map[string]time.Duration{},
		MaxPages: DefaultMaxPages, PageLimit: wb.PageLimit,
	}
}

// Executor — fetch.Adapter WB.
type Executor struct {
	core   *fetch.Executor[connections.Cabinet]
	cfg    Config
	report Report
}

func New(cfg Config, tasks fetch.TaskStore, raw fetch.RawStore, report Report, refresher fetch.Refresher, logger *slog.Logger,
	now func() time.Time, jitter func(time.Duration) time.Duration) *Executor {
	e := &Executor{cfg: cfg, report: report}
	e.core = fetch.New[connections.Cabinet](fetch.Config{
		Provider: wb.Source, Lease: cfg.Lease, FinishTimeout: cfg.FinishTimeout,
		BackoffBase: cfg.BackoffBase, BackoffMax: cfg.BackoffMax, RateLimitPause: cfg.RateLimitPause,
		AuthRetryPause: cfg.AuthRetryPause, AuthSuspend: cfg.AuthSuspend, NoCredentialsIn: cfg.NoCredentialsIn,
		NotReadyDelay: cfg.NotReadyDelay,
	}, tasks, raw, refresher, e, logger, now, jitter)
	return e
}

// Run — одна задача (dispatcher.Runner).
func (e *Executor) Run(ctx context.Context, task store.Task, cabinet connections.Cabinet, traceID string) fetch.Result {
	return e.core.Run(ctx, task, cabinet, traceID)
}

// Fetch — fetch.Adapter.
func (e *Executor) Fetch(ctx context.Context, run *fetch.Run, cab connections.Cabinet) error {
	if run.Task.ResourceType != ResourceDaily {
		return fmt.Errorf("%w: %q", fetch.ErrUnknownResource, run.Task.ResourceType)
	}
	if cab.Token == "" {
		return fetch.ErrNoCredentials
	}
	if !cab.Supports(run.Task.ResourceType) {
		return fetch.ErrResourceNotEnabled
	}
	day, err := time.Parse(time.DateOnly, run.Task.PeriodKey)
	if err != nil {
		return fmt.Errorf("%w: %q", fetch.ErrBadPeriod, run.Task.PeriodKey)
	}
	return e.pages(ctx, run, cab, wb.PeriodDaily, day.Format(time.DateOnly), day.Format(time.DateOnly))
}

// pages — с курсора задачи до страницы короче limit; каждая страница с
// курсором продолжения пишется до запроса следующей.
func (e *Executor) pages(ctx context.Context, run *fetch.Run, cab connections.Cabinet, period, from, to string) error {
	if run.Cursor == "" && len(run.Hashes) > 0 {
		return nil // все страницы сохранены прошлой попыткой, не записан только итог
	}
	var cursor int64
	if run.Cursor != "" {
		c, err := strconv.ParseInt(run.Cursor, 10, 64)
		if err != nil || c <= 0 {
			return fmt.Errorf("%w: cursor %q", fetch.ErrBadPeriod, run.Cursor)
		}
		cursor = c
	}
	started, rows := run.Pages, 0
	for {
		if run.Pages-started >= e.cfg.MaxPages {
			return fmt.Errorf("%w: %d pages", errTooManyPages, e.cfg.MaxPages)
		}
		page, err := e.report.SalesReportPage(ctx, wb.ReportRequest{ConnectionRef: cab.Ref, Token: cab.Token.Reveal(),
			DateFrom: from, DateTo: to, Period: period, RrdID: cursor, Limit: e.cfg.PageLimit})
		if err != nil {
			return err
		}
		next := ""
		if page.More {
			next = strconv.FormatInt(page.NextRrdID, 10)
		}
		if err := run.Store(ctx, fetch.Page{No: run.NextPage, Body: page.Body, Cursor: next, ContentType: "application/json"}); err != nil {
			return err
		}
		rows += page.Rows
		if !page.More {
			run.Log.Info("sales report read", slog.String("stage", "fetch"), slog.Int("pages", run.Pages-started),
				slog.Int("rows", rows), slog.Bool("continued", run.Task.Cursor != ""))
			return nil
		}
		cursor = page.NextRrdID
	}
}

// Classify — свои исходы WB раньше общей таблицы ядра.
func (e *Executor) Classify(c *fetch.Classification) (store.Outcome, bool) {
	var we *WindowError
	switch {
	case errors.As(c.Err, &we):
		return store.Outcome{Status: store.StatusPending, Label: "symfony_window", ErrorClass: "symfony_window", KeepCursor: true, NextRunAt: we.Until}, true
	case errors.Is(c.Err, errTooManyPages):
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "too_many_pages", ErrorClass: "too_many_pages", NextRunAt: c.Now}, true
	}
	return store.Outcome{}, false
}

// ResumesConnection — успех снимает паузу кабинета после 401.
func (e *Executor) ResumesConnection(string) bool { return true }

// LogLevel — уровни по умолчанию ядра.
func (e *Executor) LogLevel(store.Outcome) (slog.Level, bool) { return 0, false }
