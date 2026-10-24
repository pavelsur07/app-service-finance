package ozon

import (
	"context"
	"net/http"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

var searchMSK = time.FixedZone("MSK", 3*60*60)

const searchPeriod = `"analytics_period":{"date_from":"2026-10-19 21:00:00 +0000 UTC","date_to":"2026-10-20 20:59:59 +0000 UTC"}`

func searchDayMSK() time.Time { return time.Date(2026, 10, 20, 0, 0, 0, 0, searchMSK) }

// Живые формы 24.10.2026 (сокращены).
const (
	queriesLive = `{"items":[{"sku":10,"name":"Брюки","offer_id":"A","category":"Одежда","unique_search_users":16781,"position":63,` +
		`"unique_view_users":"7","view_conversion":0,"gmv":0,"currency":"RUB"}],` + searchPeriod + `,"total":1,"page_count":1}`
	detailsLive = `{` + searchPeriod + `,"queries":[{"sku":10,"currency":"RUB","gmv":0,"order_count":0,"position":0,"query":"брюки женские",` +
		`"view_conversion":0,"query_index":1,"unique_search_users":14609,"unique_view_users":2}],"total":441,"page_count":5}`
)

func TestFetchSearchPageBodiesAndLiveShapes(t *testing.T) {
	for name, tc := range map[string]struct {
		details  bool
		endpoint string
		body     string
		want     map[string]any
		stats    SearchPage
	}{
		"queries": {false, EndpointSearchQueries, queriesLive, map[string]any{
			"date_from": "2026-10-20T00:00:00+03:00", "date_to": "2026-10-20T23:59:59+03:00",
			"skus": []any{"10", "20"}, "page": float64(0), "page_size": float64(SearchQueriesPageSize),
		}, SearchPage{Rows: 1, SKUs: 1, PageCount: 1, Total: 1}},
		"details": {true, EndpointSearchQueriesDetails, detailsLive, map[string]any{
			"date_from": "2026-10-20T00:00:00+03:00", "date_to": "2026-10-20T23:59:59+03:00",
			"skus": []any{"10", "20"}, "page": float64(0), "page_size": float64(SearchDetailsPageSize), "limit_by_sku": float64(SearchDetailsLimitBySKU),
		}, SearchPage{Rows: 1, SKUs: 1, PageCount: 5, Total: 441}},
	} {
		t.Run(name, func(t *testing.T) {
			handler, bodies := productServer(t, tc.endpoint, func(map[string]any) (int, string) { return http.StatusOK, tc.body })
			page, stats, err := clientFor(t, handler).FetchSearchPage(context.Background(), testCreds, "conn-1", tc.details, searchDayMSK(), []int64{10, 20}, 0)
			require.NoError(t, err)
			assert.Equal(t, tc.want, (*bodies)[0])
			assert.Equal(t, tc.stats, stats)
			assert.JSONEq(t, tc.body, string(page.Body))
			assert.True(t, SearchDrift(tc.details, page.Body).Empty(), "живая форма без расхождений")
		})
	}
}

func TestFetchSearchPageRejectsBrokenResponses(t *testing.T) {
	cases := map[string]string{
		"not object":      `[]`,
		"no items":        `{` + searchPeriod + `,"total":0,"page_count":0}`,
		"no page_count":   `{"items":[],` + searchPeriod + `,"total":0}`,
		"neg total":       `{"items":[],` + searchPeriod + `,"total":-1,"page_count":0}`,
		"no period":       `{"items":[],"total":0,"page_count":0}`,
		"other day":       `{"items":[],"analytics_period":{"date_from":"2026-10-20 00:00:00 +0000 UTC","date_to":"2026-10-20 23:59:59 +0000 UTC"},"total":0,"page_count":0}`,
		"foreign sku":     `{"items":[{"sku":99}],` + searchPeriod + `,"total":1,"page_count":1}`,
		"row without sku": `{"items":[{"query":"x"}],` + searchPeriod + `,"total":1,"page_count":1}`,
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			handler, _ := productServer(t, EndpointSearchQueries, func(map[string]any) (int, string) { return http.StatusOK, body })
			_, _, err := clientFor(t, handler).FetchSearchPage(context.Background(), testCreds, "conn-1", false, searchDayMSK(), []int64{10}, 0)
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestSearchNoDataAndLimits(t *testing.T) {
	handler, _ := productServer(t, EndpointSearchQueries, func(map[string]any) (int, string) {
		return http.StatusBadRequest, `{"code":3,"message":"ProductQueries error: service.ProductQueriesDetails.getPremiumAnalyticsPeriod rpc error: code = InvalidArgument desc = There is no data for the specified period"}`
	})
	_, _, err := clientFor(t, handler).FetchSearchPage(context.Background(), testCreds, "conn-1", false, searchDayMSK(), []int64{10}, 0)
	assert.True(t, IsSearchNoData(err))

	other, _ := productServer(t, EndpointSearchQueries, func(map[string]any) (int, string) {
		return http.StatusBadRequest, `{"code":3,"message":"Request validation error: invalid AnalyticsProductQueriesRequest.Skus"}`
	})
	_, _, err = clientFor(t, other).FetchSearchPage(context.Background(), testCreds, "conn-1", false, searchDayMSK(), []int64{10}, 0)
	assert.False(t, IsSearchNoData(err))

	limited, _ := productServer(t, EndpointSearchQueriesDetails, func(map[string]any) (int, string) {
		return http.StatusTooManyRequests, `{"code":8,"message":"You have reached request rate limit per second"}`
	})
	_, _, err = clientFor(t, limited).FetchSearchPage(context.Background(), testCreds, "conn-1", true, searchDayMSK(), []int64{10}, 0)
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassRateLimit, apiErr.Class)

	idle, bodies := productServer(t, EndpointSearchQueries, func(map[string]any) (int, string) { return http.StatusOK, `{}` })
	big := make([]int64, MaxSearchSKUs+1)
	for i := range big {
		big[i] = int64(i + 1)
	}
	for name, skus := range map[string][]int64{"empty": nil, "too many": big, "duplicate": {1, 1}} {
		_, _, err := clientFor(t, idle).FetchSearchPage(context.Background(), testCreds, "conn-1", false, searchDayMSK(), skus, 0)
		assert.Error(t, err, name)
	}
	_, _, err = clientFor(t, idle).FetchSearchPage(context.Background(), testCreds, "conn-1", false, searchDayMSK(), []int64{1}, MaxSearchPages)
	assert.Error(t, err, "предел страниц")
	assert.Empty(t, *bodies)
}
