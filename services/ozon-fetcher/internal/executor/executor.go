// Package executor выполняет одну захваченную задачу загрузки: страницы Ozon →
// S3 → журнал, итог по таблице исходов (docs/tasks/ozon-fetcher-ingestion/stages/stage-9.md).
package executor

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

// Ресурсы первой волны (contract marketplace-connections v1).
const (
	ResourceAccrualByDay     = "ozon.finance.accrual-by-day"
	ResourceAccrualTypes     = "ozon.finance.accrual-types"
	ResourceRealization      = "ozon.finance.realization"
	ResourceMutualSettlement = "ozon.finance.mutual-settlement"

	// PeriodRef — period_key справочников.
	PeriodRef = "ref"
)

// TaskStore — часть store.Tasks, нужная исполнителю.
type TaskStore interface {
	ExtendLease(ctx context.Context, key store.TaskKey, attemptID string, until time.Time) error
	RecordPage(ctx context.Context, key store.TaskKey, attemptID string, obj store.RawObject, nextCursor string) error
	FinishTask(ctx context.Context, key store.TaskKey, attemptID string, out store.Outcome, now time.Time) (int, error)
	RecordAuthFailure(ctx context.Context, connectionRef string, now time.Time, pauseFor func(failures int) time.Duration) (int, error)
	ResumeConnection(ctx context.Context, connectionRef string) error
	StopResource(ctx context.Context, resourceType, reason string) error
}

// RawStore — запись страницы в S3.
type RawStore interface {
	Put(ctx context.Context, obj rawstore.Object) (rawstore.Stored, error)
}

