package app

import (
	"context"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/ratelimit"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

func TestOneReportRequestPerIntervalPerCabinet(t *testing.T) {
	interval := 150 * time.Millisecond
	reg := ratelimit.New(Limits(interval), time.Now, sleepContext, nil)
	ctx := context.Background()

	start := time.Now()
	require.NoError(t, reg.Wait(ctx, "wb-1", wb.EndpointSalesReport))
	assert.Less(t, time.Since(start), interval/2, "первый запрос — сразу")
	require.NoError(t, reg.Wait(ctx, "wb-2", wb.EndpointSalesReport))
	assert.Less(t, time.Since(start), interval/2, "другой кабинет — свой лимит")
	require.NoError(t, reg.Wait(ctx, "wb-1", wb.EndpointSalesReport))
	assert.GreaterOrEqual(t, time.Since(start), interval*9/10, "второй запрос кабинета — через интервал")
}

func TestRateLimitSlowsCabinetDownButNotBelowFloor(t *testing.T) {
	interval := 70 * time.Second
	reg := ratelimit.New(Limits(interval), time.Now, sleepContext, nil)
	ceiling := 1 / interval.Seconds()

	reg.Wait(context.Background(), "wb-1", wb.EndpointSalesReport) //nolint:errcheck // токен без ожидания
	for range 5 {
		reg.Observe("wb-1", wb.EndpointSalesReport, &apierr.APIError{Class: apierr.ClassRateLimit, RetryAfter: time.Millisecond})
	}

	assert.InDelta(t, ceiling/4, reg.Rate("wb-1", wb.EndpointSalesReport), 1e-9)
}
