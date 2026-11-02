package ozonstore

import (
	"context"
	"fmt"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store/storetest"
)

// Интеграционные тесты: TEST_DB_DSN — пустая БД; без переменной — пропуск.
func testTasks(t *testing.T) *Tasks {
	t.Helper()
	dsn := storetest.DSN(t, "ozonstore")
	ctx := context.Background()
	require.NoError(t, Migrate(ctx, dsn))
	pool, err := store.Open(ctx, dsn, store.PoolSize(4))
	require.NoError(t, err)
	t.Cleanup(pool.Close)
	return NewTasks(pool)
}

var t0 = time.Date(2026, 10, 17, 9, 0, 0, 0, time.UTC)

func spec(ref, resource, period string, priority int) store.TaskSpec {
	return store.TaskSpec{TaskKey: store.TaskKey{ConnectionRef: ref, ResourceType: resource, PeriodKey: period}, Priority: priority, RunAt: t0}
}

var attemptSeq atomic.Int64

func newAttemptID() string {
	return fmt.Sprintf("00000000-0000-7000-8000-%012d", attemptSeq.Add(1))
}

func claim(t *testing.T, tasks *Tasks, refs []string, now time.Time) (store.Task, error) {
	t.Helper()
	return tasks.ClaimNext(context.Background(), refs, "worker-1", newAttemptID(), "trace-1", now, time.Minute)
}

func TestMigrationLowersAccrualPostingsPriority(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	accrual := spec("a", "ozon.finance.accrual-postings", "2026-10-20", 5)
	orders := spec("a", "ozon.orders.fbo", "2026-10-20", 5)
	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{accrual, orders}))

	up, err := migrationsFS.ReadFile("migrations/00005_accrual_postings_priority.sql")
	require.NoError(t, err)
	body, _, _ := strings.Cut(string(up), "-- +goose Down")
	_, err = tasks.Pool().Exec(ctx, body)
	require.NoError(t, err)

	priority := func(s store.TaskSpec) int {
		var p int
		require.NoError(t, tasks.Pool().QueryRow(ctx, `SELECT priority FROM fetch_task WHERE connection_ref=$1 AND resource_type=$2 AND period_key=$3`,
			s.ConnectionRef, s.ResourceType, s.PeriodKey).Scan(&p))
		return p
	}
	assert.Equal(t, 1, priority(accrual))
	assert.Equal(t, 5, priority(orders), "другие ресурсы не трогаются")
}

func TestAdsSlotMatchesMigrationIndex(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{
		spec("a", "ozon.finance.accrual-by-day", "2026-10-20", 5),
		spec("a", "ozon.ads.expense", "2026-10-20", 5),
		spec("a", "ozon.ads.daily", "2026-10-20", 5),
	}))

	first, err := claim(t, tasks, []string{"a"}, t0)
	require.NoError(t, err)
	second, err := claim(t, tasks, []string{"a"}, t0)
	require.NoError(t, err, "реклама и seller — параллельно (индекс 00006 пропускает)")
	assert.NotEqual(t, strings.HasPrefix(first.ResourceType, SeparateSlotPrefix), strings.HasPrefix(second.ResourceType, SeparateSlotPrefix))
	_, err = claim(t, tasks, []string{"a"}, t0)
	assert.ErrorIs(t, err, store.ErrNoTask)
}

func TestAdsReportInFlight(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	daily := spec("a", "ozon.ads.statistics", "2026-10-21", 5)
	tail := spec("a", "ozon.ads.statistics.tail", "2026-10-22", 5)
	other := spec("b", "ozon.ads.statistics", "2026-10-21", 5)
	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{daily, tail, other}))

	busy, err := tasks.AdsReportInFlight(ctx, tail.TaskKey)
	require.NoError(t, err)
	assert.False(t, busy)

	got, err := claim(t, tasks, []string{"a"}, t0)
	require.NoError(t, err)
	require.NoError(t, tasks.SaveCursor(ctx, got.TaskKey, got.AttemptID, "fp:0:uuid-1:1700000000:0"))

	otherKey := tail.TaskKey
	if got.TaskKey == tail.TaskKey {
		otherKey = daily.TaskKey
	}
	busy, err = tasks.AdsReportInFlight(ctx, otherKey)
	require.NoError(t, err)
	assert.True(t, busy, "отчёт другой задачи кабинета заказан")
	busy, err = tasks.AdsReportInFlight(ctx, got.TaskKey)
	require.NoError(t, err)
	assert.False(t, busy, "своя задача не мешает")
	busy, err = tasks.AdsReportInFlight(ctx, other.TaskKey)
	require.NoError(t, err)
	assert.False(t, busy, "другой кабинет не мешает")
}

// Схема после миграций ozon-fetcher и схема ядра fetchkit совпадают по
// таблицам и колонкам: код ядра работает на обеих. Индексы различаются только
// слотом running-задач (у ozon-fetcher — отдельный слот рекламы).
func TestSchemaMatchesFetchkitCore(t *testing.T) {
	ctx := context.Background()
	ozonDSN := storetest.DSN(t, "schema_ozon")
	coreDSN := storetest.DSN(t, "schema_core")
	require.NoError(t, Migrate(ctx, ozonDSN))
	require.NoError(t, store.Migrate(ctx, coreDSN, store.CoreMigrations(), "migrations"))

	columns := func(dsn, schema string) []string {
		pool, err := store.Open(ctx, dsn, store.MinConns)
		require.NoError(t, err)
		defer pool.Close()
		rows, err := pool.Query(ctx, `
			SELECT table_name || '.' || column_name || ' ' || data_type || ' ' || is_nullable || ' ' || COALESCE(column_default, '')
			FROM information_schema.columns
			WHERE table_schema = $1 AND table_name <> 'goose_db_version'
			ORDER BY table_name, ordinal_position`, schema)
		require.NoError(t, err)
		defer rows.Close()
		var out []string
		for rows.Next() {
			var c string
			require.NoError(t, rows.Scan(&c))
			out = append(out, c)
		}
		require.NoError(t, rows.Err())
		return out
	}
	indexes := func(dsn, schema string) []string {
		pool, err := store.Open(ctx, dsn, store.MinConns)
		require.NoError(t, err)
		defer pool.Close()
		rows, err := pool.Query(ctx, `
			SELECT replace(indexdef, $1 || '.', '') FROM pg_indexes
			WHERE schemaname = $1 AND tablename <> 'goose_db_version' AND indexname NOT LIKE 'fetch_task_one_running_per_connection%'
			ORDER BY indexname`, schema)
		require.NoError(t, err)
		defer rows.Close()
		var out []string
		for rows.Next() {
			var c string
			require.NoError(t, rows.Scan(&c))
			out = append(out, c)
		}
		require.NoError(t, rows.Err())
		return out
	}

	ozonColumns := columns(ozonDSN, "test_schema_ozon")
	require.NotEmpty(t, ozonColumns)
	assert.Equal(t, ozonColumns, columns(coreDSN, "test_schema_core"))
	assert.Equal(t, indexes(ozonDSN, "test_schema_ozon"), indexes(coreDSN, "test_schema_core"))
}
