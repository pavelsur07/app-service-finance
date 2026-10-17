package store

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store/storetest"
)

// Интеграционные тесты: TEST_DB_DSN — отдельная пустая БД; каждый тест
// начинает с чистых таблиц. Без переменной — пропуск.
func testTasks(t *testing.T) *Tasks {
	t.Helper()
	dsn := storetest.DSN(t, "store")
	ctx := context.Background()
	require.NoError(t, Migrate(ctx, dsn))
	pool, err := Open(ctx, dsn, PoolSize(4))
	require.NoError(t, err)
	t.Cleanup(pool.Close)
	_, err = pool.Exec(ctx, `TRUNCATE raw_object, fetch_attempt, fetch_task, connection_state, connection_limit, resource_stop, planner_run`)
	require.NoError(t, err)
	return NewTasks(pool)
}

var t0 = time.Date(2026, 10, 17, 9, 0, 0, 0, time.UTC)

func spec(ref, resource, period string, priority int) TaskSpec {
	return TaskSpec{TaskKey: TaskKey{ConnectionRef: ref, ResourceType: resource, PeriodKey: period}, Priority: priority, RunAt: t0}
}

var attemptSeq atomic.Int64

func newAttemptID() string {
	return fmt.Sprintf("00000000-0000-7000-8000-%012d", attemptSeq.Add(1))
}

func claim(t *testing.T, tasks *Tasks, refs []string, now time.Time) (Task, error) {
	t.Helper()
	return tasks.ClaimNext(context.Background(), refs, "worker-1", newAttemptID(), "trace-1", now, time.Minute)
}

func status(t *testing.T, tasks *Tasks, key TaskKey) string {
	t.Helper()
	var s string
	require.NoError(t, tasks.pool.QueryRow(context.Background(),
		`SELECT status FROM fetch_task WHERE connection_ref=$1 AND resource_type=$2 AND period_key=$3`,
		key.ConnectionRef, key.ResourceType, key.PeriodKey).Scan(&s))
	return s
}

func TestUpsertIsIdempotentAndKeepsRunning(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.finance.accrual-by-day", "2026-10-15", 1)

	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s, s}))
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	var n int
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT count(*) FROM fetch_task`).Scan(&n))
	assert.Equal(t, 1, n)

	task, err := claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	s.Refresh = true
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusRunning, status(t, tasks, task.TaskKey), "upsert не сбрасывает running")
}

func TestUpsertRefreshReopensDoneOnlyOnRequest(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.finance.accrual-by-day", "2026-10-15", 0)
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)

	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusDone, status(t, tasks, s.TaskKey))

	s.Refresh = true
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey))
}

func TestUpsertRefreshRevivesPermanentFailure(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "r", "2026-10-01", 0)
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusFailedPermanent, Label: "invalid_response", ErrorClass: "invalid_response", NextRunAt: t0}, t0)
	require.NoError(t, err)

	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusFailedPermanent, status(t, tasks, s.TaskKey), "обычная постановка не перезапускает")
	s.Refresh = true
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey))
}

func TestConcurrentClaimsNeverRunOneConnectionTwice(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	var specs []TaskSpec
	for day := 1; day <= 20; day++ {
		specs = append(specs, spec("ref-a", "ozon.finance.accrual-by-day", fmt.Sprintf("2026-10-%02d", day), 0))
	}
	specs = append(specs, spec("ref-b", "ozon.finance.accrual-by-day", "2026-10-01", 0))
	require.NoError(t, tasks.Upsert(ctx, specs))

	var wg sync.WaitGroup
	var mu sync.Mutex
	claimedByRef := map[string]int{}
	for range 16 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			task, err := tasks.ClaimNext(ctx, []string{"ref-a", "ref-b"}, "w", newAttemptID(), "trace", t0, time.Minute)
			if errors.Is(err, ErrNoTask) {
				return
			}
			assert.NoError(t, err)
			mu.Lock()
			claimedByRef[task.ConnectionRef]++
			mu.Unlock()
		}()
	}
	wg.Wait()

	assert.Equal(t, map[string]int{"ref-a": 1, "ref-b": 1}, claimedByRef)
	var running int
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT count(*) FROM fetch_task WHERE status='running'`).Scan(&running))
	assert.Equal(t, 2, running)
}

func TestClaimOrderPriorityThenLeastRecentlyServed(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{
		spec("ref-a", "r", "2026-10-01", 0),
		spec("ref-b", "r", "2026-10-01", 0),
		spec("ref-c", "r", "2026-10-01", 5),
	}))
	_, err := tasks.pool.Exec(ctx, `INSERT INTO connection_state (connection_ref, last_served_at) VALUES ('ref-a', $1), ('ref-b', $2)`, t0.Add(-time.Hour), t0.Add(-2*time.Hour))
	require.NoError(t, err)
	refs := []string{"ref-a", "ref-b", "ref-c"}

	first, err := claim(t, tasks, refs, t0)
	require.NoError(t, err)
	second, err := claim(t, tasks, refs, t0)
	require.NoError(t, err)
	third, err := claim(t, tasks, refs, t0)
	require.NoError(t, err)

	assert.Equal(t, []string{"ref-c", "ref-b", "ref-a"}, []string{first.ConnectionRef, second.ConnectionRef, third.ConnectionRef})
}

