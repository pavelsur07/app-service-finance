package executor

import (
	"context"
	"encoding/json"
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

	pages := map[int64]string{0: `[{"rrdId":10},{"rrdId":20}]`, 20: `[{"rrdId":30}]`}
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
	assert.Len(t, raw.keys, 2, "страница 1 не перезаписана")

	require.NoError(t, tasks.Upsert(ctx, []store.TaskSpec{{TaskKey: key, Refresh: true, RunAt: now}}))
	again := exec.Run(ctx, claim(now, "00000000-0000-7000-8000-000000000003"), c, "t3")
	assert.Equal(t, "unchanged", again.Outcome, "тот же отчёт — ревизия не растёт")
}
