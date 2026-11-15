package app

import (
	"context"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store/storetest"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/executor"
)

// Миграции сервиса поверх ядра повторяются без ошибок; реклама кабинета идёт своим
// слотом — отчёт о продажах того же кабинета выдаётся параллельно, второй отчёт — нет.
func TestAdsRunInSeparateSlot(t *testing.T) {
	dsn := storetest.DSN(t, "wb_slot")
	ctx := context.Background()
	require.NoError(t, Migrate(ctx, dsn))
	require.NoError(t, Migrate(ctx, dsn), "повторный старт")
	pool, err := store.Open(ctx, dsn, store.PoolSize(2))
	require.NoError(t, err)
	defer pool.Close()
	_, err = pool.Exec(ctx, `TRUNCATE raw_object, fetch_attempt, fetch_task, connection_state, connection_limit, resource_stop`)
	require.NoError(t, err)
	tasks := NewTasks(pool)
	now := time.Date(2026, 11, 15, 9, 0, 0, 0, time.UTC)
	key := func(resource, day string) store.TaskKey {
		return store.TaskKey{ConnectionRef: "wb-1", ResourceType: resource, PeriodKey: day}
	}
	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{
		{TaskKey: key(executor.ResourceDaily, "2026-11-14"), RunAt: now, Priority: 10},
		{TaskKey: key(executor.ResourceDaily, "2026-11-13"), RunAt: now, Priority: 9},
		{TaskKey: key(executor.ResourceAds, "2026-11-14"), RunAt: now, Priority: 1},
	}))

	claim := func(attempt string) (store.Task, error) {
		return tasks.ClaimNext(ctx, []string{"wb-1"}, "w", attempt, "trace", now, time.Minute)
	}
	first, err := claim("00000000-0000-7000-8000-000000000001")
	require.NoError(t, err)
	second, err := claim("00000000-0000-7000-8000-000000000002")
	require.NoError(t, err)
	_, err = claim("00000000-0000-7000-8000-000000000003")

	assert.Equal(t, executor.ResourceDaily, first.ResourceType)
	assert.Equal(t, executor.ResourceAds, second.ResourceType, "реклама не ждёт отчёт")
	assert.ErrorIs(t, err, store.ErrNoTask, "второй отчёт кабинета ждёт слот")
}
