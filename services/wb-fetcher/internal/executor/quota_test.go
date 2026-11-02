package executor

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

type countingLimiter struct {
	waits    int
	observed []*apierr.APIError
}

func (c *countingLimiter) Wait(context.Context, string, string) error { c.waits++; return nil }
func (c *countingLimiter) Observe(_, _ string, e *apierr.APIError) {
	c.observed = append(c.observed, e)
}

type fakeQuota struct {
	wait    time.Duration
	err     error
	reserve []string
	limited []time.Duration
}

func (f *fakeQuota) Reserve(_ context.Context, ref string) (time.Duration, error) {
	f.reserve = append(f.reserve, ref)
	return f.wait, f.err
}

func (f *fakeQuota) RateLimited(_ context.Context, _ string, pause time.Duration) error {
	f.limited = append(f.limited, pause)
	return f.err
}

var quotaNow = time.Date(2026, 11, 2, 7, 10, 0, 0, time.UTC)

func newTestLimiter(q *fakeQuota) (*countingLimiter, *bytes.Buffer, func(context.Context) error) {
	inner, logs := &countingLimiter{}, &bytes.Buffer{}
	lim := NewSharedLimiter(inner, q, func() time.Time { return quotaNow }, slog.New(slog.NewJSONHandler(logs, nil)))
	return inner, logs, func(ctx context.Context) error { return lim.Wait(ctx, "wb-1", "/x") }
}

func TestSharedLimiterTakesLocalThenSymfonySlot(t *testing.T) {
	q := &fakeQuota{}
	inner, _, wait := newTestLimiter(q)

	require.NoError(t, wait(context.Background()), "минута :10 больше не запретна — решает квота Symfony")

	assert.Equal(t, 1, inner.waits)
	assert.Equal(t, []string{"wb-1"}, q.reserve)
}

func TestSharedLimiterDefersWhenSymfonyHoldsSlot(t *testing.T) {
	_, _, wait := newTestLimiter(&fakeQuota{wait: 40 * time.Second})

	var qe *QuotaWaitError
	require.True(t, errors.As(wait(context.Background()), &qe))
	assert.Equal(t, quotaNow.Add(40*time.Second), qe.Until)
	assert.False(t, qe.Unavailable)
}

// Symfony недоступна — запрос к WB не идёт: без общей квоты сервис не знает,
// свободен ли лимит продавца.
func TestSharedLimiterFailsClosedWhenSymfonyUnavailable(t *testing.T) {
	_, _, wait := newTestLimiter(&fakeQuota{err: errors.New("quota: HTTP 502")})

	var qe *QuotaWaitError
	require.True(t, errors.As(wait(context.Background()), &qe))
	assert.Equal(t, quotaNow.Add(QuotaUnavailableDelay), qe.Until)
	assert.True(t, qe.Unavailable)
}

func TestSharedLimiterReturnsCancellation(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	_, _, wait := newTestLimiter(&fakeQuota{err: context.Canceled})

	assert.ErrorIs(t, wait(ctx), context.Canceled)
}

func TestSharedLimiterReportsRemote429ToSymfony(t *testing.T) {
	q := &fakeQuota{}
	inner := &countingLimiter{}
	lim := NewSharedLimiter(inner, q, func() time.Time { return quotaNow }, slog.New(slog.DiscardHandler))

	lim.Observe("wb-1", "/x", nil)
	lim.Observe("wb-1", "/x", &apierr.APIError{Class: apierr.ClassTransient})
	lim.Observe("wb-1", "/x", &apierr.APIError{Class: apierr.ClassRateLimit, RetryAfter: 30 * time.Second})

	assert.Len(t, inner.observed, 3, "локальный лимитер видит всё")
	assert.Equal(t, []time.Duration{30 * time.Second}, q.limited, "Symfony — только 429")
}

func TestSharedLimiterLogsFailedReportOf429(t *testing.T) {
	q := &fakeQuota{err: errors.New("quota: HTTP 503")}
	inner, logs := &countingLimiter{}, &bytes.Buffer{}
	lim := NewSharedLimiter(inner, q, func() time.Time { return quotaNow }, slog.New(slog.NewJSONHandler(logs, nil)))

	lim.Observe("wb-1", "/x", &apierr.APIError{Class: apierr.ClassRateLimit, RetryAfter: time.Minute})

	assert.Contains(t, logs.String(), `"level":"WARN"`)
	assert.Contains(t, logs.String(), `"connection_ref":"wb-1"`)
}
