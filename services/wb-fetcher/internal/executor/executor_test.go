package executor

import (
	"bytes"
	"context"
	"log/slog"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/fetch"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

var t0 = time.Date(2026, 11, 2, 9, 0, 0, 0, time.UTC)

// fakeReport отдаёт страницы по очереди и запоминает запросы.
type fakeReport struct {
	pages []wb.ReportPage
	err   error
	reqs  []wb.ReportRequest
}

func (f *fakeReport) SalesReportPage(_ context.Context, r wb.ReportRequest) (wb.ReportPage, error) {
	f.reqs = append(f.reqs, r)
	if len(f.pages) == 0 {
		if f.err != nil {
			return wb.ReportPage{}, f.err
		}
		return wb.ReportPage{Body: []byte("[]")}, nil
	}
	p := f.pages[0]
	f.pages = f.pages[1:]
	return p, nil
}

func page(next int64, more bool) wb.ReportPage {
	return wb.ReportPage{Body: []byte(`[{"rrdId":` + strconv.FormatInt(next, 10) + `}]`), Rows: 1, NextRrdID: next, More: more}
}

type fakeTasks struct {
	mu       sync.Mutex
	pages    []store.RawObject
	cursors  []string
	finished []store.Outcome
}

func (f *fakeTasks) ExtendLease(context.Context, store.TaskKey, string, time.Time) error { return nil }
func (f *fakeTasks) RecordPage(_ context.Context, _ store.TaskKey, _ string, o store.RawObject, next string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.pages, f.cursors = append(f.pages, o), append(f.cursors, next)
	return nil
}
func (f *fakeTasks) FindObject(context.Context, store.TaskKey, string) (store.RawObject, bool, error) {
	return store.RawObject{}, false, nil
}
func (f *fakeTasks) FinishTask(_ context.Context, _ store.TaskKey, _ string, o store.Outcome, _ time.Time) (int, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.finished = append(f.finished, o)
	return 1, nil
}
func (f *fakeTasks) RecordAuthFailure(context.Context, string, time.Time, func(int) time.Duration) (int, error) {
	return 1, nil
}
func (f *fakeTasks) ResumeConnection(context.Context, string) error     { return nil }
func (f *fakeTasks) StopResource(context.Context, string, string) error { return nil }

type fakeRaw struct{ keys []string }

func (f *fakeRaw) Put(_ context.Context, o rawstore.Object) (rawstore.Stored, error) {
	k := rawstore.Key(o, strings.Repeat("a", 64))
	f.keys = append(f.keys, k)
	return rawstore.Stored{URI: "s3://b/" + k, Key: k}, nil
}

type noRefresh struct{}

func (noRefresh) ForceRefresh(context.Context) (bool, error) { return true, nil }

var cab = connections.Cabinet{Ref: "wb-1", CompanyID: "c1", Token: "tok", ResourceTypes: []string{ResourceDaily}}

func task(day string) store.Task {
	return store.Task{TaskKey: store.TaskKey{ConnectionRef: "wb-1", ResourceType: ResourceDaily, PeriodKey: day}, AttemptID: "att", Attempts: 1, NextPageNo: 1}
}

type harness struct {
	tasks *fakeTasks
	raw   *fakeRaw
	logs  *bytes.Buffer
}

func run(t *testing.T, rep *fakeReport, tk store.Task, c connections.Cabinet, mutate ...func(*Config)) (fetch.Result, *harness) {
	t.Helper()
	h := &harness{tasks: &fakeTasks{}, raw: &fakeRaw{}, logs: &bytes.Buffer{}}
	cfg := DefaultConfig()
	for _, m := range mutate {
		m(&cfg)
	}
	exec := New(cfg, h.tasks, h.raw, rep, noRefresh{}, slog.New(slog.NewJSONHandler(h.logs, nil)),
		func() time.Time { return t0 }, func(time.Duration) time.Duration { return 0 })
	return exec.Run(context.Background(), tk, c, "trace"), h
}

func TestShortPageIsWholeDay(t *testing.T) {
	rep := &fakeReport{pages: []wb.ReportPage{page(11, false)}}

	res, h := run(t, rep, task("2026-11-01"), cab)

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, rep.reqs, 1)
	r := rep.reqs[0]
	assert.Equal(t, wb.ReportRequest{ConnectionRef: "wb-1", Token: "tok", DateFrom: "2026-11-01", DateTo: "2026-11-01",
		Period: wb.PeriodDaily, RrdID: 0, Limit: wb.PageLimit}, r)
	require.Len(t, h.raw.keys, 1)
	assert.True(t, strings.HasPrefix(h.raw.keys[0], "wb/wb-1/wb.finance.sales-report.daily/2026-11-01/"), h.raw.keys[0])
	assert.Equal(t, []string{""}, h.tasks.cursors, "последняя страница — без курсора")
}

