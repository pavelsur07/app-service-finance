package executor

import (
	"context"
	"fmt"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

func (f *fakeOzon) refPage(call string) (ozon.Page, error) {
	f.refCalls = append(f.refCalls, call)
	if err := f.refErr[call]; err != nil {
		return ozon.Page{}, err
	}
	return ozon.Page{No: 1, Body: []byte(fmt.Sprintf(`{"call":%q}`, call)), ContentType: "application/json"}, nil
}

func (f *fakeOzon) FetchActions(_ context.Context, _ ozon.Credentials, _ string) (ozon.Page, []int64, error) {
	p, err := f.refPage("actions")
	return p, f.actionIDs, err
}

func (f *fakeOzon) FetchActionProducts(_ context.Context, _ ozon.Credentials, _ string, candidates bool, actionID int64, lastID string) (ozon.Page, ozon.ActionProductsPage, error) {
	key := fmt.Sprintf("%d:%t:%s", actionID, candidates, lastID)
	p, err := f.refPage("products " + key)
	n := f.productsN[key]
	if f.productsNoLast != nil {
		if total, ok := f.productsNoLast[key]; ok {
			return p, ozon.ActionProductsPage{Products: n, Total: total}, err
		}
	}
	return p, ozon.ActionProductsPage{Products: n, Total: n, LastID: lastID + "x"}, err
}

func (f *fakeOzon) FetchSupplyOrderList(_ context.Context, _ ozon.Credentials, _, lastID string) (ozon.Page, []int64, string, error) {
	p, err := f.refPage("supply list " + lastID)
	return p, f.supplyPages[lastID], f.supplyNext[lastID], err
}

func (f *fakeOzon) FetchSupplyOrders(_ context.Context, _ ozon.Credentials, _ string, ids []int64) (ozon.Page, []string, error) {
	p, err := f.refPage(fmt.Sprintf("supply get %d", len(ids)))
	if f.supplyGetErr != nil {
		return ozon.Page{}, nil, f.supplyGetErr
	}
	var b []string
	for _, id := range ids {
		b = append(b, f.bundles[id]...)
	}
	return p, b, err
}

func (f *fakeOzon) FetchSupplyBundle(_ context.Context, _ ozon.Credentials, _ string, bundleIDs []string, lastID string) (ozon.Page, ozon.SupplyBundlePage, error) {
	p, err := f.refPage(fmt.Sprintf("bundle %v %s", bundleIDs, lastID))
	if f.bundleErr != nil {
		return ozon.Page{}, ozon.SupplyBundlePage{}, f.bundleErr
	}
	if lastID == "" && f.bundleTwoPages[bundleIDs[0]] {
		return p, ozon.SupplyBundlePage{Items: 3, HasNext: true, LastID: "P2"}, err
	}
	return p, ozon.SupplyBundlePage{Items: 3}, err
}

func (f *fakeOzon) FetchClusters(_ context.Context, _ ozon.Credentials, _, clusterType string) (ozon.Page, ozon.ClustersPage, error) {
	p, err := f.refPage("clusters " + clusterType)
	return p, ozon.ClustersPage{Clusters: 2, Warehouses: 10}, err
}

func refCabinet(resources ...string) connections.Cabinet {
	c := cabinet
	c.ResourceTypes = resources
	return c
}

func TestActionsSnapshotWalksEveryActionUntilEmptyPage(t *testing.T) {
	o := &fakeOzon{actionIDs: []int64{9, 3}, productsN: map[string]int{"3:false:": 2, "9:true:": 1}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceActions, today), refCabinet(ResourceActions), "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{
		"actions",
		"products 3:false:", "products 3:false:x", "products 3:true:",
		"products 9:false:", "products 9:true:", "products 9:true:x",
	}, o.refCalls, "акции по возрастанию id, участники и кандидаты до пустой страницы")
	require.Len(t, h.tasks.cursors, 7)
	assert.Equal(t, referenceMore, h.tasks.cursors[0])
	assert.Equal(t, "", h.tasks.cursors[6], "последняя страница — без курсора")
	assert.Equal(t, 7, h.tasks.pages[6].PageNo)
	assert.Contains(t, h.logs.String(), `"msg":"reference snapshot read"`)
	assert.Contains(t, h.logs.String(), `"pages":7,"actions":2,"candidates":1,"participants":2`)
}

