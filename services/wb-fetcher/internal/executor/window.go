package executor

import (
	"context"
	"fmt"
	"slices"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

// DefaultBlockedMinutes — минуты часа, в которые к finance-api ходит Symfony
// (cron 03:10 и orchestrate в :20): сервис их пропускает, чтобы на время
// параллельной работы не съедать общий лимит токена (решение 3 плана).
var DefaultBlockedMinutes = []int{10, 20}

// WindowError — запрос попал в минуту Symfony; задача ждёт до Until.
type WindowError struct{ Until time.Time }

func (e *WindowError) Error() string {
	return fmt.Sprintf("executor: symfony window, retry at %s", e.Until.Format(time.RFC3339))
}

// windowLimiter — лимитер с окном: токен берётся как обычно, затем запрос в
// минуту окна отменяется. Проверка после ожидания — ожидание паузы 70 с не
// проскакивает в окно.
type windowLimiter struct {
	inner   wb.Limiter
	minutes []int
	now     func() time.Time
}

func NewWindowLimiter(inner wb.Limiter, minutes []int, now func() time.Time) wb.Limiter {
	return &windowLimiter{inner: inner, minutes: minutes, now: now}
}

func (w *windowLimiter) Wait(ctx context.Context, ref, endpoint string) error {
	if err := w.inner.Wait(ctx, ref, endpoint); err != nil {
		return err
	}
	now := w.now()
	if slices.Contains(w.minutes, now.Minute()) {
		return &WindowError{Until: now.Truncate(time.Minute).Add(time.Minute)}
	}
	return nil
}

func (w *windowLimiter) Observe(ref, endpoint string, apiErr *apierr.APIError) {
	w.inner.Observe(ref, endpoint, apiErr)
}
