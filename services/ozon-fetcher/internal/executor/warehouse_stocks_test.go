package executor

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

func (f *fakeOzon) FetchWarehouseStocks(_ context.Context, creds ozon.Credentials, _ string, skus []int64) (ozon.Page, ozon.WarehouseStocks, error) {
	f.creds = append(f.creds, creds)
	f.whCalls = append(f.whCalls, append([]int64(nil), skus...))
	if f.whErr != nil && len(f.whCalls) > f.whFailAfter {
		return ozon.Page{}, ozon.WarehouseStocks{}, f.whErr
	}
	// Две строки складов на SKU, кроме последнего в порции (нигде не лежит).
	return ozon.Page{No: 1, Body: []byte(fmt.Sprintf(`{"items":"%v"}`, skus)), ContentType: "application/json"},
		ozon.WarehouseStocks{Rows: 2 * (len(skus) - 1), SKUs: len(skus) - 1, Warehouses: 2}, nil
}

var warehouseCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourceStocks, ResourceStocksWarehouses}
	return c
}()

// warehouseHarness — снимок ozon.stocks дня из страниц stocksPage (SKU = id·10).
func warehouseHarness(t *testing.T, o *fakeOzon, pages []ozon.Page) (*harness, *fakeReader) {
	t.Helper()
	h := newHarness(o)
	reader := &fakeReader{bodies: map[string]string{}}
	h.tasks.content = map[string][]store.RawObject{}
	if pages != nil {
		objs := []store.RawObject{}
		for i, p := range pages {
			uri := fmt.Sprintf("s3://raw/stocks/p%d", i+1)
			reader.bodies[uri] = string(p.Body)
			objs = append(objs, store.RawObject{PageNo: i + 1, StorageURI: uri, SHA256: sha(string(p.Body))})
		}
		h.tasks.content[ResourceStocks+"/"+today] = objs
	}
	h.exec = h.exec.WithRawReader(reader)
	return h, reader
}

// snapshot — снимок дня как его отдаёт Ozon: страница с товарами и концевая пустая.
func snapshot(total int, ids ...int64) []ozon.Page {
	return []ozon.Page{stocksPage(total, "C1", ids...), stocksPage(total, "")}
}

func skuKeys(skus []int64) []string {
	out := make([]string, len(skus))
	for i, s := range skus {
		out[i] = strconv.FormatInt(s, 10)
	}
	return out
}

func TestWarehouseStocksUseSnapshotSKUsSortedInChunks(t *testing.T) {
	var ids []int64
	for i := range 250 {
		ids = append(ids, int64(250-i)) // по убыванию — порядок задаёт исполнитель
	}
	o := &fakeOzon{}
	h, _ := warehouseHarness(t, o, []ozon.Page{stocksPage(251, "C1", ids[:200]...), stocksPage(251, "C2", append(ids[200:], 250)...), stocksPage(251, "")})

	res := h.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, o.whCalls, 3)
	assert.Len(t, o.whCalls[0], 100)
	assert.Len(t, o.whCalls[2], 50, "250 SKU, дубль один раз")
	assert.Equal(t, int64(10), o.whCalls[0][0], "по возрастанию")
	all := append(append(append([]int64(nil), o.whCalls[0]...), o.whCalls[1]...), o.whCalls[2]...)
	fp := numbersFingerprint(skuKeys(all))
	assert.Equal(t, []string{fp + ":100", fp + ":200", ""}, h.tasks.cursors)
	assert.Equal(t, ResourceStocksWarehouses, h.raw.puts[0].ResourceType)
	assert.Contains(t, h.logs.String(), `"msg":"warehouse stocks read"`)
	assert.Contains(t, h.logs.String(), `"skus":250,"requested":250,"skus_with_rows":247,"rows":494`)
}

func TestWarehouseStocksResumeAndRevisedSnapshot(t *testing.T) {
	var ids []int64
	for i := range 150 {
		ids = append(ids, int64(i+1))
	}
	skus := make([]int64, len(ids))
	for i, id := range ids {
		skus[i] = id * 10
	}
	o := &fakeOzon{}
	h, _ := warehouseHarness(t, o, snapshot(150, ids...))
	tk := task(ResourceStocksWarehouses, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = numbersFingerprint(skuKeys(skus))+":100", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, warehouseCabinet, "trace")
	assert.Equal(t, "done", res.Outcome)
	require.Len(t, o.whCalls, 1, "первая порция не перезапрашивается")
	assert.Equal(t, int64(1010), o.whCalls[0][0])
	assert.Equal(t, 2, h.tasks.pages[0].PageNo)

	o2 := &fakeOzon{}
	h2, _ := warehouseHarness(t, o2, snapshot(151, append(ids, 9999)...))
	res = h2.exec.Run(context.Background(), tk, warehouseCabinet, "trace")
	assert.Equal(t, "stocks_revised", res.Outcome)
	assert.Empty(t, o2.whCalls)
	assert.False(t, h2.tasks.finished.KeepCursor)

	for _, bad := range []string{"x", numbersFingerprint(skuKeys(skus)) + ":0", numbersFingerprint(skuKeys(skus)) + ":150"} {
		h3, _ := warehouseHarness(t, &fakeOzon{}, snapshot(150, ids...))
		tk3 := tk
		tk3.Cursor = bad
		assert.Equal(t, "stocks_revised", h3.exec.Run(context.Background(), tk3, warehouseCabinet, "trace").Outcome, bad)
	}
}