func TestClaimSkipsInactiveSuspendedStoppedAndFuture(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	future := spec("ref-f", "r", "2026-10-01", 0)
	future.RunAt = t0.Add(time.Hour)
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{
		spec("ref-gone", "r", "2026-10-01", 0),
		spec("ref-s", "r", "2026-10-01", 0),
		spec("ref-x", "stopped", "2026-10-01", 0),
		future,
	}))
	require.NoError(t, tasks.SuspendConnection(ctx, "ref-s", "auth", t0.Add(time.Hour)))
	require.NoError(t, tasks.StopResource(ctx, "stopped", "obsolete_endpoint"))

	_, err := claim(t, tasks, []string{"ref-s", "ref-x", "ref-f"}, t0)
	assert.ErrorIs(t, err, ErrNoTask)

	task, err := claim(t, tasks, []string{"ref-s"}, t0.Add(2*time.Hour))
	require.NoError(t, err, "пауза истекла")
	assert.Equal(t, "ref-s", task.ConnectionRef)
}

func TestExpiredLeaseIsReleasedWithCursor(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0)}))
	task, err := claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), "cursor-1"))

	released, err := tasks.ReleaseExpired(ctx, t0.Add(30*time.Second))
	require.NoError(t, err)
	assert.Zero(t, released, "lease ещё жив")

	released, err = tasks.ReleaseExpired(ctx, t0.Add(2*time.Minute))
	require.NoError(t, err)
	assert.EqualValues(t, 1, released)

	next, err := claim(t, tasks, []string{"ref-a"}, t0.Add(2*time.Minute))
	require.NoError(t, err)
	assert.Equal(t, "cursor-1", next.Cursor)
	assert.Equal(t, 2, next.NextPageNo)
	assert.Equal(t, []string{hash("p")}, next.PageHashes, "хеши страниц прежней попытки сохранены")
	assert.Equal(t, "lease_expired", next.LastErrorClass)
	assert.Equal(t, 2, next.Attempts)

	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone}, t0)
	assert.ErrorIs(t, err, ErrLeaseLost, "старая попытка не может закрыть перехваченную задачу")
	assert.ErrorIs(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(2), ""), ErrLeaseLost)
	assert.ErrorIs(t, tasks.ExtendLease(ctx, task.TaskKey, task.AttemptID, t0.Add(time.Hour)), ErrLeaseLost)
}

func TestReleaseRacingWithLeaseExtensionKeepsTaskAndAttemptConsistent(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	for i := range 40 {
		key := spec(fmt.Sprintf("ref-%d", i), "r", "2026-10-01", 0)
		require.NoError(t, tasks.Upsert(ctx, []TaskSpec{key}))
		task, err := claim(t, tasks, []string{key.ConnectionRef}, t0)
		require.NoError(t, err)

		var wg sync.WaitGroup
		wg.Add(2)
		go func() {
			defer wg.Done()
			_ = tasks.ExtendLease(ctx, task.TaskKey, task.AttemptID, t0.Add(time.Hour))
		}()
		go func() {
			defer wg.Done()
			_, err := tasks.ReleaseExpired(ctx, t0.Add(2*time.Minute))
			assert.NoError(t, err)
		}()
		wg.Wait()

		var taskStatus string
		var outcome *string
		require.NoError(t, tasks.pool.QueryRow(ctx, `
			SELECT t.status, a.outcome FROM fetch_task t JOIN fetch_attempt a ON a.attempt_id = $4
			WHERE t.connection_ref = $1 AND t.resource_type = $2 AND t.period_key = $3`,
			task.ConnectionRef, task.ResourceType, task.PeriodKey, task.AttemptID).Scan(&taskStatus, &outcome))
		if taskStatus == StatusRunning {
			assert.Nil(t, outcome, "задача продлена — попытка не закрыта")
		} else {
			require.NotNil(t, outcome)
			assert.Equal(t, "lease_expired", *outcome, "задача освобождена — попытка закрыта")
		}
	}
}

func TestFinishTaskRevisionFollowsContentHash(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "r", "2026-10-01", 0)
	s.Refresh = true
	run := func(h string) int {
		require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
		task, err := claim(t, tasks, []string{"ref-a"}, t0)
		require.NoError(t, err)
		rev, err := tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: h}, t0)
		require.NoError(t, err)
		return rev
	}

	assert.Equal(t, 1, run(hash("a")))
	assert.Equal(t, 1, run(hash("a")), "тот же контент — та же ревизия")
	assert.Equal(t, 2, run(hash("b")))
}

