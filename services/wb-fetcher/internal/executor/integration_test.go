package executor

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store/storetest"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

// Сквозной прогон на PostgreSQL (TEST_DB_DSN): миграции ядра, очередь, клиент
// WB против мока; падение посреди пагинации продолжается с курсора без
// дублей; перезагрузка тем же содержимым — unchanged.
func TestDailyReportResumesFromCursorAndDetectsUnchanged(t *testing.T) {
	dsn := storetest.DSN(t, "wb_executor")
	ctx := context.Background()
	require.NoError(t, store.Migrate(ctx, dsn, store.CoreMigrations(), "migrations"))
	pool, err := store.Open(ctx, dsn, store.PoolSize(4))
	require.NoError(t, err)
	defer pool.Close()
	_, err = pool.Exec(ctx, `TRUNCATE raw_object, fetch_attempt, fetch_task, connection_state, connection_limit, resource_stop`)
	require.NoError(t, err)
	tasks := store.NewTasks(pool, store.Options{})

	// Неполная страница (rrdId 30) — не конец: конец отчёта — [] (документация WB).
	pages := map[int64]string{0: `[{"rrdId":10},{"rrdId":20}]`, 20: `[{"rrdId":30}]`, 30: `[]`}
	var cursor20 atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			RrdID int64 `json:"rrdId"`
		}
		b, _ := io.ReadAll(r.Body)
		require.NoError(t, json.Unmarshal(b, &req))
		if req.RrdID == 20 && cursor20.Add(1) == 1 {
			w.WriteHeader(http.StatusBadGateway) // первая попытка падает на странице 2
			return
		}
		_, _ = io.WriteString(w, pages[req.RrdID])
	}))
	defer srv.Close()

	now := time.Date(2026, 11, 2, 9, 0, 0, 0, time.UTC)
	raw := &fakeRaw{}
	cfg := DefaultConfig()
	cfg.PageLimit = 2
	exec := New(cfg, tasks, raw, wb.NewClient(srv.URL, srv.Client(), func() time.Time { return now }), noRefresh{},
		slog.New(slog.NewTextHandler(io.Discard, nil)), func() time.Time { return now }, func(time.Duration) time.Duration { return 0 })
	key := store.TaskKey{ConnectionRef: "wb-int", ResourceType: ResourceDaily, PeriodKey: "2026-11-01"}
	c := connections.Cabinet{Ref: "wb-int", Token: "tok", ResourceTypes: []string{ResourceDaily}}
	claim := func(at time.Time, attempt string) store.Task {
		task, err := tasks.ClaimNext(ctx, []string{"wb-int"}, "w", attempt, "trace", at, time.Minute)
		require.NoError(t, err)
		return task
	}

	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{{TaskKey: key, RunAt: now}}))
	first := exec.Run(ctx, claim(now, "00000000-0000-7000-8000-000000000001"), c, "t1")
	assert.Equal(t, "transient", first.Outcome)
	assert.Equal(t, 1, first.Pages)

	now = now.Add(2 * time.Hour)
	second := claim(now, "00000000-0000-7000-8000-000000000002")
	assert.Equal(t, "20", second.Cursor, "курсор страницы 1 пережил сбой")
	assert.Equal(t, 2, second.NextPageNo)
	res := exec.Run(ctx, second, c, "t2")
	assert.Equal(t, "done", res.Outcome)
	assert.Len(t, raw.keys, 3, "страница 1 не перезаписана; третья — [] конца отчёта")

	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{{TaskKey: key, Refresh: true, RunAt: now}}))
	again := exec.Run(ctx, claim(now, "00000000-0000-7000-8000-000000000003"), c, "t3")
	assert.Equal(t, "unchanged", again.Outcome, "тот же отчёт — ревизия не растёт")
}

