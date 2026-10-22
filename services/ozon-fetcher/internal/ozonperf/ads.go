package ozonperf

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/url"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

// Синхронные методы рекламы
// (docs/tasks/marketplace-data-ingestion/stages/ozon-6-ads-sync.md §2).
const (
	EndpointCampaigns = "/api/client/campaign"
	EndpointExpense   = "/api/client/statistics/expense/json"
	EndpointDaily     = "/api/client/statistics/daily/json"
)

// FetchCampaigns — список всех кампаний аккаунта (все типы и состояния).
// Ответ — объект с массивом list.
func (c *Client) FetchCampaigns(ctx context.Context, creds Credentials, connectionRef string) (ozon.Page, error) {
	resp, err := c.Get(ctx, creds, connectionRef, EndpointCampaigns, nil)
	if err != nil {
		return ozon.Page{}, err
	}
	var shape struct {
		List json.RawMessage `json:"list"`
	}
	if err := json.Unmarshal(resp.Body, &shape); err != nil || !isArray(shape.List) {
		return ozon.Page{}, invalid(EndpointCampaigns, resp.Status, `response has no "list" array`)
	}
	return ozon.Page{No: 1, Body: resp.Body, ContentType: contentType(resp)}, nil
}

// FetchExpense — расход аккаунта за день (dateFrom = dateTo).
func (c *Client) FetchExpense(ctx context.Context, creds Credentials, connectionRef, day string) (ozon.Page, error) {
	return c.fetchDay(ctx, creds, connectionRef, EndpointExpense, day)
}

// FetchDaily — дневная статистика по кампаниям за день (dateFrom = dateTo).
func (c *Client) FetchDaily(ctx context.Context, creds Credentials, connectionRef, day string) (ozon.Page, error) {
	return c.fetchDay(ctx, creds, connectionRef, EndpointDaily, day)
}

// fetchDay — день как пришёл; форма ответа вживую не проверена (нет тестового
// ключа), поэтому проверяется только JSON-объект или массив.
func (c *Client) fetchDay(ctx context.Context, creds Credentials, connectionRef, endpoint, day string) (ozon.Page, error) {
	resp, err := c.Get(ctx, creds, connectionRef, endpoint, url.Values{"dateFrom": {day}, "dateTo": {day}})
	if err != nil {
		return ozon.Page{}, err
	}
	trimmed := bytes.TrimSpace(resp.Body)
	if !json.Valid(trimmed) || len(trimmed) == 0 || (trimmed[0] != '{' && trimmed[0] != '[') {
		return ozon.Page{}, invalid(endpoint, resp.Status, "response is not a JSON object or array")
	}
	return ozon.Page{No: 1, Body: resp.Body, ContentType: contentType(resp)}, nil
}

func contentType(resp ozon.Response) string {
	if ct := resp.Header.Get("Content-Type"); ct != "" {
		return ct
	}
	return "application/json"
}

func isArray(raw json.RawMessage) bool {
	trimmed := bytes.TrimSpace(raw)
	return len(trimmed) > 0 && trimmed[0] == '['
}

func invalid(endpoint string, status int, reason string) error {
	return &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: endpoint, Status: status, Err: errors.New(reason)}
}