func TestFinishTaskNotDoneKeepsOrResetsCursor(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0)}))
	task, err := claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), "cursor-1"))
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusFailedRetryable, Label: "transient", ErrorClass: "transient", NextRunAt: t0, KeepCursor: true}, t0)
	require.NoError(t, err)

	again, err := claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	assert.Equal(t, "cursor-1", again.Cursor)

	_, err = tasks.FinishTask(ctx, again.TaskKey, again.AttemptID, Outcome{Status: StatusFailedRetryable, Label: "invalid_response", ErrorClass: "invalid_response", NextRunAt: t0}, t0)
	require.NoError(t, err)
	third, err := claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	assert.Empty(t, third.Cursor, "без KeepCursor загрузка дня начинается заново")
	assert.Equal(t, 1, third.NextPageNo)
	assert.Empty(t, third.PageHashes)
}

func TestLimitsRoundTrip(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.SaveCurrentRate(ctx, "ref-a", "/v1/x", 0.5))
	require.NoError(t, tasks.SetOverride(ctx, "ref-a", "/v1/x", 2))
	require.NoError(t, tasks.SaveCurrentRate(ctx, "ref-a", "/v1/x", 0.25))
	require.NoError(t, tasks.SetOverride(ctx, "ref-b", "/v1/x", 0))

	limits, err := tasks.LoadLimits(ctx)
	require.NoError(t, err)
	assert.ElementsMatch(t, []Limit{
		{ConnectionRef: "ref-a", Endpoint: "/v1/x", Override: 2, Current: 0.25},
		{ConnectionRef: "ref-b", Endpoint: "/v1/x"},
	}, limits)
}

func hash(s string) string {
	return fmt.Sprintf("%064s", s)
}

func rawObj(page int) RawObject {
	return RawObject{PageNo: page, StorageURI: fmt.Sprintf("s3://bucket/p%d", page), SHA256: hash("p"), SizeBytes: 10, StoredBytes: 5, ContentType: "application/json"}
}

func TestPlanOnceRunsOncePerDateEvenConcurrently(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "r", "2026-10-01", 5)
	s.Refresh = true
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{{TaskKey: s.TaskKey, RunAt: t0}}))
	task, err := claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)

	var wg sync.WaitGroup
	var ran atomic.Int32
	for range 8 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			done, err := tasks.PlanOnce(ctx, "tail", "2026-10-17", []TaskSpec{s})
			assert.NoError(t, err)
			if done {
				ran.Add(1)
			}
		}()
	}
	wg.Wait()
	assert.EqualValues(t, 1, ran.Load(), "проход за дату — ровно один")
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey))

	// Задача снова загружена — тот же проход за ту же дату её не трогает.
	task, err = claim(t, tasks, []string{"ref-a"}, t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)
	done, err := tasks.PlanOnce(ctx, "tail", "2026-10-17", []TaskSpec{s})
	require.NoError(t, err)
	assert.False(t, done)
	assert.Equal(t, StatusDone, status(t, tasks, s.TaskKey))

	done, err = tasks.PlanOnce(ctx, "tail", "2026-10-18", []TaskSpec{s})
	require.NoError(t, err)
	assert.True(t, done, "следующая дата — новый проход")
}

func TestAuthFailuresAreCountedPerCabinet(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	pause := func(n int) time.Duration {
		if n >= 2 {
			return time.Hour
		}
		return time.Minute
	}
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0), spec("ref-a", "r", "2026-10-02", 0)}))

	n, err := tasks.RecordAuthFailure(ctx, "ref-a", t0, pause)
	require.NoError(t, err)
	assert.Equal(t, 1, n)
	_, err = claim(t, tasks, []string{"ref-a"}, t0.Add(30*time.Second))
	assert.ErrorIs(t, err, ErrNoTask, "короткая пауза кабинета")

	n, err = tasks.RecordAuthFailure(ctx, "ref-a", t0.Add(2*time.Minute), pause)
	require.NoError(t, err)
	assert.Equal(t, 2, n, "второй отказ — по кабинету, задача может быть другой")
	_, err = claim(t, tasks, []string{"ref-a"}, t0.Add(30*time.Minute))
	assert.ErrorIs(t, err, ErrNoTask, "длинная пауза")

	require.NoError(t, tasks.ResumeConnection(ctx, "ref-a"))
	n, err = tasks.RecordAuthFailure(ctx, "ref-a", t0.Add(3*time.Hour), pause)
	require.NoError(t, err)
	assert.Equal(t, 1, n, "успех после смены ключа сбрасывает счётчик")
}
