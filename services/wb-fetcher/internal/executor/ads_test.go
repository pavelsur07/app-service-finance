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

type fakeAds struct {
	err  error
	days []string
}

func (f *fakeAds) Day(_ context.Context, ref, token, day string) (wb.AdsDay, error) {
	f.days = append(f.days, ref+" "+token+" "+day)
	if f.err != nil {
		return wb.AdsDay{}, f.err
	}
	return wb.AdsDay{Body: []byte(`{"schema":"wb-ads-daily-v1","expenses":[],"statistics":[]}`)}, nil
}

var adsCab = connections.Cabinet{Ref: "wb-1", CompanyID: "c1", Token: "tok", ResourceTypes: []string{ResourceDaily, ResourceAds}}

func adsTask(lastError string) store.Task {
	return store.Task{TaskKey: store.TaskKey{ConnectionRef: "wb-1", ResourceType: ResourceAds, PeriodKey: "2026-11-10"},
		AttemptID: "att", Attempts: 1, NextPageNo: 1, LastErrorClass: lastError}
}

func runAds(t *testing.T, ads *fakeAds, tk store.Task, c connections.Cabinet) (fetch.Result, *harness) {
	t.Helper()
	h := &harness{tasks: &fakeTasks{}, raw: &fakeRaw{}, logs: &bytes.Buffer{}}
	exec := New(DefaultConfig(), h.tasks, h.raw, &fakeReport{}, noRefresh{}, slog.New(slog.NewJSONHandler(h.logs, nil)),
		func() time.Time { return t0 }, func(time.Duration) time.Duration { return 0 }).WithAds(ads)
	return exec.Run(context.Background(), tk, c, "trace"), h
}

func TestAdsDayIsOnePage(t *testing.T) {
	ads := &fakeAds{}

	res, h := runAds(t, ads, adsTask(""), adsCab)

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"wb-1 tok 2026-11-10"}, ads.days)
	require.Len(t, h.raw.keys, 1)
	assert.True(t, strings.HasPrefix(h.raw.keys[0], "wb/wb-1/wb.ads.daily/2026-11-10/"), h.raw.keys[0])
}

func TestAdsAuthPausesOnlyTheTask(t *testing.T) {
	ads := &fakeAds{err: &apierr.APIError{Source: wb.Source, Class: apierr.ClassAuth, Endpoint: wb.EndpointAdsExpenses, Status: 401}}

	_, h := runAds(t, ads, adsTask(""), adsCab)
	out := h.tasks.finished[0]
	assert.Equal(t, store.StatusPending, out.Status)
	assert.Equal(t, t0.Add(DefaultConfig().AuthRetryPause), out.NextRunAt)
	assert.NotContains(t, h.logs.String(), `"level":"ERROR"`)

	_, h = runAds(t, ads, adsTask(string(apierr.ClassAuth)), adsCab)
	assert.Equal(t, t0.Add(DefaultConfig().AuthSuspend), h.tasks.finished[0].NextRunAt)
	assert.Contains(t, h.logs.String(), "ads task paused", "второй отказ подряд — ERROR")
}

func TestAdsNeedsResourceAndAdsDoNotResumeCabinet(t *testing.T) {
	_, h := runAds(t, &fakeAds{}, adsTask(""), cab)
	assert.Equal(t, "resource_disabled", h.tasks.finished[0].Label)

	e := New(DefaultConfig(), &fakeTasks{}, &fakeRaw{}, &fakeReport{}, noRefresh{}, slog.Default(), time.Now, nil)
	assert.False(t, e.ResumesConnection(ResourceAds))
	assert.True(t, e.ResumesConnection(ResourceDaily))
}
