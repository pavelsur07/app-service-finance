package fetch

import (
	"context"
	"errors"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

// Classification — ошибка попытки для Adapter.Classify и помощники исхода.
type Classification struct {
	// Side — контекст побочных записей: переживает отмену задачи, ограничен FinishTimeout.
	Side context.Context
	Task store.Task
	Run  *Run
	Err  error
	Now  time.Time
	Log  *slog.Logger

	cfg       Config
	tasks     TaskStore
	refresher Refresher
	jitter    func(time.Duration) time.Duration
}

// Retryable — failed_retryable с backoff base·2^(n−1) ≤ max и jitter.
func (c *Classification) Retryable(class string, keepCursor bool) store.Outcome {
	delay := c.cfg.BackoffBase
	for i := 1; i < c.Task.Attempts && delay < c.cfg.BackoffMax; i++ {
		delay *= 2
	}
	delay = min(delay, c.cfg.BackoffMax)
	delay += c.jitter(delay / 4)
	return store.Outcome{Status: store.StatusFailedRetryable, Label: class, ErrorClass: class, KeepCursor: keepCursor, NextRunAt: c.Now.Add(delay)}
}

// NotReadyDelay — пауза not_ready ресурса задачи (по умолчанию час).
func (c *Classification) NotReadyDelay() time.Duration {
	if delay, ok := c.cfg.NotReadyDelay[c.Task.ResourceType]; ok {
		return delay
	}
	return time.Hour
}

// Config — тайминги исполнителя (паузы auth, ожидания).
func (c *Classification) Config() Config { return c.cfg }

// ForceRefresh — обновить снимок кабинетов после отказа ключа.
func (c *Classification) ForceRefresh() {
	_, err := c.refresher.ForceRefresh(c.Side)
	c.SideEffect("refresh connections", err)
}

// SideEffect — сбой побочного действия исхода: WARNING, исход не меняется.
func (c *Classification) SideEffect(what string, err error) {
	if err != nil {
		c.Log.Warn("fetch task side effect failed", slog.String("stage", "fetch"), slog.String("action", what), slog.String("error", err.Error()))
	}
}

// outcome сопоставляет результат загрузки строке таблицы исходов.
// Побочные действия (пауза кабинета, стоп ресурса, обновление кабинетов)
// выполняются здесь же — до записи итога задачи.
func (e *Executor[C]) outcome(parent, taskCtx context.Context, task store.Task, run *Run, err error, log *slog.Logger) store.Outcome {
	now := e.now()
	// Побочные записи переживают отмену задачи, но ограничены по времени:
	// зависшее соединение с БД не задерживает shutdown и не переживает lease.
	side, cancelSide := context.WithTimeout(context.WithoutCancel(parent), e.cfg.FinishTimeout)
	defer cancelSide()
	c := &Classification{Side: side, Task: task, Run: run, Err: err, Now: now, Log: log,
		cfg: e.cfg, tasks: e.tasks, refresher: e.refresher, jitter: e.jitter}

	if err == nil {
		label := "done"
		hash := ContentHash(run.Hashes)
		if hash == task.ContentHash {
			label = "unchanged"
		}
		// Успех с основным ключом сбрасывает счётчик отказов кабинета, даже если
		// эта задача сама 401 не получала (no-op, когда сбрасывать нечего).
		if e.adapter.ResumesConnection(task.ResourceType) {
			c.SideEffect("resume connection", e.tasks.ResumeConnection(side, task.ConnectionRef))
		}
		return store.Outcome{Status: store.StatusDone, Label: label, ContentHash: hash}
	}

	if errors.Is(context.Cause(taskCtx), errLeaseLost) || errors.Is(err, store.ErrLeaseLost) {
		return store.Outcome{Status: store.StatusPending, Label: "lease_lost", KeepCursor: true, NextRunAt: now}
	}
	if parent.Err() != nil && errors.Is(err, parent.Err()) {
		// Graceful shutdown: задача возвращается в очередь с курсором.
		return store.Outcome{Status: store.StatusPending, Label: "released", KeepCursor: true, NextRunAt: now}
	}
	if errors.Is(err, ErrNoCredentials) {
		return store.Outcome{Status: store.StatusPending, Label: "no_credentials", ErrorClass: "no_credentials", KeepCursor: true, NextRunAt: now.Add(e.cfg.NoCredentialsIn)}
	}
	if errors.Is(err, ErrResourceNotEnabled) {
		// Задача ждёт: ресурс могут включить снова, курсор сохраняется.
		return store.Outcome{Status: store.StatusPending, Label: "resource_disabled", ErrorClass: "resource_disabled", KeepCursor: true, NextRunAt: now.Add(e.cfg.NoCredentialsIn)}
	}
	if out, ok := e.adapter.Classify(c); ok {
		return out
	}
	if errors.Is(err, ErrUnknownResource) || errors.Is(err, ErrBadPeriod) {
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "invalid_task", ErrorClass: "invalid_task", NextRunAt: now}
	}
	var se *storeError
	if errors.As(err, &se) {
		return c.Retryable("store", true)
	}

	var apiErr *apierr.APIError
	if !errors.As(err, &apiErr) {
		// Неожиданная ошибка (например, дедлайн без класса): повтор с backoff.
		return c.Retryable("unexpected", true)
	}

	switch apiErr.Class {
	case apierr.ClassRateLimit:
		pause := apiErr.RetryAfter
		if pause <= 0 {
			pause = e.cfg.RateLimitPause
		}
		return store.Outcome{Status: store.StatusPending, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now.Add(pause)}

	case apierr.ClassTransient:
		out := c.Retryable(string(apiErr.Class), true)
		out.HTTPStatus = apiErr.Status
		return out

	case apierr.ClassAuth:
		// Счётчик подряд — по кабинету: первая пауза короткая (снимок с новым
		// ключом успевает обновиться), со второго отказа — длинная + ERROR.
		failures, err := e.tasks.RecordAuthFailure(side, task.ConnectionRef, now, func(n int) time.Duration {
			if n >= 2 {
				return e.cfg.AuthSuspend
			}
			return e.cfg.AuthRetryPause
		})
		if err != nil {
			// Пауза не записана — кабинет не должен сразу уйти в новый круг 401.
			c.SideEffect("record auth failure", err)
			out := c.Retryable(string(apiErr.Class), true)
			out.HTTPStatus = apiErr.Status
			return out
		}
		c.ForceRefresh()
		if failures >= 2 {
			log.Error(e.cfg.Provider+" rejected cabinet keys again, cabinet suspended",
				slog.String("stage", "fetch"), slog.Int("http_status", apiErr.Status), slog.Int("auth_failures", failures),
				slog.Time("suspended_until", now.Add(e.cfg.AuthSuspend)))
		}
		return store.Outcome{Status: store.StatusPending, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now}

	case apierr.ClassObsoleteEndpoint:
		if err := e.tasks.StopResource(side, task.ResourceType, apiErr.Endpoint); err != nil {
			// Стоп не записан — задача повторится и попробует остановить ресурс снова.
			c.SideEffect("stop resource", err)
			out := c.Retryable(string(apiErr.Class), false)
			out.HTTPStatus = apiErr.Status
			return out
		}
		log.Error(e.cfg.Provider+" endpoint is obsolete, resource stopped for all cabinets",
			slog.String("stage", "fetch"), slog.String("endpoint", apiErr.Endpoint), slog.Int("code", apiErr.Code),
			slog.String("message", apiErr.Message))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now}

	case apierr.ClassNotReady:
		return store.Outcome{Status: store.StatusNotReady, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now.Add(c.NotReadyDelay())}

	default: // invalid_request, invalid_response
		return store.Outcome{Status: store.StatusFailedPermanent, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now}
	}
}