// Ozon — загрузки ресурсов первой волны.
type Ozon interface {
	FetchAccrualByDay(ctx context.Context, creds ozon.Credentials, connectionRef, day, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
	FetchAccrualTypes(ctx context.Context, creds ozon.Credentials, connectionRef string) (ozon.Page, error)
	FetchRealization(ctx context.Context, creds ozon.Credentials, connectionRef string, year, month int) (ozon.Page, error)
	FetchMutualSettlement(ctx context.Context, creds ozon.Credentials, connectionRef string, year, month int, poll ozon.ReportPoll, sleep func(context.Context, time.Duration) error) (ozon.Page, error)
}

// Refresher — обновление снимка кабинетов после 401.
type Refresher interface {
	ForceRefresh(ctx context.Context) (bool, error)
}

// Config — тайминги исполнителя.
type Config struct {
	Lease           time.Duration // срок lease; продление — раз в треть
	FinishTimeout   time.Duration // запись итога после отмены
	BackoffBase     time.Duration // transient: base·2^(n−1)
	BackoffMax      time.Duration
	RateLimitPause  time.Duration // 429 без Retry-After
	AuthRetryPause  time.Duration // пауза кабинета после первого auth: снимок с новым ключом успевает обновиться
	AuthSuspend     time.Duration // пауза кабинета на втором auth подряд
	NoCredentialsIn time.Duration // повтор задачи без ключа seller
	NotReadyDelay   map[string]time.Duration
	ReportPoll      ozon.ReportPoll
}

// DefaultConfig — значения плана (stage-9.md).
func DefaultConfig() Config {
	return Config{
		Lease:           2 * time.Minute,
		FinishTimeout:   10 * time.Second,
		BackoffBase:     time.Minute,
		BackoffMax:      time.Hour,
		RateLimitPause:  30 * time.Second,
		AuthRetryPause:  time.Minute,
		AuthSuspend:     time.Hour,
		NoCredentialsIn: time.Hour,
		NotReadyDelay: map[string]time.Duration{
			ResourceAccrualByDay:     time.Hour,
			ResourceAccrualTypes:     time.Hour,
			ResourceRealization:      24 * time.Hour,
			ResourceMutualSettlement: 24 * time.Hour,
		},
		ReportPoll: ozon.DefaultReportPoll,
	}
}

// Executor — без состояния задачи; безопасен для параллельных Run.
type Executor struct {
	cfg       Config
	tasks     TaskStore
	raw       RawStore
	ozon      Ozon
	refresher Refresher
	logger    *slog.Logger
	now       func() time.Time
	jitter    func(time.Duration) time.Duration // [0; d)
	sleep     func(context.Context, time.Duration) error
}

func New(cfg Config, tasks TaskStore, raw RawStore, client Ozon, refresher Refresher, logger *slog.Logger,
	now func() time.Time, jitter func(time.Duration) time.Duration, sleep func(context.Context, time.Duration) error) *Executor {
	return &Executor{cfg: cfg, tasks: tasks, raw: raw, ozon: client, refresher: refresher, logger: logger, now: now, jitter: jitter, sleep: sleep}
}

// errLeaseLost — внутренний сигнал: продление не удалось, задачу ведёт другой воркер.
var errLeaseLost = errors.New("executor: lease lost")

// Result — итог для диспетчера и логов.
type Result struct {
	Outcome  string
	Pages    int
	Revision int
}

// Run выполняет задачу. traceID — сквозной идентификатор для логов.
func (e *Executor) Run(ctx context.Context, task store.Task, cabinet connections.Cabinet, traceID string) Result {
	started := e.now()
	log := e.logger.With(
		slog.String("job_id", task.AttemptID), slog.String("trace_id", traceID),
		slog.String("connection_ref", task.ConnectionRef), slog.String("resource_type", task.ResourceType),
		slog.String("period_key", task.PeriodKey), slog.Int("retry_count", task.Attempts-1),
	)
	log.Info("fetch task started", slog.String("stage", "fetch"))

	taskCtx, cancel := context.WithCancelCause(ctx)
	defer cancel(nil)
	stopLease := e.keepLease(taskCtx, cancel, task, log)

	run := &taskRun{exec: e, task: task, hashes: append([]string(nil), task.PageHashes...), cursor: task.Cursor, nextPage: task.NextPageNo}
	fetchErr := run.fetch(taskCtx, cabinet)

	// Lease продлевается и во время побочных действий исхода (пауза кабинета,
	// стоп ресурса); у них свой ограниченный контекст.
	out := e.outcome(ctx, taskCtx, task, run, fetchErr, log)
	stopLease()
	result := Result{Outcome: out.Label, Pages: run.pages}

	if !errors.Is(context.Cause(taskCtx), errLeaseLost) {
		finishCtx, finishCancel := context.WithTimeout(context.WithoutCancel(ctx), e.cfg.FinishTimeout)
		revision, err := e.tasks.FinishTask(finishCtx, task.TaskKey, task.AttemptID, out, e.now())
		finishCancel()
		switch {
		case errors.Is(err, store.ErrLeaseLost):
			result.Outcome = "lease_lost"
		case err != nil:
			log.Error("fetch task finish failed", slog.String("stage", "fetch"), slog.String("error", err.Error()))
			result.Outcome = "finish_failed"
		default:
			result.Revision = revision
			if out.Label == "done" && task.ContentHash != "" && revision > task.Revision {
				log.Info("source revised", slog.String("stage", "fetch"), slog.Int("revision", revision))
			}
		}
	} else {
		result.Outcome = "lease_lost"
	}

	level := slog.LevelInfo
	switch out.Status {
	case store.StatusFailedRetryable:
		level = slog.LevelWarn
	case store.StatusFailedPermanent:
		level = slog.LevelError
	}
	if result.Outcome == "lease_lost" {
		level = slog.LevelWarn
	}
	attrs := []any{slog.String("stage", "fetch"), slog.String("outcome", result.Outcome), slog.String("error_class", out.ErrorClass),
		slog.Int("pages", run.pages), slog.Int64("duration_ms", e.now().Sub(started).Milliseconds())}
	if fetchErr != nil {
		attrs = append(attrs, slog.String("error", fetchErr.Error()))
	}
	log.Log(context.WithoutCancel(ctx), level, "fetch task finished", attrs...)
	return result
}

// keepLease продлевает lease раз в треть срока; потеря — отмена задачи.
// У продления свой контекст: остановка прерывает и зависший вызов к БД,
// каждый вызов ограничен третью срока lease.
func (e *Executor) keepLease(ctx context.Context, cancel context.CancelCauseFunc, task store.Task, log *slog.Logger) func() {
	renewCtx, stopRenew := context.WithCancel(ctx)
	stopped := make(chan struct{})
	go func() {
		defer close(stopped)
		interval := e.cfg.Lease / 3
		for {
			select {
			case <-renewCtx.Done():
				return
			case <-time.After(interval):
			}
			callCtx, callCancel := context.WithTimeout(renewCtx, interval)
			err := e.tasks.ExtendLease(callCtx, task.TaskKey, task.AttemptID, e.now().Add(e.cfg.Lease))
			callCancel()
			switch {
			case errors.Is(err, store.ErrLeaseLost):
				log.Warn("fetch task lease lost", slog.String("stage", "fetch"))
				cancel(errLeaseLost)
				return
			case err != nil && renewCtx.Err() == nil:
				// Сбой БД: lease ещё действует, пробуем на следующем тике.
				log.Warn("fetch task lease extension failed", slog.String("stage", "fetch"), slog.String("error", err.Error()))
			}
		}
	}()
	return func() {
		stopRenew()
		<-stopped
	}
}

// taskRun — состояние одной попытки.
type taskRun struct {
	exec     *Executor
	task     store.Task
	hashes   []string
	cursor   string
	nextPage int
	pages    int
}

// errNoCredentials — у кабинета нет ключа seller для финансового ресурса.
var errNoCredentials = errors.New("executor: no seller credentials")

func (r *taskRun) fetch(ctx context.Context, cabinet connections.Cabinet) error {
	if cabinet.Seller == nil {
		return errNoCredentials
	}
	creds := *cabinet.Seller
	ref := r.task.ConnectionRef
	e := r.exec

	switch r.task.ResourceType {
	case ResourceAccrualByDay:
		if r.cursor == "" && len(r.hashes) > 0 {
			// Все страницы дня уже сохранены прежней попыткой (упала запись
			// итога): загружать день заново — удвоить страницы и хеш.
			return nil
		}
		return e.ozon.FetchAccrualByDay(ctx, creds, ref, r.task.PeriodKey, r.cursor, r.nextPage, func(p ozon.Page) error {
			return r.store(ctx, p)
		})
	case ResourceAccrualTypes:
		return r.single(ctx, func() (ozon.Page, error) { return e.ozon.FetchAccrualTypes(ctx, creds, ref) })
	case ResourceRealization, ResourceMutualSettlement:
		year, month, err := parseMonth(r.task.PeriodKey)
		if err != nil {
			return err
		}
		if r.task.ResourceType == ResourceRealization {
			return r.single(ctx, func() (ozon.Page, error) { return e.ozon.FetchRealization(ctx, creds, ref, year, month) })
		}
		return r.single(ctx, func() (ozon.Page, error) {
			return e.ozon.FetchMutualSettlement(ctx, creds, ref, year, month, e.cfg.ReportPoll, e.sleep)
		})
	default:
		return fmt.Errorf("%w: %s", errUnknownResource, r.task.ResourceType)
	}
}

var (
	errUnknownResource = errors.New("executor: unknown resource type")
	errBadPeriod       = errors.New("executor: bad period key")
)

// single — ресурс одной страницей. Страница, сохранённая прежней попыткой
// (упала запись итога или процесс), не загружается повторно.
func (r *taskRun) single(ctx context.Context, fetch func() (ozon.Page, error)) error {
	if len(r.hashes) > 0 {
		return nil
	}
	r.cursor, r.nextPage = "", 1
	page, err := fetch()
	if err != nil {
		return err
	}
	page.No = 1
	return r.store(ctx, page)
}

// storeError — сбой S3 или журнала при сохранении страницы.
type storeError struct{ err error }

func (e *storeError) Error() string { return "executor: store page: " + e.err.Error() }
func (e *storeError) Unwrap() error { return e.err }

// store: S3 → журнал (страница, курсор, хеш) — до запроса следующей страницы.
func (r *taskRun) store(ctx context.Context, p ozon.Page) error {
	e := r.exec
	stored, err := e.raw.Put(ctx, rawstore.Object{
		Provider: "ozon", ConnectionRef: r.task.ConnectionRef, ResourceType: r.task.ResourceType,
		PeriodKey: r.task.PeriodKey, AttemptID: r.task.AttemptID, PageNo: p.No, FetchedAt: e.now(),
		ContentType: p.ContentType, Body: p.Body,
	})
	if err != nil {
		return &storeError{err}
	}
	err = e.tasks.RecordPage(ctx, r.task.TaskKey, r.task.AttemptID, store.RawObject{
		PageNo: p.No, StorageURI: stored.URI, SHA256: stored.SHA256, SizeBytes: stored.SizeBytes,
		StoredBytes: stored.StoredBytes, ContentType: p.ContentType,
	}, p.Cursor)
	if errors.Is(err, store.ErrLeaseLost) {
		return err
	}
	if err != nil {
		return &storeError{err}
	}
	r.hashes = append(r.hashes, stored.SHA256)
	r.cursor, r.nextPage = p.Cursor, p.No+1
	r.pages++
	return nil
}

// contentHash — sha256 упорядоченного списка хешей страниц загрузки.
func contentHash(hashes []string) string {
	sum := sha256.Sum256([]byte(strings.Join(hashes, "\n")))
	return hex.EncodeToString(sum[:])
}

func parseMonth(periodKey string) (int, int, error) {
	t, err := time.Parse("2006-01", periodKey)
	if err != nil {
		return 0, 0, fmt.Errorf("%w: %q", errBadPeriod, periodKey)
	}
	return t.Year(), int(t.Month()), nil
}
