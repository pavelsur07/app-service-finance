package dispatcher

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"regexp"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"go.uber.org/goleak"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

func TestMain(m *testing.M) { goleak.VerifyTestMain(m) }

type fakeCabinets struct {
	ready atomic.Bool
	list  []connections.Cabinet
}

func (f *fakeCabinets) Ready() bool                 { return f.ready.Load() }
func (f *fakeCabinets) List() []connections.Cabinet { return f.list }
func (f *fakeCabinets) Get(ref string) (connections.Cabinet, bool) {
	for _, c := range f.list {
		if c.Ref == ref {
			return c, true
		}
	}
	return connections.Cabinet{}, false
}

func cabinets(refs ...string) *fakeCabinets {
	f := &fakeCabinets{}
	for _, r := range refs {
		f.list = append(f.list, connections.Cabinet{Ref: r})
	}
	f.ready.Store(true)
	return f
}

// queueClaimer — очередь задач в памяти с правилом «один кабинет — одна задача».
type queueClaimer struct {
	mu       sync.Mutex
	queue    []store.Task
	running  map[string]bool
	err      error
	claims   int
	released atomic.Int32
}

func newQueue(tasks ...store.Task) *queueClaimer {
	return &queueClaimer{queue: tasks, running: map[string]bool{}}
}

func (q *queueClaimer) ClaimNext(_ context.Context, refs []string, _, attemptID, _ string, _ time.Time, _ time.Duration) (store.Task, error) {
	q.mu.Lock()
	defer q.mu.Unlock()
	q.claims++
	if q.err != nil {
		return store.Task{}, q.err
	}
	allowed := map[string]bool{}
	for _, r := range refs {
		allowed[r] = true
	}
	for i, t := range q.queue {
		if allowed[t.ConnectionRef] && !q.running[t.ConnectionRef] {
			q.queue = append(q.queue[:i], q.queue[i+1:]...)
			q.running[t.ConnectionRef] = true
			t.AttemptID = attemptID
			return t, nil
		}
	}
	return store.Task{}, store.ErrNoTask
}

func (q *queueClaimer) done(ref string) {
	q.mu.Lock()
	defer q.mu.Unlock()
	delete(q.running, ref)
}

func (q *queueClaimer) ReleaseExpired(context.Context, time.Time) (int64, error) {
	q.released.Add(1)
	return 0, nil
}

func (q *queueClaimer) remaining() int {
	q.mu.Lock()
	defer q.mu.Unlock()
	return len(q.queue)
}

type recordingRunner struct {
	queue    *queueClaimer
	delay    func(ref string) time.Duration
	mu       sync.Mutex
	inFlight int
	maxSeen  int
	perRef   map[string]int
	violated bool
	done     []string
	ctxDone  atomic.Int32
}

func (r *recordingRunner) Run(ctx context.Context, task store.Task, _ connections.Cabinet, traceID string) executor.Result {
	r.mu.Lock()
	if r.perRef == nil {
		r.perRef = map[string]int{}
	}
	r.inFlight++
	r.maxSeen = max(r.maxSeen, r.inFlight)
	r.perRef[task.ConnectionRef]++
	if r.perRef[task.ConnectionRef] > 1 {
		r.violated = true
	}
	r.mu.Unlock()

	select {
	case <-time.After(r.delay(task.ConnectionRef)):
	case <-ctx.Done():
		r.ctxDone.Add(1)
	}

	r.mu.Lock()
	r.inFlight--
	r.perRef[task.ConnectionRef]--
	r.done = append(r.done, task.ConnectionRef+"/"+task.PeriodKey)
	r.mu.Unlock()
	r.queue.done(task.ConnectionRef)
	return executor.Result{Outcome: "done"}
}

func tasksFor(ref string, n int) []store.Task {
	var out []store.Task
	for i := range n {
		out = append(out, store.Task{TaskKey: store.TaskKey{ConnectionRef: ref, ResourceType: "r", PeriodKey: fmt.Sprintf("2026-10-%02d", i+1)}})
	}
	return out
}

func newDispatcher(q Claimer, c Cabinets, r Runner, maxParallel int) *Dispatcher {
	return New(Config{MaxParallel: maxParallel, Lease: time.Minute, IdlePoll: 5 * time.Millisecond, ReleaseEvery: 5 * time.Millisecond, Owner: "test:1"},
		q, c, r, slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now, nil)
}

func runUntil(t *testing.T, d *Dispatcher, cond func() bool) {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		d.Run(ctx)
		close(done)
	}()
	stopped := false
	stop := func() {
		if stopped {
			return
		}
		stopped = true
		cancel()
		select {
		case <-done:
		case <-time.After(5 * time.Second):
			t.Error("Run did not return after cancel")
		}
	}
	t.Cleanup(stop) // и при FailNow в Eventually
	require.Eventually(t, cond, 5*time.Second, time.Millisecond)
	stop()
}

