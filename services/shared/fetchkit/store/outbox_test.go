package store

import (
	"context"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

const factResource = "ozon.finance.accrual-by-day"

func factTasks(t *testing.T) *Tasks {
	t.Helper()
	tasks := testTasksWith(t, Options{PublishResources: []string{factResource}})
	_, err := tasks.pool.Exec(context.Background(), `TRUNCATE outbox_batch`)
	require.NoError(t, err)
	return tasks
}

// load — одна загрузка задачи одной страницей с хешем содержимого contentHash.
func load(t *testing.T, tasks *Tasks, s TaskSpec, page, contentHash string, now time.Time) {
	t.Helper()
	ctx := context.Background()
	s.Refresh = true
	require.NoError(t, tasks.Upsert(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, []string{s.ConnectionRef}, now)
	require.NoError(t, err)
	obj := RawObject{PageNo: 1, StorageURI: "s3://b/" + page, SHA256: hash(page), SizeBytes: 1, StoredBytes: 1, ContentType: "application/json"}
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, obj, ""))
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash(contentHash)}, now)
	require.NoError(t, err)
}

func TestNewRevisionEnqueuesExactlyOneFactBatch(t *testing.T) {
	tasks := factTasks(t)
	ctx := context.Background()
	day := spec("ref-a", factResource, "2026-10-20", 5)

	load(t, tasks, day, "p1", "rev1", t0)
	load(t, tasks, day, "p1", "rev1", t0.Add(time.Hour)) // перезагрузка без изменений
	batches, err := tasks.NewFactBatches(ctx, factResource, time.Time{}, "", 10)
	require.NoError(t, err)
	require.Len(t, batches, 1, "тот же content_hash — та же ревизия, новой пачки нет")
	assert.Equal(t, "ref-a:ozon.finance.accrual-by-day:2026-10-20:r1", batches[0].BatchID)
	assert.Equal(t, day.TaskKey, batches[0].TaskKey)
	assert.Equal(t, 1, batches[0].Revision)
	assert.Equal(t, hash("rev1"), batches[0].ContentHash)
	assert.Equal(t, []string{hash("p1")}, batches[0].PageHashes)

	load(t, tasks, day, "p2", "rev2", t0.Add(2*time.Hour))
	batches, err = tasks.NewFactBatches(ctx, factResource, time.Time{}, "", 10)
	require.NoError(t, err)
	require.Len(t, batches, 2, "изменение дня — новая пачка, прежняя остаётся")
	assert.Equal(t, []int{1, 2}, []int{batches[0].Revision, batches[1].Revision})

	// Страница прежней ревизии читается и после её замены.
	obj, err := tasks.FactBatchPage(ctx, batches[0], 1)
	require.NoError(t, err)
	assert.Equal(t, "s3://b/p1", obj.StorageURI)
	_, err = tasks.FactBatchPage(ctx, batches[0], 2)
	require.ErrorIs(t, err, ErrNoBatch)
}

func TestUnpublishedResourceEnqueuesNothing(t *testing.T) {
	tasks := factTasks(t)
	load(t, tasks, spec("ref-a", "ozon.orders.fbo", "2026-10-20", 5), "p1", "rev1", t0)
	batches, err := tasks.NewFactBatches(context.Background(), "ozon.orders.fbo", time.Time{}, "", 10)
	require.NoError(t, err)
	assert.Empty(t, batches)
}

func TestFactBatchesPageByCursorAndAckIsIdempotent(t *testing.T) {
	tasks := factTasks(t)
	ctx := context.Background()
	for i, period := range []string{"2026-10-20", "2026-10-21", "2026-10-22"} {
		load(t, tasks, spec("ref-a", factResource, period, 5), "p-"+period, "rev-"+period, t0.Add(time.Duration(i)*time.Minute))
	}

	first, err := tasks.NewFactBatches(ctx, factResource, time.Time{}, "", 2)
	require.NoError(t, err)
	require.Len(t, first, 2)
	rest, err := tasks.NewFactBatches(ctx, factResource, first[1].CreatedAt, first[1].BatchID, 2)
	require.NoError(t, err)
	require.Len(t, rest, 1)
	assert.Equal(t, "2026-10-22", rest[0].PeriodKey)

	acked, err := tasks.AckFactBatches(ctx, []string{first[0].BatchID, "unknown"}, t0)
	require.NoError(t, err)
	assert.EqualValues(t, 1, acked)
	acked, err = tasks.AckFactBatches(ctx, []string{first[0].BatchID}, t0)
	require.NoError(t, err)
	assert.EqualValues(t, 0, acked, "повторное подтверждение — не ошибка")

	left, err := tasks.NewFactBatches(ctx, factResource, time.Time{}, "", 10)
	require.NoError(t, err)
	require.Len(t, left, 2, "подтверждённая пачка больше не выдаётся")
	got, err := tasks.FactBatch(ctx, first[0].BatchID)
	require.NoError(t, err, "по идентификатору пачка доступна и после подтверждения")
	assert.Equal(t, first[0].BatchID, got.BatchID)
	_, err = tasks.FactBatch(ctx, "nope")
	require.ErrorIs(t, err, ErrNoBatch)

	// Перезагрузка подтверждённого дня без изменений пачку не возвращает.
	load(t, tasks, spec("ref-a", factResource, "2026-10-20", 5), "p-2026-10-20", "rev-2026-10-20", t0.Add(time.Hour))
	left, err = tasks.NewFactBatches(ctx, factResource, time.Time{}, "", 10)
	require.NoError(t, err)
	assert.Len(t, left, 2)
}
