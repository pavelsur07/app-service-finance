package dispatcher

import (
	"context"
	"io"
	"log/slog"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/fetch"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

// cab — кабинет произвольного сервиса: ядро знает о нём только CabinetRef.
type cab struct{ ref, seller string }

func (c cab) CabinetRef() string     { return c.ref }
func (c cab) CabinetCompany() string { return "c" }
func (c cab) Clone() cab             { return c }

type snapshot []cab

func (s snapshot) Ready() bool { return true }
func (s snapshot) List() []cab { return s }
func (s snapshot) Get(ref string) (cab, bool) {
	for _, c := range s {
		if c.ref == ref {
			return c, true
		}
	}
	return cab{}, false
}

type oneTask struct {
	mu   sync.Mutex
	refs []string
	done bool
}

func (o *oneTask) ClaimNext(_ context.Context, refs []string, _, attempt, _ string, _ time.Time, _ time.Duration) (store.Task, error) {
	o.mu.Lock()
	defer o.mu.Unlock()
	if o.done {
		return store.Task{}, store.ErrNoTask
	}
	o.done = true
	o.refs = refs
	return store.Task{TaskKey: store.TaskKey{ConnectionRef: "b", ResourceType: "wb.r", PeriodKey: "p"}, AttemptID: attempt}, nil
}
func (o *oneTask) ReleaseExpired(context.Context, time.Time) (int64, error) { return 0, nil }

type runner struct {
	got chan cab
}

func (r runner) Run(_ context.Context, _ store.Task, c cab, _ string) fetch.Result {
	r.got <- c
	return fetch.Result{Outcome: "done"}
}

func TestDispatcherClaimsForAllCabinetsAndPassesTheTaskCabinet(t *testing.T) {
	claimer := &oneTask{}
	r := runner{got: make(chan cab, 1)}
	d := New[cab](Config{MaxParallel: 2, IdlePoll: 10 * time.Millisecond, ReleaseEvery: time.Hour, Owner: "h:1"},
		claimer, snapshot{{ref: "a"}, {ref: "b", seller: "s-b"}}, r, slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now, nil)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { d.Run(ctx); close(done) }()

	select {
	case got := <-r.got:
		assert.Equal(t, cab{ref: "b", seller: "s-b"}, got)
	case <-time.After(5 * time.Second):
		t.Fatal("задача не выдана исполнителю")
	}
	cancel()
	<-done
	claimer.mu.Lock()
	defer claimer.mu.Unlock()
	require.Equal(t, []string{"a", "b"}, claimer.refs)
}