func TestSuppliesSnapshotListDetailsAndBundles(t *testing.T) {
	var many []int64
	for i := range 60 {
		many = append(many, int64(100+i))
	}
	o := &fakeOzon{
		supplyPages: map[string][]int64{"": many[:30], "L1": append(many[30:], 100)},
		supplyNext:  map[string]string{"": "L1"},
		bundles:     map[int64][]string{100: {"b2"}, 159: {"b1", "b2"}},
	}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceSupplies, today), refCabinet(ResourceSupplies), "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"supply list ", "supply list L1", "supply get 50", "supply get 10", "bundle [b1] ", "bundle [b2] "}, o.refCalls,
		"состав — по одной поставке: вместе Ozon сводит позиции по SKU")
	assert.Contains(t, h.logs.String(), `"bundles":2,"items":6,"orders":60`)
}

func TestSuppliesMissingBundleRestartsSnapshot(t *testing.T) {
	o := &fakeOzon{supplyPages: map[string][]int64{"": {1}}, bundles: map[int64][]string{1: {"b"}},
		bundleErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointSupplyOrderBundle, Status: 400, Code: 9, Message: "Bundles b not found"}}
	defer func() {
		// Прочие 400 состава — постоянная ошибка, не снимок заново.
		o2 := &fakeOzon{supplyPages: map[string][]int64{"": {1}}, bundles: map[int64][]string{1: {"b"}},
			bundleErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointSupplyOrderBundle, Status: 400, Code: 3, Message: "invalid limit"}}
		h2 := newHarness(o2)
		assert.Equal(t, "invalid_request", h2.exec.Run(context.Background(), task(ResourceSupplies, today), refCabinet(ResourceSupplies), "trace").Outcome)

		// Заявка пропала между списком и деталями — снимок заново.
		o3 := &fakeOzon{supplyPages: map[string][]int64{"": {1}}, supplyGetErr: fmt.Errorf("%w: 1 of 1", ozon.ErrSupplyOrderMissing)}
		h3 := newHarness(o3)
		assert.Equal(t, "snapshot_restart", h3.exec.Run(context.Background(), task(ResourceSupplies, today), refCabinet(ResourceSupplies), "trace").Outcome)
	}()
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceSupplies, today), refCabinet(ResourceSupplies), "trace")

	assert.Equal(t, "snapshot_restart", res.Outcome)
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
	assert.False(t, h.tasks.finished.KeepCursor, "снимок заново, сохранённые страницы сбрасываются")
}

