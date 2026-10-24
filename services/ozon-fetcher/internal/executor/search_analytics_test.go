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

func (f *fakeOzon) FetchSearchPage(_ context.Context, creds ozon.Credentials, _ string, details bool, day time.Time, skus []int64, page int) (ozon.Page, ozon.SearchPage, error) {
	f.creds = append(f.creds, creds)
	f.searchCalls = append(f.searchCalls, searchCall{details: details, day: day.Format(time.DateOnly), firstSKU: skus[0], skus: len(skus), page: page})
	stats, err := f.searchFn(details, day, skus, page)
	if err != nil {
		return ozon.Page{}, ozon.SearchPage{}, err
	}
	if f.searchSameBody {
		page = 0 // Ozon отдаёт одну и ту же страницу
	}
	array := "items"
	if details {
		array = "queries"
	}
	// Форма живого ответа без строк; total различает страницы (хеш).
	body := fmt.Sprintf(`{%q:[],"total":%d,"page_count":%d,"analytics_period":{}}`, array, skus[0]*1000+int64(page), stats.PageCount)
	return ozon.Page{No: 1, Body: []byte(body), ContentType: "application/json"}, stats, nil
}

var searchCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourceStocks, ResourceSearchQueries, ResourceSearchDetails}
	return c
}()

// t0 — 17.10 12:00 МСК: D−2 = 15.10.
const searchDay = "2026-10-15"

// pagesBy — page_count по первой SKU порции, по 10 строк на страницу.
func pagesBy(counts map[int64]int) func(bool, time.Time, []int64, int) (ozon.SearchPage, error) {
	return func(_ bool, _ time.Time, skus []int64, _ int) (ozon.SearchPage, error) {
		n := counts[skus[0]]
		rows := 10
		if n == 0 {
			rows = 0
		}
		return ozon.SearchPage{Rows: rows, SKUs: rows, PageCount: n, Total: n * 10}, nil
	}
}

func searchSKUs(n int) (ids, skus []int64) {
	for i := range n {
		ids = append(ids, int64(n-i)) // по убыванию — порядок задаёт исполнитель
	}
	for i := range n {
		skus = append(skus, int64(i+1)*10)
	}
	return ids, skus
}

func TestSearchAnalyticsChunksAndPages(t *testing.T) {
	ids, skus := searchSKUs(1200)
	o := &fakeOzon{searchFn: pagesBy(map[int64]int{10: 2, 10010: 1})}
	h, _ := warehouseHarness(t, o, snapshot(1200, ids...))

	res := h.exec.Run(context.Background(), task(ResourceSearchDetails, searchDay), searchCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, o.searchCalls, 3)
	assert.Equal(t, searchCall{details: true, day: searchDay, firstSKU: 10, skus: 1000, page: 0}, o.searchCalls[0])
	assert.Equal(t, 1, o.searchCalls[1].page)
	assert.Equal(t, searchCall{details: true, day: searchDay, firstSKU: 10010, skus: 200, page: 0}, o.searchCalls[2])
	fp := numbersFingerprint(skuKeys(skus))
	assert.Equal(t, []string{fp + ":0:1:2", fp + ":1:0:0", fp + ":end"}, h.tasks.cursors)
	assert.Equal(t, ResourceSearchDetails, h.raw.puts[0].ResourceType)
	assert.Contains(t, h.logs.String(), `"skus":1200,"pages":3,"rows":30`)
	assert.NotContains(t, h.logs.String(), `"level":"WARN"`)
}

func TestSearchAnalyticsResumesFromSavedPage(t *testing.T) {
	ids, skus := searchSKUs(1200)
	fp := numbersFingerprint(skuKeys(skus))
	o := &fakeOzon{searchFn: pagesBy(map[int64]int{10: 2, 10010: 1})}
	h, _ := warehouseHarness(t, o, snapshot(1200, ids...))
	tk := task(ResourceSearchQueries, searchDay)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = fp+":0:1:2", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, searchCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, o.searchCalls, 2)
	assert.Equal(t, searchCall{details: false, day: searchDay, firstSKU: 10, skus: 1000, page: 1}, o.searchCalls[0])
	assert.Equal(t, int64(10010), o.searchCalls[1].firstSKU)

	// Все страницы сохранены, итог не записан: тот же набор — done без запросов.
	o2 := &fakeOzon{searchFn: pagesBy(nil)}
	h2, _ := warehouseHarness(t, o2, snapshot(1200, ids...))
	tk.Cursor = fp + ":end"
	assert.Equal(t, "done", h2.exec.Run(context.Background(), tk, searchCabinet, "trace").Outcome)
	assert.Empty(t, o2.searchCalls)

	// Снимок остатков другой — день заново.
	h3, _ := warehouseHarness(t, &fakeOzon{searchFn: pagesBy(nil)}, snapshot(3, 1, 2, 3))
	res = h3.exec.Run(context.Background(), tk, searchCabinet, "trace")
	assert.Equal(t, "stocks_revised", res.Outcome)
	assert.False(t, h3.tasks.finished.KeepCursor)

	for _, bad := range []string{fp + ":5:0:0", fp + ":0:2:2", fp + ":0:1:0", "x:0:0:0", fp + ":0:0"} {
		h4, _ := warehouseHarness(t, &fakeOzon{searchFn: pagesBy(nil)}, snapshot(1200, ids...))
		tk4 := tk
		tk4.Cursor = bad
		assert.Equal(t, "stocks_revised", h4.exec.Run(context.Background(), tk4, searchCabinet, "trace").Outcome, bad)
	}
}