// Недельный отчёт на PostgreSQL: пустой ответ до срока — not_ready с повтором
// через час; отчёт появился — done; правка WB задним числом — новая ревизия;
// при одной свободной задаче вчерашний день выдаётся раньше недели.
func TestWeeklyReportNotReadyThenRevisedAndYieldsToYesterday(t *testing.T) {
	dsn := storetest.DSN(t, "wb_executor_weekly")
	ctx := context.Background()
	require.NoError(t, store.Migrate(ctx, dsn, store.CoreMigrations(), "migrations"))
	pool, err := store.Open(ctx, dsn, store.PoolSize(4))
	require.NoError(t, err)
	defer pool.Close()
	_, err = pool.Exec(ctx, `TRUNCATE raw_object, fetch_attempt, fetch_task, connection_state, connection_limit, resource_stop`)
	require.NoError(t, err)
	tasks := store.NewTasks(pool, store.Options{})

	var body atomic.Value
	body.Store(`[]`)
	var seen atomic.Value
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		var req struct {
			RrdID int64 `json:"rrdId"`
		}
		require.NoError(t, json.Unmarshal(b, &req))
		if req.RrdID > 0 {
			_, _ = io.WriteString(w, `[]`) // конец отчёта
			return
		}
		seen.Store(string(b))
		_, _ = io.WriteString(w, body.Load().(string))
	}))
	defer srv.Close()

	now := time.Date(2026, 11, 2, 5, 0, 0, 0, moscow) // понедельник после недели W44
	raw := &fakeRaw{}
	exec := New(DefaultConfig(), tasks, raw, wb.NewClient(srv.URL, srv.Client(), func() time.Time { return now }), noRefresh{},
		slog.New(slog.NewTextHandler(io.Discard, nil)), func() time.Time { return now }, func(time.Duration) time.Duration { return 0 })
	week := store.TaskKey{ConnectionRef: "wb-int", ResourceType: ResourceWeekly, PeriodKey: "2026-W44"}
	c := connections.Cabinet{Ref: "wb-int", Token: "tok", ResourceTypes: []string{ResourceDaily, ResourceWeekly}}
	n := 0
	claim := func(at time.Time) store.Task {
		n++
		task, err := tasks.ClaimNext(ctx, []string{"wb-int"}, "w", fmt.Sprintf("00000000-0000-7000-8000-%012d", 100+n), "trace", at, time.Minute)
		require.NoError(t, err)
		return task
	}

	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{{TaskKey: week, Priority: 7, RunAt: now}}))
	res := exec.Run(ctx, claim(now), c, "t1")
	assert.Equal(t, store.StatusNotReady, res.Outcome)
	assert.JSONEq(t, `{"dateFrom":"2026-10-26","dateTo":"2026-11-01","limit":20000,"rrdId":0,"period":"weekly"}`, seen.Load().(string))
	assert.Empty(t, raw.keys)
	_, err = tasks.ClaimNext(ctx, []string{"wb-int"}, "w", "00000000-0000-7000-8000-000000000199", "trace", now.Add(59*time.Minute), time.Minute)
	assert.ErrorIs(t, err, store.ErrNoTask, "повтор not_ready — через час")

	now = now.Add(time.Hour)
	body.Store(`[{"rrdId":10,"reportId":1}]`)
	assert.Equal(t, "done", exec.Run(ctx, claim(now), c, "t2").Outcome)

	body.Store(`[{"rrdId":10,"reportId":1},{"rrdId":11,"reportId":2}]`) // WB дописал второй отчёт недели
	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{{TaskKey: week, Priority: 7, Refresh: true, RunAt: now}}))
	assert.Equal(t, "done", exec.Run(ctx, claim(now), c, "t3").Outcome, "другое содержимое — новая ревизия, не unchanged")
	var revision int
	require.NoError(t, pool.QueryRow(ctx, `SELECT revision FROM fetch_task WHERE period_key = '2026-W44'`).Scan(&revision))
	assert.Equal(t, 2, revision)

	yesterday := store.TaskKey{ConnectionRef: "wb-int", ResourceType: ResourceDaily, PeriodKey: "2026-11-01"}
	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{
		{TaskKey: week, Priority: 7, Refresh: true, RunAt: now},
		{TaskKey: yesterday, Priority: 10, RunAt: now},
	}))
	assert.Equal(t, yesterday, claim(now).TaskKey, "вчерашний день раньше недели")
}
