package executor

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

// QuotaUnavailableDelay — повтор задачи, когда квота Symfony не ответила.
const QuotaUnavailableDelay = time.Minute

// Quota — общая с Symfony квота sales-reports (internal/quota.Client).
type Quota interface {
	Reserve(ctx context.Context, connectionRef string) (time.Duration, error)
	RateLimited(ctx context.Context, connectionRef string, pause time.Duration) error
}

// QuotaWaitError — слот кабинета занят импортом Symfony (или квота не
// ответила): задача ждёт до Until, запрос к WB не отправлен.
type QuotaWaitError struct {
	Until       time.Time
	Unavailable bool
	Err         error
}

func (e *QuotaWaitError) Error() string {
	if e.Unavailable {
		return fmt.Sprintf("executor: symfony quota unavailable (%v), retry at %s", e.Err, e.Until.Format(time.RFC3339))
	}
	return fmt.Sprintf("executor: symfony quota busy, retry at %s", e.Until.Format(time.RFC3339))
}

func (e *QuotaWaitError) Unwrap() error { return e.Err }

// sharedLimiter — свой темп кабинета (fetchkit/ratelimit), затем слот в
// лимитере Symfony: лимит WB 1 запрос/мин на продавца общий с импортом
// Symfony. Без ответа квоты запрос не идёт.
type sharedLimiter struct {
	inner wb.Limiter
	quota Quota
	now   func() time.Time
	log   *slog.Logger
}

func NewSharedLimiter(inner wb.Limiter, quota Quota, now func() time.Time, log *slog.Logger) wb.Limiter {
	return &sharedLimiter{inner: inner, quota: quota, now: now, log: log}
}

func (s *sharedLimiter) Wait(ctx context.Context, ref, endpoint string) error {
	if err := s.inner.Wait(ctx, ref, endpoint); err != nil {
		return err
	}
	wait, err := s.quota.Reserve(ctx, ref)
	if err != nil {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		return &QuotaWaitError{Until: s.now().Add(QuotaUnavailableDelay), Unavailable: true, Err: err}
	}
	if wait > 0 {
		return &QuotaWaitError{Until: s.now().Add(wait)}
	}
	return nil
}

// Observe — 429 от WB становится общим cooldown: импорт Symfony тоже ждёт.
func (s *sharedLimiter) Observe(ref, endpoint string, apiErr *apierr.APIError) {
	s.inner.Observe(ref, endpoint, apiErr)
	if apiErr == nil || apiErr.Class != apierr.ClassRateLimit {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := s.quota.RateLimited(ctx, ref, apiErr.RetryAfter); err != nil {
		s.log.Warn("wb 429 not shared with symfony quota", slog.String("stage", "ratelimit"),
			slog.String("error_class", "quota_unavailable"), slog.String("connection_ref", ref), slog.String("error", err.Error()))
	}
}
