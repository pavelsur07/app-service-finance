// Package fetch выполняет одну захваченную задачу загрузки: страницы
// источника → S3 → журнал, продление lease, ревизия по хешу содержимого и
// исход задачи. Что и как запрашивать у источника, решает адаптер сервиса
// (Adapter): ресурсы, свои ошибки и уровни логов.
package fetch

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

// Page — страница ответа источника.
type Page struct {
	No          int    // с 1
	Body        []byte // как прислал источник
	Cursor      string // курсор следующей страницы; "" — последняя
	ContentType string
}

// TaskStore — часть store.Tasks, нужная исполнителю.
type TaskStore interface {
	ExtendLease(ctx context.Context, key store.TaskKey, attemptID string, until time.Time) error
	RecordPage(ctx context.Context, key store.TaskKey, attemptID string, obj store.RawObject, nextCursor string) error
	FindObject(ctx context.Context, key store.TaskKey, sha256 string) (store.RawObject, bool, error)
	FinishTask(ctx context.Context, key store.TaskKey, attemptID string, out store.Outcome, now time.Time) (int, error)
	RecordAuthFailure(ctx context.Context, connectionRef string, now time.Time, pauseFor func(failures int) time.Duration) (int, error)
	ResumeConnection(ctx context.Context, connectionRef string) error
	StopResource(ctx context.Context, resourceType, reason string) error
}

// RawStore — запись страницы в S3.
type RawStore interface {
	Put(ctx context.Context, obj rawstore.Object) (rawstore.Stored, error)
}

// Refresher — обновление снимка кабинетов после 401.
type Refresher interface {
	ForceRefresh(ctx context.Context) (bool, error)
}

// Adapter — ресурсы и особенности источника.
type Adapter[C any] interface {
	// Fetch загружает задачу: страницы — через run.Store / run.Single.
	Fetch(ctx context.Context, run *Run, cabinet C) error
	// Classify — исход для ошибки, которую знает только адаптер; false —
	// общая таблица исходов. Вызывается после успеха, потери lease и остановки.
	Classify(c *Classification) (store.Outcome, bool)
	// ResumesConnection — успех ресурса сбрасывает счётчик отказов ключа
	// кабинета (false — у ресурса свой ключ, например реклама).
	ResumesConnection(resourceType string) bool
	// LogLevel — уровень записи «fetch task finished» для исхода; false — общий.
	LogLevel(out store.Outcome) (slog.Level, bool)
}

// Config — тайминги исполнителя.
type Config struct {
	Provider        string        // префикс ключа S3: ozon, wb
	Lease           time.Duration // срок lease; продление — раз в треть
	FinishTimeout   time.Duration // запись итога после отмены
	BackoffBase     time.Duration // transient: base·2^(n−1)
	BackoffMax      time.Duration
	RateLimitPause  time.Duration // 429 без Retry-After
	AuthRetryPause  time.Duration // пауза кабинета после первого auth: снимок с новым ключом успевает обновиться
	AuthSuspend     time.Duration // пауза кабинета на втором auth подряд
	NoCredentialsIn time.Duration // повтор задачи без ключа или с выключенным ресурсом
	NotReadyDelay   map[string]time.Duration
}

// Executor — без состояния задачи; безопасен для параллельных Run.
type Executor[C any] struct {
	cfg       Config
	tasks     TaskStore
	raw       RawStore
	refresher Refresher
	adapter   Adapter[C]
	logger    *slog.Logger
	now       func() time.Time
	jitter    func(time.Duration) time.Duration // [0; d)
}

func New[C any](cfg Config, tasks TaskStore, raw RawStore, refresher Refresher, adapter Adapter[C], logger *slog.Logger,
	now func() time.Time, jitter func(time.Duration) time.Duration) *Executor[C] {
	return &Executor[C]{cfg: cfg, tasks: tasks, raw: raw, refresher: refresher, adapter: adapter, logger: logger, now: now, jitter: jitter}
}

var (
	// ErrNoCredentials — у кабинета нет ключа для ресурса.
	ErrNoCredentials = errors.New("executor: no seller credentials")
	// ErrResourceNotEnabled — ресурс снят с кабинета в снимке Symfony.
	ErrResourceNotEnabled = errors.New("executor: resource is not enabled for cabinet")
	// ErrUnknownResource — задача ресурса, которого адаптер не знает.
	ErrUnknownResource = errors.New("executor: unknown resource type")
	// ErrBadPeriod — period_key не той формы.
	ErrBadPeriod = errors.New("executor: bad period key")
)

// errLeaseLost — внутренний сигнал: продление не удалось, задачу ведёт другой воркер.
var errLeaseLost = errors.New("executor: lease lost")

// Result — итог для диспетчера и логов.
type Result struct {
	Outcome  string
	Pages    int
	Revision int
}

// Run — состояние одной попытки.
type Run struct {
	Task     store.Task
	Log      *slog.Logger
	Hashes   []string
	Cursor   string
	NextPage int
	Pages    int
	Reused   int // страниц без записи в S3: то же содержимое уже сохранено

	tasks    TaskStore
	raw      RawStore
	provider string
	now      func() time.Time
}