func TestSearchAnalyticsPaginationGuards(t *testing.T) {
	cases := map[string]struct {
		fn      func(bool, time.Time, []int64, int) (ozon.SearchPage, error)
		outcome string
	}{
		"page count changed": {func(_ bool, _ time.Time, _ []int64, page int) (ozon.SearchPage, error) {
			return ozon.SearchPage{Rows: 10, PageCount: 2 + page, Total: 30}, nil
		}, "search_shifted"},
		"empty page before end": {func(_ bool, _ time.Time, _ []int64, page int) (ozon.SearchPage, error) {
			return ozon.SearchPage{Rows: 10 * (1 - page), PageCount: 3, Total: 30}, nil
		}, "invalid_response"},
		"rows with zero page count": {func(bool, time.Time, []int64, int) (ozon.SearchPage, error) {
			return ozon.SearchPage{Rows: 5, PageCount: 0}, nil
		}, "invalid_response"},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			h, _ := warehouseHarness(t, &fakeOzon{searchFn: tc.fn}, snapshot(2, 1, 2))
			res := h.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), searchCabinet, "trace")
			assert.Equal(t, tc.outcome, res.Outcome)
		})
	}

	// Сдвиг page_count после рестарта: сохранённая страница — от другой выдачи.
	_, skus := searchSKUs(2)
	h, _ := warehouseHarness(t, &fakeOzon{searchFn: pagesBy(map[int64]int{10: 5})}, snapshot(2, 2, 1))
	tk := task(ResourceSearchQueries, searchDay)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = numbersFingerprint(skuKeys(skus))+":0:1:2", 2, []string{"h1"}
	assert.Equal(t, "search_shifted", h.exec.Run(context.Background(), tk, searchCabinet, "trace").Outcome)
}

func TestSearchAnalyticsEmptyDayAndMismatchedTotal(t *testing.T) {
	o := &fakeOzon{searchFn: pagesBy(map[int64]int{})}
	h, _ := warehouseHarness(t, o, snapshot(2, 1, 2))
	res := h.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), searchCabinet, "trace")
	assert.Equal(t, "done", res.Outcome, "день без данных у товаров — page_count 0")
	require.Len(t, o.searchCalls, 1)
	assert.Equal(t, []string{numbersFingerprint(skuKeys([]int64{10, 20})) + ":end"}, h.tasks.cursors)

	o2 := &fakeOzon{searchFn: func(bool, time.Time, []int64, int) (ozon.SearchPage, error) {
		return ozon.SearchPage{Rows: 10, PageCount: 1, Total: 12}, nil
	}}
	h2, _ := warehouseHarness(t, o2, snapshot(2, 1, 2))
	assert.Equal(t, "done", h2.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), searchCabinet, "trace").Outcome)
	assert.Contains(t, h2.logs.String(), `"msg":"search analytics: rows differ from total"`)

	for resource, marker := range map[string]string{ResourceSearchQueries: `{"items":[]}`, ResourceSearchDetails: `{"queries":[]}`} {
		h3, _ := warehouseHarness(t, &fakeOzon{searchFn: pagesBy(nil)}, []ozon.Page{stocksPage(0, "")})
		assert.Equal(t, "done", h3.exec.Run(context.Background(), task(resource, searchDay), searchCabinet, "trace").Outcome)
		require.Len(t, h3.raw.puts, 1)
		assert.JSONEq(t, marker, string(h3.raw.puts[0].Body), "служебная страница — в форме выдачи ресурса")
	}
}

