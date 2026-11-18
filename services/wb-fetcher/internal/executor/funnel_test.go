package executor

import (
	"bytes"
	"context"
	"log/slog"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/fetch"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

type fakeFunnel struct {
	err  error
	days []string
}

func (f *fakeFunnel) FunnelDay(_ context.Context, ref, token, day string) (wb.FunnelDay, error) {
	f.days = append(f.days, ref+" "+token+" "+day)
	if f.err != nil {
		return wb.FunnelDay{}, f.err
	}
	return wb.FunnelDay{Body: []byte(`{"schema":"wb-funnel-daily-v1","currency":"RUB","products":[]}`), Products: 3}, nil
}

func (f *fakeFunnel) SearchDay(_ context.Context, ref, token, day string) (wb.FunnelDay, error) {
	f.days = append(f.days, "search "+ref+" "+token+" "+day)
	return wb.FunnelDay{Body: []byte(`{"schema":"wb-search-daily-v1","currency":"RUB","products":[]}`), Products: 2}, f.err
}

func TestSearchDayIsOnePageWithOwnAccess(t *testing.T) {
	c := funnelCab
	c.ResourceTypes = []string{ResourceDaily, ResourceSearch}
	tk := funnelTask("")
	tk.ResourceType = ResourceSearch
	f := &fakeFunnel{}

	res, h := runFunnel(t, f, tk, c)

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"search wb-1 tok 2026-11-17"}, f.days)
	require.Len(t, h.raw.keys, 1)
	assert.True(t, strings.HasPrefix(h.raw.keys[0], "wb/wb-1/wb.analytics.search/2026-11-17/"), h.raw.keys[0])
	assert.True(t, ownAccess(ResourceSearch), "отказ в доступе — пауза только задачи")

	_, h = runFunnel(t, &fakeFunnel{}, tk, funnelCab)
	assert.Empty(t, h.raw.keys, "ресурс кабинету не выдан")
}

var funnelCab = connections.Cabinet{Ref: "wb-1", CompanyID: "c1", Token: "tok", ResourceTypes: []string{ResourceDaily, ResourceFunnel}}

func funnelTask(lastError string) store.Task {
	return store.Task{TaskKey: store.TaskKey{ConnectionRef: "wb-1", ResourceType: ResourceFunnel, PeriodKey: "2026-11-17"},
		AttemptID: "att", Attempts: 1, NextPageNo: 1, LastErrorClass: lastError}
}

func runFunnel(t *testing.T, f *fakeFunnel, tk store.Task, c connections.Cabinet) (fetch.Result, *harness) {
	t.Helper()
	h := &harness{tasks: &fakeTasks{}, raw: &fakeRaw{}, logs: &bytes.Buffer{}}
	exec := New(DefaultConfig(), h.tasks, h.raw, &fakeReport{}, noRefresh{}, slog.New(slog.NewJSONHandler(h.logs, nil)),
		func() time.Time { return t0 }, func(time.Duration) time.Duration { return 0 }).WithFunnel(f)
	return exec.Run(context.Background(), tk, c, "trace"), h
}

func TestFunnelDayIsOnePage(t *testing.T) {
	f := &fakeFunnel{}

	res, h := runFunnel(t, f, funnelTask(""), funnelCab)

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"wb-1 tok 2026-11-17"}, f.days)
	require.Len(t, h.raw.keys, 1)
	assert.True(t, strings.HasPrefix(h.raw.keys[0], "wb/wb-1/wb.analytics.funnel/2026-11-17/"), h.raw.keys[0])
	assert.Contains(t, h.logs.String(), `"products":3`)
	assert.NotContains(t, h.logs.String(), "tok", "токен не попадает в журнал")
}

func TestFunnelAuthPausesOnlyTheTask(t *testing.T) {
	// Токен без категории «Аналитика»: финансы кабинета не останавливаются.
	f := &fakeFunnel{err: &apierr.APIError{Source: wb.Source, Class: apierr.ClassAuth, Endpoint: wb.EndpointFunnel, Status: 403}}

	_, h := runFunnel(t, f, funnelTask(""), funnelCab)
	out := h.tasks.finished[0]
	assert.Equal(t, store.StatusPending, out.Status)
	assert.Equal(t, t0.Add(DefaultConfig().AuthRetryPause), out.NextRunAt)
	assert.NotContains(t, h.logs.String(), `"level":"ERROR"`)

	_, h = runFunnel(t, f, funnelTask(string(apierr.ClassAuth)), funnelCab)
	assert.Equal(t, t0.Add(DefaultConfig().AuthSuspend), h.tasks.finished[0].NextRunAt)
}

func TestFunnelNeedsResourceAndDoesNotResumeCabinet(t *testing.T) {
	f := &fakeFunnel{}
	_, h := runFunnel(t, f, funnelTask(""), cab)
	assert.Equal(t, "resource_disabled", h.tasks.finished[0].Label)
	assert.Empty(t, f.days)

	e := New(DefaultConfig(), &fakeTasks{}, &fakeRaw{}, &fakeReport{}, noRefresh{}, slog.Default(), time.Now, nil)
	assert.False(t, e.ResumesConnection(ResourceFunnel))
}
