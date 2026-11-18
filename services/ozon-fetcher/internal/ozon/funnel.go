package ozon

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
)

// Воронка продаж по SKU за день (docs/tasks/marketplace-funnel/TASK.md, этап 4).
const EndpointFunnel = "/v1/analytics/data"

const (
	// FunnelPageSize — максимум строк Ozon в ответе.
	FunnelPageSize = 1000
	// MaxFunnelPages — защитный предел страниц дня.
	MaxFunnelPages = 100
)

// FunnelMetrics — показы, переходы в карточку, корзины, заказы и их исходы.
// Ozon принимает не больше 14 метрик; конверсии считаются из этих счётчиков.
var FunnelMetrics = []string{
	"hits_view_search", "hits_view_pdp", "hits_view",
	"session_view_search", "session_view_pdp", "session_view",
	"hits_tocart_search", "hits_tocart_pdp", "hits_tocart",
	"ordered_units", "revenue", "returns", "cancellations", "delivered_units",
}

// subscriptionMessage — 400 Ozon кабинету, тариф которого не открывает метрики
// воронки (живой ответ 24.10.2026).
const subscriptionMessage = "deprecated metrics"

// outcomeSubscriptionRequired — исход запроса в журнале клиента (INFO).
const outcomeSubscriptionRequired = "subscription_required"

// IsSubscriptionRequired — метрики воронки закрыты тарифом кабинета.
func IsSubscriptionRequired(err error) bool {
	var apiErr *APIError
	return errors.As(err, &apiErr) && apiErr.Class == ClassInvalidRequest &&
		strings.Contains(strings.ToLower(apiErr.Message), subscriptionMessage)
}

// ErrFunnelShifted — SKU повторился между страницами: порядок выдачи сдвинулся
// во время загрузки, день читается заново.
var ErrFunnelShifted = errors.New("ozon: funnel sku repeated between pages")

// FetchFunnel выгружает воронку дня day (YYYY-MM-DD) по SKU постранично по
// offset; возвращает число строк. Page.Cursor — offset следующей страницы, ""
// у последней. Нет поля data, строка без SKU — invalid_response, повтор SKU
// между страницами — ErrFunnelShifted; "data": null — пустой день.
func (c *Client) FetchFunnel(ctx context.Context, creds Credentials, connectionRef, day string, firstPageNo int, onPage PageHandler) (int, error) {
	// SKU прежних страниц попытки: порядок выдачи Ozon не закреплён, сдвиг
	// между страницами дал бы повторы и потери.
	seen := map[string]bool{}
	for page := 0; ; page++ {
		if page >= MaxFunnelPages {
			return 0, invalidResponse(EndpointFunnel, fmt.Sprintf("more than %d full pages", MaxFunnelPages))
		}
		resp, err := c.Post(ctx, creds, connectionRef, EndpointFunnel, map[string]any{
			"date_from": day, "date_to": day, "metrics": FunnelMetrics, "dimension": []string{"sku"},
			"limit": FunnelPageSize, "offset": page * FunnelPageSize,
		})
		if err != nil {
			return 0, err
		}
		var root struct {
			Result *struct {
				Data []struct {
					Dimensions []struct {
						ID string `json:"id"`
					} `json:"dimensions"`
				} `json:"data"`
			} `json:"result"`
		}
		var probe struct {
			Result map[string]json.RawMessage `json:"result"`
		}
		// "data": null — пустой день; поля нет вовсе — повреждённый ответ.
		if json.Unmarshal(resp.Body, &probe) != nil || probe.Result["data"] == nil || json.Unmarshal(resp.Body, &root) != nil || root.Result == nil {
			return 0, invalidResponse(EndpointFunnel, `response has no "result.data"`)
		}
		for _, row := range root.Result.Data {
			if len(row.Dimensions) == 0 || row.Dimensions[0].ID == "" {
				return 0, invalidResponse(EndpointFunnel, "row without sku")
			}
			if seen[row.Dimensions[0].ID] {
				return 0, ErrFunnelShifted
			}
			seen[row.Dimensions[0].ID] = true
		}
		full := len(root.Result.Data) == FunnelPageSize
		next := ""
		if full {
			next = strconv.Itoa((page + 1) * FunnelPageSize)
		}
		if err := onPage(Page{No: firstPageNo + page, Body: resp.Body, Cursor: next, ContentType: "application/json"}); err != nil {
			return 0, err
		}
		if !full {
			return len(seen), nil
		}
	}
}