func TestReferenceSnapshotInterruptedStartsOver(t *testing.T) {
	o := &fakeOzon{refErr: map[string]error{"clusters CLUSTER_TYPE_CIS": &ozon.APIError{Class: ozon.ClassRateLimit, Endpoint: ozon.EndpointClusters, Status: 429}}}
	h := newHarness(o)
	res := h.exec.Run(context.Background(), task(ResourceClusters, today), refCabinet(ResourceClusters), "trace")
	assert.Equal(t, "rate_limit", res.Outcome)
	assert.Empty(t, h.tasks.cursors, "страница сохраняется с приходом следующей: обрыв на второй — ничего не сохранено")

	// Следующая попытка с курсором — не продолжение, а снимок заново.
	o2 := &fakeOzon{}
	h2 := newHarness(o2)
	tk := task(ResourceClusters, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = referenceMore, 2, []string{"h1"}
	res = h2.exec.Run(context.Background(), tk, refCabinet(ResourceClusters), "trace")
	assert.Equal(t, "snapshot_restart", res.Outcome)
	assert.False(t, h2.tasks.finished.KeepCursor)
	assert.Empty(t, o2.refCalls)

	// Все страницы сохранены, итог не записан — без запросов.
	tk.Cursor = ""
	h3 := newHarness(&fakeOzon{})
	assert.Equal(t, "done", h3.exec.Run(context.Background(), tk, refCabinet(ResourceClusters), "trace").Outcome)
}

func TestReferenceSnapshotsWindowAndGating(t *testing.T) {
	for _, resource := range []string{ResourceActions, ResourceSupplies, ResourceClusters} {
		o := &fakeOzon{}
		h := newHarness(o)
		h.exec.now = func() time.Time { return msk(t, "2026-10-17 04:59") }
		assert.Equal(t, "snapshot_early", h.exec.Run(context.Background(), task(resource, "2026-10-17"), refCabinet(resource), "trace").Outcome, resource)
		h.exec.now = func() time.Time { return msk(t, "2026-10-18 00:01") }
		assert.Equal(t, "snapshot_missed", h.exec.Run(context.Background(), task(resource, "2026-10-17"), refCabinet(resource), "trace").Outcome, resource)
		h.exec.now = func() time.Time { return t0 }
		assert.Equal(t, "resource_disabled", h.exec.Run(context.Background(), task(resource, today), cabinet, "trace").Outcome, resource)
		assert.Empty(t, o.refCalls, resource)
	}
}

func TestClustersSnapshotBothTypes(t *testing.T) {
	o := &fakeOzon{}
	h := newHarness(o)
	assert.Equal(t, "done", h.exec.Run(context.Background(), task(ResourceClusters, today), refCabinet(ResourceClusters), "trace").Outcome)
	assert.Equal(t, []string{"clusters CLUSTER_TYPE_OZON", "clusters CLUSTER_TYPE_CIS"}, o.refCalls)
	assert.Contains(t, h.logs.String(), `"pages":2,"clusters":4,"warehouses":20`)
}

func TestActionsEndWithoutLastIDNeedsTotal(t *testing.T) {
	o := &fakeOzon{actionIDs: []int64{3}, productsN: map[string]int{"3:false:": 2}, productsNoLast: map[string]int{"3:false:": 2}}
	h := newHarness(o)
	assert.Equal(t, "done", h.exec.Run(context.Background(), task(ResourceActions, today), refCabinet(ResourceActions), "trace").Outcome)
	assert.Equal(t, []string{"actions", "products 3:false:", "products 3:true:"}, o.refCalls, "набран total — конец без пустой страницы")

	o2 := &fakeOzon{actionIDs: []int64{3}, productsN: map[string]int{"3:false:": 2}, productsNoLast: map[string]int{"3:false:": 5}}
	h2 := newHarness(o2)
	assert.Equal(t, "invalid_response", h2.exec.Run(context.Background(), task(ResourceActions, today), refCabinet(ResourceActions), "trace").Outcome, "2 из 5 без last_id — обрезка")

	o3 := &fakeOzon{actionIDs: []int64{3}, productsN: map[string]int{"3:false:": 4}, productsNoLast: map[string]int{"3:false:": 2}}
	h3 := newHarness(o3)
	assert.Equal(t, "invalid_response", h3.exec.Run(context.Background(), task(ResourceActions, today), refCabinet(ResourceActions), "trace").Outcome, "4 при total 2 — перекрытие страниц")
}

func TestSuppliesBundlePagesResetPerSupply(t *testing.T) {
	o := &fakeOzon{supplyPages: map[string][]int64{"": {1}}, bundles: map[int64][]string{1: {"b1", "b2"}}, bundleTwoPages: map[string]bool{"b1": true}}
	h := newHarness(o)
	assert.Equal(t, "done", h.exec.Run(context.Background(), task(ResourceSupplies, today), refCabinet(ResourceSupplies), "trace").Outcome)
	assert.Equal(t, []string{"supply list ", "supply get 1", "bundle [b1] ", "bundle [b1] P2", "bundle [b2] "}, o.refCalls,
		"вторая страница первой поставки — по её last_id, следующая поставка — с пустого")
}
