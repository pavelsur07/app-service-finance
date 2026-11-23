// Package executor — загрузка задачи WB поверх исполнителя ядра
// (fetchkit/fetch): страницы детального отчёта с курсором rrdId в raw S3.
package executor

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/fetch"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

// ResourceDaily — детальный отчёт о реализации за день МСК (period_key YYYY-MM-DD).
const ResourceDaily = "wb.finance.sales-report.daily"

// ResourceAds — реклама за день МСК: списания кампаний и их статистика (одна страница).
const ResourceAds = "wb.ads.daily"

// IsAdsResource — ресурс семейства рекламы: свой слот кабинета, свой ключ доступа.
func IsAdsResource(resource string) bool { return strings.HasPrefix(resource, "wb.ads.") }

// ResourceFunnel — воронка продаж по карточкам за день МСК (одна страница):
// переходы в карточку, корзины, заказы, выкупы (docs/tasks/marketplace-funnel/TASK.md).
const ResourceFunnel = "wb.analytics.funnel"

// ownAccess — ресурс со своей категорией доступа токена (продвижение,
// аналитика): отказ 401/403 — пауза только этой задачи, финансы кабинета идут.
func ownAccess(resource string) bool {
	return IsAdsResource(resource) || resource == ResourceFunnel || resource == ResourceSearch
}

// ResourceSearch — поиск по карточкам за день МСК (одна страница): видимость,
// позиция, переходы, корзины и заказы из поиска; у WB — только с «Джемом»,
// без него день — служебная страница wb.SearchUnavailablePage.
const ResourceSearch = "wb.analytics.search"

// FunnelSource — день воронки (wb.Analytics).
type FunnelSource interface {
	FunnelDay(ctx context.Context, ref, token, day string) (wb.FunnelDay, error)
	SearchDay(ctx context.Context, ref, token, day string) (wb.FunnelDay, error)
}

// AdsSource — день рекламы (wb.Ads).
type AdsSource interface {
	Day(ctx context.Context, ref, token, day string) (wb.AdsDay, error)
}

// DefaultMaxPages — предел страниц периода: 250 × 20 000 строк (5 млн) —
// заведомо больше реального отчёта; превышение — зацикливание курсора, а не данные.
const DefaultMaxPages = 250

var errTooManyPages = errors.New("executor: page limit exceeded")

// errSearchRefused — WB отказал в поиске дню, у которого уже есть ревизия с
// данными (подписка закончилась): данные не затираются служебной страницей.
var errSearchRefused = errors.New("executor: wb refused search for a day that already has data")

func unavailableHash(status int) string {
	return fetch.ContentHash([]string{fetch.SHA256Hex(wb.SearchUnavailablePage(status))})
}

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
	Location        *time.Location // пояс периодов: Europe/Moscow
}

// DefaultConfig — как у ozon-fetcher; lease покрывает ожидание лимита (70 с) и
// страницу до 120 с.
func DefaultConfig() Config {
	return Config{
		Lease: 10 * time.Minute, FinishTimeout: 10 * time.Second,
		BackoffBase: time.Minute, BackoffMax: 6 * time.Hour,
		RateLimitPause: wb.DefaultRateLimitPause, AuthRetryPause: 10 * time.Minute, AuthSuspend: 6 * time.Hour,
		NoCredentialsIn: time.Hour, NotReadyDelay: map[string]time.Duration{},
		MaxPages: DefaultMaxPages, PageLimit: wb.PageLimit, Location: moscow,
	}
}

// Executor — fetch.Adapter WB.
type Executor struct {
	core   *fetch.Executor[connections.Cabinet]
	cfg    Config
	report Report
	ads    AdsSource
	funnel FunnelSource
	now    func() time.Time
}

// WithFunnel — источник воронки; без него задачи wb.analytics.funnel — неизвестный ресурс.
func (e *Executor) WithFunnel(f FunnelSource) *Executor {
	e.funnel = f
	return e
}

// WithAds — источник рекламы; без него задачи wb.ads.daily — неизвестный ресурс.
func (e *Executor) WithAds(ads AdsSource) *Executor {
	e.ads = ads
	return e
}