// Run выполняет задачу. traceID — сквозной идентификатор для логов.
func (e *Executor[C]) Run(ctx context.Context, task store.Task, cabinet C, traceID string) Result {
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

	run := &Run{Task: task, Log: log, Hashes: append([]string(nil), task.PageHashes...), Cursor: task.Cursor, NextPage: task.NextPageNo,
		tasks: e.tasks, raw: e.raw, provider: e.cfg.Provider, now: e.now}
	fetchErr := e.adapter.Fetch(taskCtx, run, cabinet)

	// Lease продлевается и во время побочных действий исхода (пауза кабинета,
	// стоп ресурса); у них свой ограниченный контекст.
	out := e.outcome(ctx, taskCtx, task, run, fetchErr, log)
	stopLease()
	result := Result{Outcome: out.Label, Pages: run.Pages}

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
	if l, ok := e.adapter.LogLevel(out); ok {
		level = l
	} else {
		switch out.Status {
		case store.StatusFailedRetryable:
			level = slog.LevelWarn
		case store.StatusFailedPermanent:
			level = slog.LevelError
		}
	}
	if result.Outcome == "lease_lost" {
		level = slog.LevelWarn
	}
	attrs := []any{slog.String("stage", "fetch"), slog.String("outcome", result.Outcome), slog.String("error_class", out.ErrorClass),
		slog.Int("pages", run.Pages), slog.Int("pages_reused", run.Reused), slog.Int64("duration_ms", e.now().Sub(started).Milliseconds())}
	if fetchErr != nil {
		attrs = append(attrs, slog.String("error", fetchErr.Error()))
	}
	log.Log(context.WithoutCancel(ctx), level, "fetch task finished", attrs...)
	return result
}

// keepLease продлевает lease раз в треть срока; потеря — отмена задачи.
// У продления свой контекст: остановка прерывает и зависший вызов к БД,
// каждый вызов ограничен третью срока lease.
func (e *Executor[C]) keepLease(ctx context.Context, cancel context.CancelCauseFunc, task store.Task, log *slog.Logger) func() {
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

// Single — ресурс одной страницей. Страница, сохранённая прежней попыткой
// (упала запись итога или процесс), не загружается повторно.
func (r *Run) Single(ctx context.Context, fetch func() (Page, error)) error {
	if len(r.Hashes) > 0 {
		return nil
	}
	r.Cursor, r.NextPage = "", 1
	page, err := fetch()
	if err != nil {
		return err
	}
	page.No = 1
	return r.Store(ctx, page)
}

// storeError — сбой S3 или журнала при сохранении страницы.
type storeError struct{ err error }

func (e *storeError) Error() string { return "executor: store page: " + e.err.Error() }
func (e *storeError) Unwrap() error { return e.err }

// StoreError — сбой S3 или журнала в коде адаптера (чтение сохранённых
// страниц, своя запись): повтор задачи с курсором.
func StoreError(err error) error { return &storeError{err} }

// Store: S3 → журнал (страница, курсор, хеш) — до запроса следующей страницы.
// Страница с тем же содержимым, уже сохранённая для этой задачи, в S3 не
// пишется: журнал попытки ссылается на существующий объект.
func (r *Run) Store(ctx context.Context, p Page) error {
	obj := store.RawObject{PageNo: p.No, SHA256: SHA256Hex(p.Body), SizeBytes: int64(len(p.Body)), ContentType: p.ContentType}

	existing, found, err := r.tasks.FindObject(ctx, r.Task.TaskKey, obj.SHA256)
	if err != nil {
		return &storeError{err}
	}
	if found {
		obj.StorageURI, obj.StoredBytes = existing.StorageURI, existing.StoredBytes
		r.Reused++
	} else {
		stored, err := r.raw.Put(ctx, rawstore.Object{
			Provider: r.provider, ConnectionRef: r.Task.ConnectionRef, ResourceType: r.Task.ResourceType,
			PeriodKey: r.Task.PeriodKey, AttemptID: r.Task.AttemptID, PageNo: p.No, FetchedAt: r.now(),
			ContentType: p.ContentType, Body: p.Body,
		})
		if err != nil {
			return &storeError{err}
		}
		obj.StorageURI, obj.StoredBytes = stored.URI, stored.StoredBytes
	}
	err = r.tasks.RecordPage(ctx, r.Task.TaskKey, r.Task.AttemptID, obj, p.Cursor)
	if errors.Is(err, store.ErrLeaseLost) {
		return err
	}
	if err != nil {
		return &storeError{err}
	}
	r.Hashes = append(r.Hashes, obj.SHA256)
	r.Cursor, r.NextPage = p.Cursor, p.No+1
	r.Pages++
	return nil
}

// ContentHash — sha256 упорядоченного списка хешей страниц загрузки.
func ContentHash(hashes []string) string {
	sum := sha256.Sum256([]byte(strings.Join(hashes, "\n")))
	return hex.EncodeToString(sum[:])
}

// SHA256Hex — sha256 байтов в hex.
func SHA256Hex(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// ParseMonth — period_key месяца YYYY-MM.
func ParseMonth(periodKey string) (int, int, error) {
	t, err := time.Parse("2006-01", periodKey)
	if err != nil {
		return 0, 0, fmt.Errorf("%w: %q", ErrBadPeriod, periodKey)
	}
	return t.Year(), int(t.Month()), nil
}
