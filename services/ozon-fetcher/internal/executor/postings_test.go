package executor

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

func TestPostingResourcesMapToScheme(t *testing.T) {
	for resource, want := range map[string]ozon.PostingScheme{ResourceOrdersFBO: ozon.SchemeFBO, ResourceOrdersFBS: ozon.SchemeFBS} {
		t.Run(resource, func(t *testing.T) {
			h := newHarness(&fakeOzon{pages: twoPageDay()})

			res := h.exec.Run(context.Background(), task(resource, "2026-10-19"), cabinet, "trace")

			assert.Equal(t, "done", res.Outcome)
			require.Len(t, h.ozon.postingCalls, 1)
			assert.Equal(t, want, h.ozon.postingCalls[0].scheme)
			assert.True(t, IsPostingResource(resource))
		})
	}
	assert.False(t, IsPostingResource(ResourceAccrualByDay))
}

func TestPostingWindowIsMoscowDay(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})

	h.exec.Run(context.Background(), task(ResourceOrdersFBO, "2026-10-19"), cabinet, "trace")

	require.Len(t, h.ozon.postingCalls, 1)
	w := h.ozon.postingCalls[0].window
	assert.Equal(t, time.Date(2026, 10, 18, 21, 0, 0, 0, time.UTC), w.From.UTC())
	assert.Equal(t, time.Date(2026, 10, 19, 21, 0, 0, 0, time.UTC), w.To.UTC())
}

func TestPostingPagesStoredWithCursorAndRawKey(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})

	h.exec.Run(context.Background(), task(ResourceOrdersFBS, "2026-10-19"), cabinet, "trace")

	require.Len(t, h.tasks.pages, 2)
	assert.Equal(t, []string{"c1", ""}, h.tasks.cursors)
	require.Len(t, h.raw.puts, 2)
	assert.Equal(t, ResourceOrdersFBS, h.raw.puts[0].ResourceType)
	assert.Equal(t, "2026-10-19", h.raw.puts[0].PeriodKey)
	assert.Equal(t, "ozon", h.raw.puts[0].Provider)
	assert.Equal(t, store.StatusDone, h.tasks.finished.Status)
}

func TestPostingResumeContinuesFromSavedPage(t *testing.T) {
	o := &fakeOzon{pages: twoPageDay()}
	h := newHarness(o)
	h.raw.fail = map[int]error{2: errors.New("s3 down")}
	first := h.exec.Run(context.Background(), task(ResourceOrdersFBO, "2026-10-19"), cabinet, "trace")
	require.Equal(t, "store", first.Outcome)
	require.True(t, h.tasks.finished.KeepCursor)

	h2 := newHarness(o)
	resumed := task(ResourceOrdersFBO, "2026-10-19")
	resumed.Cursor, resumed.NextPageNo, resumed.PageHashes = "c1", 2, []string{h.tasks.pages[0].SHA256}
	h2.exec.Run(context.Background(), resumed, cabinet, "trace")

	last := o.postingCalls[len(o.postingCalls)-1]
	assert.Equal(t, "c1", last.cursor)
	assert.Equal(t, 2, last.firstPage)
	require.Len(t, h2.tasks.pages, 1, "страница 1 не загружается повторно")
	assert.Equal(t, contentHash([]string{sha(`{"p":1}`), sha(`{"p":2}`)}), h2.tasks.finished.ContentHash)
}

func TestPostingAllPagesAlreadySavedIsNotRefetched(t *testing.T) {
	o := &fakeOzon{pages: twoPageDay()}
	h := newHarness(o)
	tk := task(ResourceOrdersFBO, "2026-10-19")
	tk.PageHashes = []string{sha(`{"p":1}`), sha(`{"p":2}`)}

	res := h.exec.Run(context.Background(), tk, cabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, o.postingCalls)
}

func TestPostingUnchangedAndRevised(t *testing.T) {
	same := task(ResourceOrdersFBO, "2026-10-19")
	same.ContentHash = contentHash([]string{sha(`{"p":1}`), sha(`{"p":2}`)})
	h := newHarness(&fakeOzon{pages: twoPageDay()})
	assert.Equal(t, "unchanged", h.exec.Run(context.Background(), same, cabinet, "trace").Outcome)

	changed := task(ResourceOrdersFBO, "2026-10-19")
	changed.ContentHash, changed.Revision = sha("old"), 1
	h2 := newHarness(&fakeOzon{pages: twoPageDay()})
	h2.tasks.revision = 2
	res := h2.exec.Run(context.Background(), changed, cabinet, "trace")
	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, 2, res.Revision)
}

func TestPostingObsoleteStopsOnlyThatResource(t *testing.T) {
	h := newHarness(&fakeOzon{postingErr: &ozon.APIError{Class: ozon.ClassObsoleteEndpoint, Endpoint: ozon.EndpointPostingFBOList, Status: 400, Code: 9, Message: "obsolete method cannot be used"}})

	res := h.exec.Run(context.Background(), task(ResourceOrdersFBO, "2026-10-19"), cabinet, "trace")

	assert.Equal(t, "obsolete_endpoint", res.Outcome)
	assert.Equal(t, []string{ResourceOrdersFBO}, h.tasks.stopped)
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status)
	assert.Contains(t, h.logs.String(), "obsolete method cannot be used", "текст ошибки Ozon — в логе задачи")
}

func TestPostingInvalidRequestIsPermanentWithoutStop(t *testing.T) {
	h := newHarness(&fakeOzon{postingErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointPostingFBSList, Status: 400, Code: 3, Message: "filter.since and filter.to must not be empty"}})

	res := h.exec.Run(context.Background(), task(ResourceOrdersFBS, "2026-10-19"), cabinet, "trace")

	assert.Equal(t, "invalid_request", res.Outcome)
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status)
	assert.Empty(t, h.tasks.stopped, "метод не снят — ресурс не останавливается для всех")
}

func TestPostingBadPeriodIsInvalidTask(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})

	res := h.exec.Run(context.Background(), task(ResourceOrdersFBO, "2026-10"), cabinet, "trace")

	assert.Equal(t, "invalid_task", res.Outcome)
	assert.Empty(t, h.ozon.postingCalls)
}
