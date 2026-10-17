package executor

import (
	"context"
	"errors"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

// outcome сопоставляет результат загрузки строке таблицы исходов (stage-9.md).
// Побочные действия (пауза кабинета, стоп ресурса, обновление кабинетов)
// выполняются здесь же — до записи итога задачи.
func (e *Executor) outcome(parent, taskCtx context.Context, task store.Task, run *taskRun, err error, log *slog.Logger) store.Outcome {
	now := e.now()
	side := context.WithoutCancel(parent)

	if err == nil {
		label := "done"
		hash := contentHash(run.hashes)
		if hash == task.ContentHash {
			label = "unchanged"
		}
		if task.LastErrorClass == string(ozon.ClassAuth) {
			e.sideEffect(log, "resume connection", e.tasks.ResumeConnection(side, task.ConnectionRef))
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
	if errors.Is(err, errNoCredentials) {
		return store.Outcome{Status: store.StatusPending, Label: "no_credentials", ErrorClass: "no_credentials", KeepCursor: true, NextRunAt: now.Add(e.cfg.NoCredentialsIn)}
	}
	if errors.Is(err, errUnknownResource) || errors.Is(err, errBadPeriod) {
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "invalid_task", ErrorClass: "invalid_task", NextRunAt: now}
	}
	var se *storeError
	if errors.As(err, &se) {
		return e.retryable(task, "store", now, true)
	}

	var apiErr *ozon.APIError
	if !errors.As(err, &apiErr) {
		// Неожиданная ошибка (например, дедлайн без класса): повтор с backoff.
		return e.retryable(task, "unexpected", now, true)
	}

	switch apiErr.Class {
	case ozon.ClassRateLimit:
		pause := apiErr.RetryAfter
		if pause <= 0 {
			pause = e.cfg.RateLimitPause
		}
		return store.Outcome{Status: store.StatusPending, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now.Add(pause)}

	case ozon.ClassTransient:
		out := e.retryable(task, string(apiErr.Class), now, true)
		out.HTTPStatus = apiErr.Status
		return out

	case ozon.ClassAuth:
		_, refreshErr := e.refresher.ForceRefresh(side)
		e.sideEffect(log, "refresh connections", refreshErr)
		if task.LastErrorClass == string(ozon.ClassAuth) {
			until := now.Add(e.cfg.AuthSuspend)
			e.sideEffect(log, "suspend connection", e.tasks.SuspendConnection(side, task.ConnectionRef, string(apiErr.Class), until))
			log.Error("ozon rejected cabinet keys twice, cabinet suspended",
				slog.String("stage", "fetch"), slog.Int("http_status", apiErr.Status), slog.Time("suspended_until", until))
		}
		return store.Outcome{Status: store.StatusPending, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now}

	case ozon.ClassObsoleteEndpoint:
		e.sideEffect(log, "stop resource", e.tasks.StopResource(side, task.ResourceType, apiErr.Endpoint))
		log.Error("ozon endpoint is obsolete, resource stopped for all cabinets",
			slog.String("stage", "fetch"), slog.String("endpoint", apiErr.Endpoint), slog.Int("code", apiErr.Code))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now}

	case ozon.ClassNotReady:
		delay, ok := e.cfg.NotReadyDelay[task.ResourceType]
		if !ok {
			delay = time.Hour
		}
		return store.Outcome{Status: store.StatusNotReady, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now.Add(delay)}

	default: // invalid_request, invalid_response
		return store.Outcome{Status: store.StatusFailedPermanent, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now}
	}
}

// retryable — failed_retryable с backoff base·2^(n−1) ≤ max и jitter.
func (e *Executor) retryable(task store.Task, class string, now time.Time, keepCursor bool) store.Outcome {
	delay := e.cfg.BackoffBase
	for i := 1; i < task.Attempts && delay < e.cfg.BackoffMax; i++ {
		delay *= 2
	}
	delay = min(delay, e.cfg.BackoffMax)
	delay += e.jitter(delay / 4)
	return store.Outcome{Status: store.StatusFailedRetryable, Label: class, ErrorClass: class, KeepCursor: keepCursor, NextRunAt: now.Add(delay)}
}

func (e *Executor) sideEffect(log *slog.Logger, what string, err error) {
	if err != nil {
		log.Warn("fetch task side effect failed", slog.String("stage", "fetch"), slog.String("action", what), slog.String("error", err.Error()))
	}
}
