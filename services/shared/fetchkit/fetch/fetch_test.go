package fetch

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

var t0 = time.Date(2026, 11, 2, 9, 0, 0, 0, time.UTC)

type fakeTasks struct {
	mu           sync.Mutex
	pages        []store.RawObject
	cursors      []string
	finished     []store.Outcome
	resumed      int
	stopped      []string
	authFailures int
}

func (f *fakeTasks) ExtendLease(context.Context, store.TaskKey, string, time.Time) error { return nil }
func (f *fakeTasks) RecordPage(_ context.Context, _ store.TaskKey, _ string, obj store.RawObject, next string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.pages = append(f.pages, obj)
	f.cursors = append(f.cursors, next)
	return nil
}
func (f *fakeTasks) FindObject(_ context.Context, _ store.TaskKey, sha string) (store.RawObject, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, p := range f.pages {
		if p.SHA256 == sha {
			return p, true, nil
		}
	}
	return store.RawObject{}, false, nil
}
func (f *fakeTasks) FinishTask(_ context.Context, _ store.TaskKey, _ string, out store.Outcome, _ time.Time) (int, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.finished = append(f.finished, out)
	return 1, nil
}
func (f *fakeTasks) RecordAuthFailure(context.Context, string, time.Time, func(int) time.Duration) (int, error) {
	f.authFailures++
	return f.authFailures, nil
}
func (f *fakeTasks) ResumeConnection(context.Context, string) error { f.resumed++; return nil }
func (f *fakeTasks) StopResource(_ context.Context, resource, _ string) error {
	f.stopped = append(f.stopped, resource)
	return nil
}

type fakeRaw struct{ keys []string }

func (f *fakeRaw) Put(_ context.Context, obj rawstore.Object) (rawstore.Stored, error) {
	key := rawstore.Key(obj, strings.Repeat("a", 64))
	f.keys = append(f.keys, key)
	return rawstore.Stored{URI: "s3://b/" + key, Key: key, StoredBytes: int64(len(obj.Body))}, nil
}

type noRefresh struct{ calls int }

func (r *noRefresh) ForceRefresh(context.Context) (bool, error) { r.calls++; return true, nil }

type cab struct{ ref string }

var errOwn = errors.New("adapter: own error")

// fakeAdapter отдаёт страницы pages по очереди, затем err.
type fakeAdapter struct {
	pages []Page
	err   error
}

func (a *fakeAdapter) Fetch(ctx context.Context, run *Run, _ cab) error {
	for _, p := range a.pages {
		if err := run.Store(ctx, p); err != nil {
			return err
		}
	}
	return a.err
}

func (a *fakeAdapter) Classify(c *Classification) (store.Outcome, bool) {
	if errors.Is(c.Err, errOwn) {
		return store.Outcome{Status: store.StatusNotReady, Label: "own", ErrorClass: "own", NextRunAt: c.Now.Add(c.NotReadyDelay())}, true
	}
	return store.Outcome{}, false
}
func (a *fakeAdapter) ResumesConnection(string) bool             { return true }
func (a *fakeAdapter) LogLevel(store.Outcome) (slog.Level, bool) { return 0, false }

type harness struct {
	tasks   *fakeTasks
	raw     *fakeRaw
	refresh *noRefresh
	logs    *bytes.Buffer
}

func run(t *testing.T, adapter *fakeAdapter, task store.Task) (Result, *harness) {
	t.Helper()
	h := &harness{tasks: &fakeTasks{}, raw: &fakeRaw{}, refresh: &noRefresh{}, logs: &bytes.Buffer{}}
	cfg := Config{Provider: "wb", Lease: time.Minute, FinishTimeout: time.Second, BackoffBase: time.Minute, BackoffMax: time.Hour,
		RateLimitPause: 30 * time.Second, AuthRetryPause: time.Minute, AuthSuspend: time.Hour, NoCredentialsIn: time.Hour,
		NotReadyDelay: map[string]time.Duration{"wb.r": 2 * time.Hour}}
	exec := New[cab](cfg, h.tasks, h.raw, h.refresh, adapter, slog.New(slog.NewJSONHandler(h.logs, nil)),
		func() time.Time { return t0 }, func(time.Duration) time.Duration { return 0 })
	return exec.Run(context.Background(), task, cab{ref: task.ConnectionRef}, "trace"), h
}

func task() store.Task {
	return store.Task{TaskKey: store.TaskKey{ConnectionRef: "ref", ResourceType: "wb.r", PeriodKey: "2026-11-01"}, AttemptID: "att", Attempts: 1, NextPageNo: 1}
}