func TestParallelismIsCappedAndCabinetsNeverOverlap(t *testing.T) {
	var all []store.Task
	for _, ref := range []string{"a", "b", "c", "d", "e"} {
		all = append(all, tasksFor(ref, 4)...)
	}
	q := newQueue(all...)
	r := &recordingRunner{queue: q, delay: func(string) time.Duration { return 3 * time.Millisecond }}
	d := newDispatcher(q, cabinets("a", "b", "c", "d", "e"), r, 3)

	runUntil(t, d, func() bool {
		r.mu.Lock()
		defer r.mu.Unlock()
		return len(r.done) == 20
	})

	assert.Equal(t, 3, r.maxSeen, "потолок достигнут и не превышен")
	assert.False(t, r.violated)
}

func TestSlowCabinetDoesNotBlockOthers(t *testing.T) {
	q := newQueue(append(tasksFor("slow", 1), tasksFor("fast", 10)...)...)
	r := &recordingRunner{queue: q, delay: func(ref string) time.Duration {
		if ref == "slow" {
			return 300 * time.Millisecond
		}
		return time.Millisecond
	}}
	d := newDispatcher(q, cabinets("slow", "fast"), r, 2)

	start := time.Now()
	runUntil(t, d, func() bool {
		r.mu.Lock()
		defer r.mu.Unlock()
		fast := 0
		for _, k := range r.done {
			if k[:4] == "fast" {
				fast++
			}
		}
		return fast == 10
	})
	assert.Less(t, time.Since(start), 300*time.Millisecond, "быстрый кабинет закончил, пока медленный ещё работал")
}

func TestWaitsForSnapshot(t *testing.T) {
	q := newQueue(tasksFor("a", 1)...)
	c := cabinets("a")
	c.ready.Store(false)
	r := &recordingRunner{queue: q, delay: func(string) time.Duration { return 0 }}
	d := newDispatcher(q, c, r, 1)

	time.AfterFunc(30*time.Millisecond, func() { c.ready.Store(true) })
	runUntil(t, d, func() bool { return q.remaining() == 0 })
	q.mu.Lock()
	defer q.mu.Unlock()
	assert.Positive(t, q.claims)
}

func TestClaimErrorsDoNotStopTheLoop(t *testing.T) {
	q := newQueue(tasksFor("a", 1)...)
	q.err = errors.New("db down")
	r := &recordingRunner{queue: q, delay: func(string) time.Duration { return 0 }}
	d := newDispatcher(q, cabinets("a"), r, 1)

	time.AfterFunc(30*time.Millisecond, func() {
		q.mu.Lock()
		q.err = nil
		q.mu.Unlock()
	})
	runUntil(t, d, func() bool { return q.remaining() == 0 })
}

func TestOnlyActiveCabinetsAreClaimed(t *testing.T) {
	q := newQueue(append(tasksFor("gone", 2), tasksFor("a", 1)...)...)
	r := &recordingRunner{queue: q, delay: func(string) time.Duration { return 0 }}
	d := newDispatcher(q, cabinets("a"), r, 2)

	runUntil(t, d, func() bool { return q.remaining() == 2 })
	assert.Equal(t, 2, q.remaining(), "задачи исчезнувшего кабинета не выдаются")
}

func TestShutdownWaitsForWorkers(t *testing.T) {
	q := newQueue(tasksFor("a", 1)...)
	r := &recordingRunner{queue: q, delay: func(string) time.Duration { return time.Hour }}
	d := newDispatcher(q, cabinets("a"), r, 1)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		d.Run(ctx)
		close(done)
	}()
	t.Cleanup(func() {
		cancel()
		<-done
	})
	require.Eventually(t, func() bool { return q.remaining() == 0 }, time.Second, time.Millisecond)

	cancel()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("Run did not return")
	}
	assert.EqualValues(t, 1, r.ctxDone.Load(), "воркер получил отмену и завершился до возврата Run")
}

func TestReleaseExpiredRunsInBackground(t *testing.T) {
	q := newQueue()
	d := newDispatcher(q, cabinets("a"), &recordingRunner{queue: q, delay: func(string) time.Duration { return 0 }}, 1)
	runUntil(t, d, func() bool { return q.released.Load() >= 2 })
}

func TestNewUUIDIsVersion4(t *testing.T) {
	re := regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
	seen := map[string]bool{}
	for range 100 {
		id := NewUUID()
		assert.Regexp(t, re, id)
		assert.False(t, seen[id])
		seen[id] = true
	}
}
