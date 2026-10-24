package ozon

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Поисковая аналитика Premium по SKU за день
// (docs/tasks/marketplace-data-ingestion/stages/ozon-9-search-analytics.md §2).
const (
	EndpointSearchQueries        = "/v1/analytics/product-queries"
	EndpointSearchQueriesDetails = "/v1/analytics/product-queries/details"
)

const (
	// MaxSearchSKUs — предел skus в запросе (обязательны, 1–1000).
	MaxSearchSKUs = 1000
	// SearchQueriesPageSize, SearchDetailsPageSize — максимумы Ozon.
	SearchQueriesPageSize = 1000
	SearchDetailsPageSize = 100
	// SearchDetailsLimitBySKU — запросов на SKU, максимум Ozon (0, 15].
	SearchDetailsLimitBySKU = 15
	// MaxSearchPages — защитный предел страниц порции.
	MaxSearchPages = 1000
)

// noDataMessage — 400 Ozon за день без данных: ещё не посчитан (D−1) или
// уже вне глубины истории (живая проверка 24.10.2026).
const noDataMessage = "no data for the specified period"

// IsSearchNoData — день без данных поисковой аналитики.
func IsSearchNoData(err error) bool {
	var apiErr *APIError
	return errors.As(err, &apiErr) && apiErr.Class == ClassInvalidRequest &&
		strings.Contains(strings.ToLower(apiErr.Message), noDataMessage)
}

// SearchPage — счётчики страницы.
type SearchPage struct {
	Rows      int
	SKUs      int // разных SKU в строках
	PageCount int
	Total     int
}

// periodLayout — analytics_period в ответе: "2026-10-19 21:00:00 +0000 UTC".
const periodLayout = "2006-01-02 15:04:05 -0700 MST"

// FetchSearchPage — страница page (с 0) сводки (details=false) или топа
// запросов по SKU (details=true) за день day (полночь в часовом поясе дня).
// Проверяет: массив строк, SKU строк — из порции, analytics_period — запрошенный
// день, page_count неотрицателен.
func (c *Client) FetchSearchPage(ctx context.Context, creds Credentials, connectionRef string, details bool, day time.Time, skus []int64, page int) (Page, SearchPage, error) {
	endpoint, array, pageSize := EndpointSearchQueries, "items", SearchQueriesPageSize
	if details {
		endpoint, array, pageSize = EndpointSearchQueriesDetails, "queries", SearchDetailsPageSize
	}
	if len(skus) == 0 || len(skus) > MaxSearchSKUs {
		return Page{}, SearchPage{}, fmt.Errorf("ozon: search analytics need 1..%d skus, got %d", MaxSearchSKUs, len(skus))
	}
	if page < 0 || page >= MaxSearchPages {
		return Page{}, SearchPage{}, invalidResponse(endpoint, fmt.Sprintf("page %d outside 0..%d", page, MaxSearchPages-1))
	}
	requested := make(map[int64]bool, len(skus))
	list := make([]string, len(skus))
	for i, sku := range skus {
		if sku <= 0 || requested[sku] {
			return Page{}, SearchPage{}, fmt.Errorf("ozon: search analytics: non-positive or duplicate sku %d", sku)
		}
		requested[sku] = true
		list[i] = strconv.FormatInt(sku, 10)
	}
	from, to := day, day.AddDate(0, 0, 1).Add(-time.Second)
	body := map[string]any{
		"date_from": from.Format(time.RFC3339),
		"date_to":   to.Format(time.RFC3339),
		"skus":      list,
		"page":      page,
		"page_size": pageSize,
	}
	if details {
		body["limit_by_sku"] = SearchDetailsLimitBySKU
	}
	resp, err := c.Post(ctx, creds, connectionRef, endpoint, body)
	if err != nil {
		return Page{}, SearchPage{}, err
	}
	stats, err := parseSearchPage(endpoint, array, resp.Body, requested, from, to)
	if err != nil {
		return Page{}, SearchPage{}, err
	}
	return Page{No: 1, Body: resp.Body, ContentType: "application/json"}, stats, nil
}

func parseSearchPage(endpoint, array string, body []byte, requested map[int64]bool, from, to time.Time) (SearchPage, error) {
	var root map[string]json.RawMessage
	if err := json.Unmarshal(body, &root); err != nil {
		return SearchPage{}, invalidResponse(endpoint, "response is not a JSON object")
	}
	var rows []struct {
		SKU *int64 `json:"sku"`
	}
	if raw, ok := root[array]; !ok || json.Unmarshal(raw, &rows) != nil || rows == nil {
		return SearchPage{}, invalidResponse(endpoint, fmt.Sprintf("response has no %q array", array))
	}
	var pageCount, total *int
	_ = json.Unmarshal(root["page_count"], &pageCount)
	_ = json.Unmarshal(root["total"], &total)
	if pageCount == nil || *pageCount < 0 || total == nil || *total < 0 {
		return SearchPage{}, invalidResponse(endpoint, `response has no non-negative "page_count" and "total"`)
	}
	var period struct {
		From string `json:"date_from"`
		To   string `json:"date_to"`
	}
	if json.Unmarshal(root["analytics_period"], &period) != nil {
		return SearchPage{}, invalidResponse(endpoint, `response has no "analytics_period"`)
	}
	gotFrom, errFrom := time.Parse(periodLayout, period.From)
	gotTo, errTo := time.Parse(periodLayout, period.To)
	if errFrom != nil || errTo != nil || !gotFrom.Equal(from) || !gotTo.Equal(to) {
		return SearchPage{}, invalidResponse(endpoint, fmt.Sprintf("analytics_period %q..%q differs from the requested day", period.From, period.To))
	}
	skus := map[int64]bool{}
	for _, row := range rows {
		if row.SKU == nil || !requested[*row.SKU] {
			sku := int64(0)
			if row.SKU != nil {
				sku = *row.SKU
			}
			return SearchPage{}, invalidResponse(endpoint, fmt.Sprintf("unexpected sku %d in response", sku))
		}
		skus[*row.SKU] = true
	}
	return SearchPage{Rows: len(rows), SKUs: len(skus), PageCount: *pageCount, Total: *total}, nil
}

// Живые формы 24.10.2026.
var (
	searchQueriesTop   = []string{"items", "total", "page_count", "analytics_period"}
	searchQueriesItem  = []string{"sku", "name", "offer_id", "category", "unique_search_users", "position", "unique_view_users", "view_conversion", "gmv", "currency"}
	searchDetailsTop   = []string{"queries", "total", "page_count", "analytics_period"}
	searchDetailsQuery = []string{"sku", "query", "query_index", "position", "unique_search_users", "unique_view_users", "view_conversion", "order_count", "gmv", "currency"}
)

// SearchDrift — расхождение страницы поисковой аналитики с живой формой.
func SearchDrift(details bool, body []byte) Drift {
	if details {
		return shapeDrift(body, searchDetailsTop, "queries", searchDetailsQuery)
	}
	return shapeDrift(body, searchQueriesTop, "items", searchQueriesItem)
}