func TestSearchAnalyticsNoDataAndFreshness(t *testing.T) {
	noData := func(bool, time.Time, []int64, int) (ozon.SearchPage, error) {
		return ozon.SearchPage{}, &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointSearchQueries, Status: 400, Code: 3,
			Message: "ProductQueries error: service.ProductQueriesDetails.getPremiumAnalyticsPeriod rpc error: code = InvalidArgument desc = There is no data for the specified period"}
	}
	h, _ := warehouseHarness(t, &fakeOzon{searchFn: noData}, snapshot(2, 1, 2))
	res := h.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), searchCabinet, "trace")
	assert.Equal(t, "search_not_ready", res.Outcome, "D−2 без данных — ещё не посчитан")
	assert.Equal(t, store.StatusNotReady, h.tasks.finished.Status)
	assert.Equal(t, t0.Add(6*time.Hour), h.tasks.finished.NextRunAt)

	h2, _ := warehouseHarness(t, &fakeOzon{searchFn: noData}, snapshot(2, 1, 2))
	res = h2.exec.Run(context.Background(), task(ResourceSearchQueries, "2026-09-10"), searchCabinet, "trace")
	assert.Equal(t, "history_unavailable", res.Outcome)
	assert.Equal(t, store.StatusFailedPermanent, h2.tasks.finished.Status)
	assert.NotContains(t, h2.logs.String(), `"level":"ERROR"`, "ушедшая история — WARNING")

	o3 := &fakeOzon{searchFn: pagesBy(nil)}
	h3, _ := warehouseHarness(t, o3, snapshot(2, 1, 2))
	res = h3.exec.Run(context.Background(), task(ResourceSearchQueries, "2026-10-16"), searchCabinet, "trace")
	assert.Equal(t, "data_early", res.Outcome)
	assert.True(t, h3.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-18 00:00")))
	assert.Empty(t, o3.searchCalls)

	invalid := func(bool, time.Time, []int64, int) (ozon.SearchPage, error) {
		return ozon.SearchPage{}, &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointSearchQueries, Status: 400, Message: "invalid skus"}
	}
	h4, _ := warehouseHarness(t, &fakeOzon{searchFn: invalid}, snapshot(2, 1, 2))
	assert.Equal(t, "invalid_request", h4.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), searchCabinet, "trace").Outcome)
}

func TestSearchAnalyticsSKUsFromExecutionDaySnapshot(t *testing.T) {
	o := &fakeOzon{searchFn: pagesBy(map[int64]int{70: 1})}
	h, reader := warehouseHarness(t, o, nil)
	reader.bodies["s3://y"] = string(stocksPage(1, "C1", 7).Body)
	reader.bodies["s3://y2"] = string(stocksPage(1, "").Body)
	h.tasks.content[ResourceStocks+"/2026-10-16"] = []store.RawObject{{PageNo: 1, StorageURI: "s3://y", SHA256: sha(string(stocksPage(1, "C1", 7).Body))}, {PageNo: 2, StorageURI: "s3://y2", SHA256: sha(string(stocksPage(1, "").Body))}}

	res := h.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), searchCabinet, "trace")
	assert.Equal(t, "done", res.Outcome, "до снимка дня — вчерашний")
	require.Len(t, o.searchCalls, 1)
	assert.Equal(t, int64(70), o.searchCalls[0].firstSKU)

	h2, _ := warehouseHarness(t, &fakeOzon{searchFn: pagesBy(nil)}, nil)
	assert.Equal(t, "stocks_not_loaded", h2.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), searchCabinet, "trace").Outcome)

	for name, types := range map[string][]string{"not enabled": {ResourceStocks}, "without ozon.stocks": {ResourceSearchQueries}} {
		o3 := &fakeOzon{searchFn: pagesBy(nil)}
		h3, _ := warehouseHarness(t, o3, snapshot(1, 5))
		c := cabinet
		c.ResourceTypes = types
		assert.Equal(t, "resource_disabled", h3.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), c, "trace").Outcome, name)
		assert.Empty(t, o3.searchCalls, name)
	}
}

func TestSearchAnalyticsRepeatedPageIsRejected(t *testing.T) {
	o := &fakeOzon{searchFn: pagesBy(map[int64]int{10: 3}), searchSameBody: true}
	h, _ := warehouseHarness(t, o, snapshot(2, 1, 2))
	res := h.exec.Run(context.Background(), task(ResourceSearchQueries, searchDay), searchCabinet, "trace")
	assert.Equal(t, "invalid_response", res.Outcome, "страница 1 повторяет страницу 0")
	assert.Len(t, h.tasks.pages, 1)

	// После рестарта на странице 1: хеш сохранённой страницы 0 — последний в загрузке.
	_, skus := searchSKUs(2)
	o2 := &fakeOzon{searchFn: pagesBy(map[int64]int{10: 3}), searchSameBody: true}
	h2, _ := warehouseHarness(t, o2, snapshot(2, 2, 1))
	body := fmt.Sprintf(`{"items":[],"total":%d,"page_count":3,"analytics_period":{}}`, 10*1000)
	tk := task(ResourceSearchQueries, searchDay)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = numbersFingerprint(skuKeys(skus))+":0:1:3", 2, []string{"other", sha(body)}
	assert.Equal(t, "invalid_response", h2.exec.Run(context.Background(), tk, searchCabinet, "trace").Outcome)
}
