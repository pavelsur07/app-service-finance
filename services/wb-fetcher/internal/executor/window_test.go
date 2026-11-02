package executor

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

type countingLimiter struct{ waits int }

func (c *countingLimiter) Wait(context.Context, string, string) error { c.waits++; return nil }
func (c *countingLimiter) Observe(string, string, *apierr.APIError)   {}

func TestWindowBlocksMinutesTenAndTwenty(t *testing.T) {
	for _, tc := range []struct {
		now     time.Time
		blocked bool
		until   time.Time
	}{
		{time.Date(2026, 11, 2, 0, 10, 0, 0, time.UTC), true, time.Date(2026, 11, 2, 0, 11, 0, 0, time.UTC)},
		{time.Date(2026, 11, 2, 7, 20, 59, 0, time.UTC), true, time.Date(2026, 11, 2, 7, 21, 0, 0, time.UTC)},
		{time.Date(2026, 11, 2, 7, 9, 59, 0, time.UTC), false, time.Time{}},
		{time.Date(2026, 11, 2, 7, 11, 0, 0, time.UTC), false, time.Time{}},
		{time.Date(2026, 11, 2, 7, 30, 0, 0, time.UTC), false, time.Time{}},
	} {
		inner := &countingLimiter{}
		lim := NewWindowLimiter(inner, DefaultBlockedMinutes, func() time.Time { return tc.now })

		err := lim.Wait(context.Background(), "wb-1", "/x")

		assert.Equal(t, 1, inner.waits, "токен лимитера берётся всегда: окно проверяется после ожидания")
		if !tc.blocked {
			assert.NoError(t, err, tc.now)
			continue
		}
		var we *WindowError
		require.True(t, errors.As(err, &we), tc.now)
		assert.Equal(t, tc.until, we.Until)
	}
}

func TestMoscowMinutesEqualUTCMinutes(t *testing.T) {
	msk, err := time.LoadLocation("Europe/Moscow")
	require.NoError(t, err)
	lim := NewWindowLimiter(&countingLimiter{}, DefaultBlockedMinutes, func() time.Time { return time.Date(2026, 11, 2, 3, 10, 30, 0, msk) })

	assert.Error(t, lim.Wait(context.Background(), "wb-1", "/x"), "03:10 МСК — cron Symfony")
}
