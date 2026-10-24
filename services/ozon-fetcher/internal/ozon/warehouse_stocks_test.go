package ozon

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// warehouseRow — строка живой формы 24.10.2026 (сокращена).
func warehouseRow(sku int64, warehouse int64) string {
	return fmt.Sprintf(`{"sku":%d,"name":"Товар","offer_id":"A-%d","warehouse_id":%d,"warehouse_name":"СКЛАД","cluster_id":109,`+
		`"cluster_name":"Кластер","item_tags":["MARKABLE"],"ads":1.24,"turnover_grade":"WAS_DEFICIT","available_stock_count":3,`+
		`"valid_stock_count":3,"transit_stock_count":0,"return_from_customer_stock_count":1,"placement_zone":"SORT"}`, sku, sku, warehouse)
}

func TestFetchWarehouseStocksSendsSKUsAsStrings(t *testing.T) {
	handler, bodies := productServer(t, EndpointWarehouseStocks, func(map[string]any) (int, string) {
		return http.StatusOK, `{"items":[` + strings.Join([]string{warehouseRow(10, 23402539267000), warehouseRow(10, 7), warehouseRow(20, 7)}, ",") + `]}`
	})

	page, stats, err := clientFor(t, handler).FetchWarehouseStocks(context.Background(), testCreds, "conn-1", []int64{10, 20, 30})

	require.NoError(t, err)
	assert.Equal(t, map[string]any{"skus": []any{"10", "20", "30"}}, (*bodies)[0])
	assert.Equal(t, WarehouseStocks{Rows: 3, SKUs: 2, Warehouses: 2}, stats, "SKU 30 без строк — нигде не лежит")
	assert.Equal(t, 1, page.No)
	assert.Equal(t, "application/json", page.ContentType)
}

func TestFetchWarehouseStocksRejectsBrokenResponses(t *testing.T) {
	cases := map[string]string{
		"not object":      `[]`,
		"no items":        `{"result":[]}`,
		"row without sku": `{"items":[{"warehouse_id":1}]}`,
		"foreign sku":     `{"items":[` + warehouseRow(99, 1) + `]}`,
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			handler, _ := productServer(t, EndpointWarehouseStocks, func(map[string]any) (int, string) { return http.StatusOK, body })
			_, _, err := clientFor(t, handler).FetchWarehouseStocks(context.Background(), testCreds, "conn-1", []int64{10})
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestFetchWarehouseStocksValidatesChunkBeforeRequest(t *testing.T) {
	handler, bodies := productServer(t, EndpointWarehouseStocks, func(map[string]any) (int, string) { return http.StatusOK, `{"items":[]}` })
	big := make([]int64, MaxWarehouseStocksSKUs+1)
	for i := range big {
		big[i] = int64(i + 1)
	}
	for name, skus := range map[string][]int64{"empty": nil, "too many": big, "duplicate": {1, 1}, "zero": {0}} {
		_, _, err := clientFor(t, handler).FetchWarehouseStocks(context.Background(), testCreds, "conn-1", skus)
		assert.Error(t, err, name)
	}
	assert.Empty(t, *bodies)
}

func TestWarehouseStocksErrorClasses(t *testing.T) {
	for status, tc := range map[int]struct {
		body  string
		class Class
	}{
		http.StatusBadRequest:      {`{"code":3,"message":"Request validation error: invalid AnalyticsStocksRequest.Skus: value must contain between 1 and 100 items, inclusive"}`, ClassInvalidRequest},
		http.StatusTooManyRequests: {`{"code":8,"message":"too many requests"}`, ClassRateLimit},
	} {
		handler, _ := productServer(t, EndpointWarehouseStocks, func(map[string]any) (int, string) { return status, tc.body })
		_, _, err := clientFor(t, handler).FetchWarehouseStocks(context.Background(), testCreds, "conn-1", []int64{10})
		var apiErr *APIError
		require.ErrorAs(t, err, &apiErr)
		assert.Equal(t, tc.class, apiErr.Class)
	}
}

func TestShapeDriftNamesFieldsOnly(t *testing.T) {
	assert.True(t, PricesDrift([]byte(pricesBody("", 0))).Empty(), "пустая концевая страница — та же форма")
	assert.True(t, PricesDrift([]byte(pricesBody("C", 1, 1))).Empty())
	assert.True(t, PricesDrift([]byte(`not json`)).Empty(), "неразборчивое отвергает разбор страницы")

	d := WarehouseStocksDrift([]byte(`{"items":[{"sku":1,"secret_value":5}],"extra":true}`))
	assert.Equal(t, []string{"extra", "items[].secret_value"}, d.Unknown())
	assert.Contains(t, d.Missing(), "items[].warehouse_id")
	assert.NotContains(t, d.Missing(), "items[].sku")
}