func TestWarehouseStocksFailureKeepsSavedChunkCursor(t *testing.T) {
	var ids []int64
	for i := range 150 {
		ids = append(ids, int64(i+1))
	}
	o := &fakeOzon{whErr: &ozon.APIError{Class: ozon.ClassRateLimit, Endpoint: ozon.EndpointWarehouseStocks, Status: 429}, whFailAfter: 1}
	h, _ := warehouseHarness(t, o, snapshot(150, ids...))

	res := h.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace")

	assert.Equal(t, "rate_limit", res.Outcome)
	require.Len(t, h.tasks.cursors, 1)
	assert.True(t, h.tasks.finished.KeepCursor)

	o2 := &fakeOzon{whErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointWarehouseStocks, Status: 400}, whFailAfter: 1}
	h2, _ := warehouseHarness(t, o2, snapshot(150, ids...))
	res = h2.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace")
	assert.Equal(t, "invalid_request", res.Outcome, "400 при своём курсоре порций — не stale_cursor")
	assert.Equal(t, store.StatusFailedPermanent, h2.tasks.finished.Status)
}

func TestWarehouseStocksWaitsForStocksOfTheDay(t *testing.T) {
	o := &fakeOzon{}
	h, _ := warehouseHarness(t, o, nil)

	res := h.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace")

	assert.Equal(t, "stocks_not_loaded", res.Outcome)
	assert.Equal(t, store.StatusNotReady, h.tasks.finished.Status)
	assert.Equal(t, t0.Add(DefaultConfig().NotReadyDelay[ResourceStocksWarehouses]), h.tasks.finished.NextRunAt)
	assert.Empty(t, o.whCalls)
}

func TestWarehouseStocksEmptySnapshotStoresMarkerWithoutRequest(t *testing.T) {
	o := &fakeOzon{}
	h, _ := warehouseHarness(t, o, []ozon.Page{stocksPage(0, "")})

	res := h.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, o.whCalls)
	require.Len(t, h.raw.puts, 1)
	assert.JSONEq(t, `{"items":[]}`, string(h.raw.puts[0].Body))
}

func TestWarehouseStocksDisabledDoNotCallOzon(t *testing.T) {
	for name, types := range map[string][]string{
		"not enabled":         {ResourceStocks},
		"without ozon.stocks": {ResourceStocksWarehouses},
	} {
		o := &fakeOzon{}
		h, _ := warehouseHarness(t, o, snapshot(1, 5))
		c := cabinet
		c.ResourceTypes = types
		res := h.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), c, "trace")
		assert.Equal(t, "resource_disabled", res.Outcome, name)
		assert.Empty(t, o.whCalls, name)
	}
}

func TestWarehouseStocksRawErrors(t *testing.T) {
	o := &fakeOzon{}
	h, reader := warehouseHarness(t, o, snapshot(1, 5))
	reader.err = errors.New("s3 down")
	assert.Equal(t, "store", h.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace").Outcome)

	h2, reader2 := warehouseHarness(t, o, snapshot(1, 5))
	reader2.err = fmt.Errorf("wrapped: %w", rawstore.ErrObjectNotFound)
	res := h2.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace")
	assert.Equal(t, "stocks_raw_invalid", res.Outcome)
	assert.Contains(t, h2.logs.String(), `"level":"ERROR"`)

	h3, _ := warehouseHarness(t, o, []ozon.Page{page(`{"items":[{"product_id":1}],"total":1,"cursor":""}`, "")})
	res = h3.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace")
	assert.Equal(t, "stocks_raw_invalid", res.Outcome, "товар без stocks")
	assert.Equal(t, store.StatusFailedPermanent, h3.tasks.finished.Status)
	assert.Empty(t, o.whCalls)
}

func TestWarehouseStocksSnapshotWindow(t *testing.T) {
	o := &fakeOzon{}
	h, _ := warehouseHarness(t, o, snapshot(1, 5))
	h.exec.now = func() time.Time { return msk(t, "2026-10-17 04:59") }
	assert.Equal(t, "snapshot_early", h.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace").Outcome)
	h.exec.now = func() time.Time { return msk(t, "2026-10-18 00:01") }
	assert.Equal(t, "snapshot_missed", h.exec.Run(context.Background(), task(ResourceStocksWarehouses, today), warehouseCabinet, "trace").Outcome)
	assert.Empty(t, o.whCalls)
}