func TestFullPageContinuesFromLastRrdID(t *testing.T) {
	rep := &fakeReport{pages: []wb.ReportPage{page(100, true), page(150, false)}}

	res, h := run(t, rep, task("2026-11-01"), cab)

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, rep.reqs, 2)
	assert.Equal(t, int64(100), rep.reqs[1].RrdID)
	assert.Equal(t, []string{"100", ""}, h.tasks.cursors, "курсор пишется вместе со страницей")
	assert.Equal(t, 2, res.Pages)
}

func TestSavedCursorResumesAfterRestart(t *testing.T) {
	rep := &fakeReport{pages: []wb.ReportPage{page(300, false)}}
	tk := task("2026-11-01")
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "200", 3, []string{"h1", "h2"}

	res, h := run(t, rep, tk, cab)

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, rep.reqs, 1)
	assert.Equal(t, int64(200), rep.reqs[0].RrdID)
	require.Len(t, h.tasks.pages, 1)
	assert.Equal(t, 3, h.tasks.pages[0].PageNo, "нумерация страниц продолжается")
}

func TestAllPagesStoredButNotFinishedMakesNoRequest(t *testing.T) {
	rep := &fakeReport{}
	tk := task("2026-11-01")
	tk.PageHashes, tk.NextPageNo = []string{"h1"}, 2

	res, _ := run(t, rep, tk, cab)

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, rep.reqs)
}

func TestTooManyPagesIsPermanent(t *testing.T) {
	rep := &fakeReport{pages: []wb.ReportPage{page(1, true), page(2, true), page(3, true)}}

	_, h := run(t, rep, task("2026-11-01"), cab, func(c *Config) { c.MaxPages = 2 })

	require.Len(t, h.tasks.finished, 1)
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished[0].Status)
	assert.Equal(t, "too_many_pages", h.tasks.finished[0].ErrorClass)
	assert.Len(t, rep.reqs, 2)
}

func TestRateLimitWaitsForHeaderPause(t *testing.T) {
	rep := &fakeReport{err: &apierr.APIError{Source: wb.Source, Class: apierr.ClassRateLimit, Endpoint: wb.EndpointSalesReport, Status: 429, RetryAfter: 30 * time.Second}}

	_, h := run(t, rep, task("2026-11-01"), cab)

	out := h.tasks.finished[0]
	assert.Equal(t, store.StatusPending, out.Status)
	assert.Equal(t, t0.Add(30*time.Second), out.NextRunAt)
	assert.True(t, out.KeepCursor)
}

func TestSymfonyWindowDefersWithoutError(t *testing.T) {
	until := t0.Add(45 * time.Second)
	rep := &fakeReport{err: &WindowError{Until: until}}

	_, h := run(t, rep, task("2026-11-01"), cab)

	out := h.tasks.finished[0]
	assert.Equal(t, store.StatusPending, out.Status)
	assert.Equal(t, "symfony_window", out.Label)
	assert.Equal(t, until, out.NextRunAt)
	assert.True(t, out.KeepCursor)
	assert.NotContains(t, h.logs.String(), `"level":"ERROR"`)
}

func TestCabinetChecks(t *testing.T) {
	_, h := run(t, &fakeReport{}, task("2026-11-01"), connections.Cabinet{Ref: "wb-1", ResourceTypes: []string{ResourceDaily}})
	assert.Equal(t, "no_credentials", h.tasks.finished[0].Label)

	_, h = run(t, &fakeReport{}, task("2026-11-01"), connections.Cabinet{Ref: "wb-1", Token: "tok"})
	assert.Equal(t, "resource_disabled", h.tasks.finished[0].Label)

	_, h = run(t, &fakeReport{}, task("01.11.2026"), cab)
	assert.Equal(t, "invalid_task", h.tasks.finished[0].Label)

	tk := task("2026-11-01")
	tk.ResourceType = "wb.unknown"
	_, h = run(t, &fakeReport{}, tk, connections.Cabinet{Ref: "wb-1", Token: "tok", ResourceTypes: []string{"wb.unknown"}})
	assert.Equal(t, "invalid_task", h.tasks.finished[0].Label)
}

func TestTokenNotInLogs(t *testing.T) {
	_, h := run(t, &fakeReport{pages: []wb.ReportPage{page(1, false)}}, task("2026-11-01"),
		connections.Cabinet{Ref: "wb-1", Token: "super-secret", ResourceTypes: []string{ResourceDaily}})

	assert.NotContains(t, h.logs.String(), "super-secret")
}