func New(cfg Config, tasks fetch.TaskStore, raw fetch.RawStore, report Report, refresher fetch.Refresher, logger *slog.Logger,
	now func() time.Time, jitter func(time.Duration) time.Duration) *Executor {
	if cfg.Location == nil {
		cfg.Location = moscow
	}
	e := &Executor{cfg: cfg, report: report, now: now}
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
	resource := run.Task.ResourceType
	if resource == ResourceAds && e.ads != nil {
		return e.dayPage(ctx, run, cab, func(day string) ([]byte, slog.Attr, error) {
			page, err := e.ads.Day(ctx, cab.Ref, cab.Token.Reveal(), day)
			return page.Body, slog.Int("campaigns", page.Campaigns), err
		})
	}
	if resource == ResourceFunnel && e.funnel != nil {
		return e.dayPage(ctx, run, cab, func(day string) ([]byte, slog.Attr, error) {
			page, err := e.funnel.FunnelDay(ctx, cab.Ref, cab.Token.Reveal(), day)
			return page.Body, slog.Int("products", page.Products), err
		})
	}
	if resource == ResourceSearch && e.funnel != nil {
		return e.dayPage(ctx, run, cab, func(day string) ([]byte, slog.Attr, error) {
			page, err := e.funnel.SearchDay(ctx, cab.Ref, cab.Token.Reveal(), day)
			if status := wb.SearchUnavailable(err); status != 0 {
				if h := run.Task.ContentHash; h != "" && h != unavailableHash(http.StatusPaymentRequired) && h != unavailableHash(http.StatusForbidden) {
					return nil, slog.Attr{}, errSearchRefused
				}
				return wb.SearchUnavailablePage(status), slog.Int("unavailable_status", status), nil
			}
			return page.Body, slog.Int("products", page.Products), err
		})
	}
	if resource != ResourceDaily && resource != ResourceWeekly {
		return fmt.Errorf("%w: %q", fetch.ErrUnknownResource, resource)
	}
	if cab.Token == "" {
		return fetch.ErrNoCredentials
	}
	if !cab.Supports(resource) {
		return fetch.ErrResourceNotEnabled
	}
	if resource == ResourceWeekly {
		monday, err := ParseWeek(run.Task.PeriodKey, e.cfg.Location)
		if err != nil {
			return fmt.Errorf("%w: %q", fetch.ErrBadPeriod, run.Task.PeriodKey)
		}
		return e.pages(ctx, run, cab, wb.PeriodWeekly, monday.Format(time.DateOnly), monday.AddDate(0, 0, 6).Format(time.DateOnly),
			monday.Add(WeekReadyAfter))
	}
	day, err := time.Parse(time.DateOnly, run.Task.PeriodKey)
	if err != nil {
		return fmt.Errorf("%w: %q", fetch.ErrBadPeriod, run.Task.PeriodKey)
	}
	return e.pages(ctx, run, cab, wb.PeriodDaily, day.Format(time.DateOnly), day.Format(time.DateOnly), time.Time{})
}

// dayPage — день ресурса одной страницей (реклама, воронка): load собирает
// страницу дня и счётчик для журнала.
func (e *Executor) dayPage(ctx context.Context, run *fetch.Run, cab connections.Cabinet, load func(day string) ([]byte, slog.Attr, error)) error {
	if cab.Token == "" {
		return fetch.ErrNoCredentials
	}
	if !cab.Supports(run.Task.ResourceType) {
		return fetch.ErrResourceNotEnabled
	}
	if len(run.Hashes) > 0 {
		return nil // страница сохранена прошлой попыткой, не записан только итог
	}
	day, err := time.Parse(time.DateOnly, run.Task.PeriodKey)
	if err != nil {
		return fmt.Errorf("%w: %q", fetch.ErrBadPeriod, run.Task.PeriodKey)
	}
	body, count, err := load(day.Format(time.DateOnly))
	if err != nil {
		return err
	}
	if err := run.Store(ctx, fetch.Page{No: run.NextPage, Body: body, ContentType: "application/json"}); err != nil {
		return err
	}
	run.Log.Info("day page read", slog.String("stage", "fetch"), count)
	return nil
}

// pages — с курсора задачи до страницы короче limit; каждая страница с
// курсором продолжения пишется до запроса следующей. readyAt — крайний срок
// формирования отчёта: пустая первая страница раньше него — not_ready
// (нулевой readyAt — пустой ответ всегда означает пустой период).
func (e *Executor) pages(ctx context.Context, run *fetch.Run, cab connections.Cabinet, period, from, to string, readyAt time.Time) error {
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
		if page.Rows == 0 && cursor == 0 && run.Pages == started && e.now().Before(readyAt) {
			return &apierr.APIError{Source: wb.Source, Class: apierr.ClassNotReady, Endpoint: wb.EndpointSalesReport, Status: http.StatusOK,
				Err: fmt.Errorf("%s report %s..%s is not formed yet (deadline %s)", period, from, to, readyAt.Format(time.RFC3339))}
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
	var qe *QuotaWaitError
	switch {
	case errors.As(c.Err, &qe):
		label := "symfony_quota"
		if qe.Unavailable {
			label = "symfony_quota_unavailable"
		}
		return store.Outcome{Status: store.StatusPending, Label: label, ErrorClass: label, KeepCursor: true, NextRunAt: qe.Until}, true
	case ownAccess(c.Task.ResourceType) && isAuth(c.Err):
		// Токен без доступа к продвижению или аналитике: пауза только этой задаче, финансы кабинета идут дальше.
		pause := e.cfg.AuthRetryPause
		if c.Task.LastErrorClass == string(apierr.ClassAuth) {
			pause = e.cfg.AuthSuspend
			c.Log.Error("wb api rejected cabinet token again, ads task paused", slog.String("stage", "fetch"))
		}
		return store.Outcome{Status: store.StatusPending, Label: string(apierr.ClassAuth), ErrorClass: string(apierr.ClassAuth), NextRunAt: c.Now.Add(pause)}, true
	case errors.Is(c.Err, errSearchRefused):
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "search_refused", ErrorClass: "search_refused", NextRunAt: c.Now}, true
	case errors.Is(c.Err, errTooManyPages):
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "too_many_pages", ErrorClass: "too_many_pages", NextRunAt: c.Now}, true
	}
	return store.Outcome{}, false
}

// ResumesConnection — успех отчёта снимает паузу кабинета после 401; реклама и аналитика — другой доступ токена.
func (e *Executor) ResumesConnection(resource string) bool { return !ownAccess(resource) }

func isAuth(err error) bool {
	var apiErr *apierr.APIError
	return errors.As(err, &apiErr) && apiErr.Class == apierr.ClassAuth
}

// LogLevel — квота Symfony не отвечает — WARN (повтор сам, но долгий отказ
// останавливает загрузку); остальное — уровни ядра.
func (e *Executor) LogLevel(o store.Outcome) (slog.Level, bool) {
	if o.Label == "symfony_quota_unavailable" {
		return slog.LevelWarn, true
	}
	return 0, false
}