func TestFirstPageThenSilenceIsDone(t *testing.T) {
	res, h := run(t, &fakeAdapter{pages: []Page{
		{No: 1, Body: []byte(`[{"rrd_id":1}]`), Cursor: "1", ContentType: "application/json"},
		{No: 2, Body: []byte(`[]`), ContentType: "application/json"},
	}}, task())

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, 2, res.Pages)
	require.Len(t, h.raw.keys, 2)
	assert.True(t, strings.HasPrefix(h.raw.keys[0], "wb/ref/wb.r/2026-11-01/"), "префикс провайдера из Config")
	assert.Equal(t, []string{"1", ""}, h.tasks.cursors, "курсор пишется с каждой страницей")
	require.Len(t, h.tasks.finished, 1)
	assert.Equal(t, ContentHash([]string{h.tasks.pages[0].SHA256, h.tasks.pages[1].SHA256}), h.tasks.finished[0].ContentHash)
	assert.Equal(t, 1, h.tasks.resumed)
}

func TestSameContentIsUnchanged(t *testing.T) {
	body := []byte(`[{"rrd_id":1}]`)
	tk := task()
	tk.ContentHash = ContentHash([]string{SHA256Hex(body)})

	res, _ := run(t, &fakeAdapter{pages: []Page{{No: 1, Body: body}}}, tk)

	assert.Equal(t, "unchanged", res.Outcome)
}

func TestAdapterClassifiesOwnErrorsFirst(t *testing.T) {
	_, h := run(t, &fakeAdapter{err: errOwn}, task())

	require.Len(t, h.tasks.finished, 1)
	assert.Equal(t, store.StatusNotReady, h.tasks.finished[0].Status)
	assert.Equal(t, t0.Add(2*time.Hour), h.tasks.finished[0].NextRunAt, "задержка not_ready ресурса из Config")
}

func TestRateLimitPausesForRetryAfter(t *testing.T) {
	_, h := run(t, &fakeAdapter{err: &apierr.APIError{Source: "wb", Class: apierr.ClassRateLimit, Status: 429, RetryAfter: 70 * time.Second}}, task())

	out := h.tasks.finished[0]
	assert.Equal(t, store.StatusPending, out.Status)
	assert.True(t, out.KeepCursor)
	assert.Equal(t, t0.Add(70*time.Second), out.NextRunAt)
	assert.Equal(t, 429, out.HTTPStatus)
}

func TestObsoleteEndpointStopsResourceForAll(t *testing.T) {
	_, h := run(t, &fakeAdapter{err: &apierr.APIError{Source: "wb", Class: apierr.ClassObsoleteEndpoint, Endpoint: "/api/x", Status: 404}}, task())

	assert.Equal(t, []string{"wb.r"}, h.tasks.stopped)
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished[0].Status)
	assert.Contains(t, h.logs.String(), `"msg":"wb endpoint is obsolete, resource stopped for all cabinets"`)
}

func TestSecondAuthFailureInARowIsError(t *testing.T) {
	h := &harness{tasks: &fakeTasks{}, raw: &fakeRaw{}, refresh: &noRefresh{}, logs: &bytes.Buffer{}}
	cfg := Config{Provider: "wb", Lease: time.Minute, FinishTimeout: time.Second, AuthRetryPause: time.Minute, AuthSuspend: time.Hour}
	exec := New[cab](cfg, h.tasks, h.raw, h.refresh, &fakeAdapter{err: &apierr.APIError{Class: apierr.ClassAuth, Status: 401}},
		slog.New(slog.NewJSONHandler(h.logs, nil)), func() time.Time { return t0 }, func(time.Duration) time.Duration { return 0 })

	exec.Run(context.Background(), task(), cab{}, "t1")
	assert.NotContains(t, h.logs.String(), `"level":"ERROR"`)
	exec.Run(context.Background(), task(), cab{}, "t2")

	assert.Contains(t, h.logs.String(), `"msg":"wb rejected cabinet keys again, cabinet suspended"`)
	assert.Equal(t, 2, h.refresh.calls, "снимок кабинетов обновляется после отказа ключа")
	assert.Equal(t, store.StatusPending, h.tasks.finished[1].Status)
}

func TestTransientIsRetriedWithBackoffAndUnknownIsPermanent(t *testing.T) {
	tk := task()
	tk.Attempts = 3
	_, h := run(t, &fakeAdapter{err: &apierr.APIError{Class: apierr.ClassTransient}}, tk)
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished[0].Status)
	assert.Equal(t, t0.Add(4*time.Minute), h.tasks.finished[0].NextRunAt, "base·2^(n−1)")

	_, h = run(t, &fakeAdapter{err: ErrUnknownResource}, task())
	assert.Equal(t, "invalid_task", h.tasks.finished[0].Label)

	_, h = run(t, &fakeAdapter{err: ErrNoCredentials}, task())
	assert.Equal(t, "no_credentials", h.tasks.finished[0].Label)
	assert.True(t, h.tasks.finished[0].KeepCursor)
}
