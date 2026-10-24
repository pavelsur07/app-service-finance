package ozon

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
)

// Остатки — снимок по всем товарам кабинета
// (docs/tasks/marketplace-data-ingestion/stages/ozon-5-stocks.md §2).
const EndpointStocks = "/v4/product/info/stocks"

// StocksPageLimit — максимум Ozon (1001 → 400 «inside range (0, 1000]»).
const StocksPageLimit = 1000

// MaxStocksPages — защитный предел страниц снимка (миллион товаров).
const MaxStocksPages = 1000

// stocksShape — страница остатков: без result, курсор — cursor.
type stocksShape struct {
	Items  *[]stocksItem `json:"items"`
	Cursor *string       `json:"cursor"`
	Total  *int64        `json:"total"`
}

type stocksItem struct {
	Stocks *[]struct {
		SKU *int64 `json:"sku"`
	} `json:"stocks"`
}

// StocksPage — счётчики страницы: товаров, SKU по строкам stocks[] и total;
// BadSKUs — строки stocks[] без положительного sku (в SKUs не входят).
type StocksPage struct {
	Items   int
	SKUs    []int64
	BadSKUs int
	Total   int64
	Next    string
}

// ParseStocksPage проверяет страницу снимка. Конец снимка Ozon сообщает
// пустой страницей: последняя страница с товарами отдаёт непустой cursor
// (живая проверка 22.10.2026).
func ParseStocksPage(body []byte) (StocksPage, error) {
	var shape stocksShape
	if err := json.Unmarshal(body, &shape); err != nil {
		return StocksPage{}, invalidResponse(EndpointStocks, "response is not a JSON object")
	}
	if shape.Items == nil {
		return StocksPage{}, invalidResponse(EndpointStocks, `response has no "items" array`)
	}
	if shape.Cursor == nil {
		return StocksPage{}, invalidResponse(EndpointStocks, `response has no string "cursor"`)
	}
	if shape.Total == nil || *shape.Total < 0 {
		return StocksPage{}, invalidResponse(EndpointStocks, `response has no non-negative "total"`)
	}
	page := StocksPage{Items: len(*shape.Items), Total: *shape.Total}
	for _, it := range *shape.Items {
		if it.Stocks == nil {
			return StocksPage{}, invalidResponse(EndpointStocks, `item has no "stocks" array`)
		}
		for _, s := range *it.Stocks {
			if s.SKU != nil && *s.SKU > 0 {
				page.SKUs = append(page.SKUs, *s.SKU)
			} else {
				page.BadSKUs++
			}
		}
	}
	if page.Items == 0 {
		// Пустая страница завершает обход и при непустом cursor.
		return page, nil
	}
	page.Next = strings.TrimSpace(*shape.Cursor)
	if page.Next == "" {
		// Конец подтверждает только пустая страница: товары без курсора —
		// тихая обрезка снимка.
		return StocksPage{}, invalidResponse(EndpointStocks, "non-empty page without cursor")
	}
	return page, nil
}

// FetchStocks выгружает снимок остатков (visibility ALL) постранично, начиная
// с fromCursor ("" — с начала). Каждая страница, включая концевую пустую,
// отдаётся в onPage до запроса следующей; Page.Cursor — курсор следующей
// страницы, "" у последней.
func (c *Client) FetchStocks(ctx context.Context, creds Credentials, connectionRef, fromCursor string, firstPageNo int, onPage PageHandler) error {
	return c.walkSnapshot(ctx, creds, connectionRef, EndpointStocks, StocksPageLimit, MaxStocksPages, fromCursor, firstPageNo,
		func(body []byte) (string, error) {
			page, err := ParseStocksPage(body)
			return page.Next, err
		}, onPage)
}

// walkSnapshot — обход снимка по cursor в корне ответа (остатки, цены):
// filter.visibility=ALL, limit, cursor со второй страницы; next разбирает
// страницу и отдаёт курсор следующей ("" — конец снимка).
func (c *Client) walkSnapshot(ctx context.Context, creds Credentials, connectionRef, endpoint string, limit, maxPages int,
	fromCursor string, firstPageNo int, next func(body []byte) (string, error), onPage PageHandler) error {
	cursor := strings.TrimSpace(fromCursor)
	seen := map[string]bool{}
	if cursor != "" {
		seen[cursor] = true
	}
	for no := firstPageNo; ; no++ {
		if no > maxPages {
			return invalidResponse(endpoint, fmt.Sprintf("more than %d pages with a continuation marker", maxPages))
		}
		body := map[string]any{
			"filter": map[string]string{"visibility": "ALL"},
			"limit":  limit,
		}
		if cursor != "" {
			body["cursor"] = cursor
		}
		resp, err := c.Post(ctx, creds, connectionRef, endpoint, body)
		if err != nil {
			return err
		}
		nextCursor, err := next(resp.Body)
		if err != nil {
			return err
		}
		if nextCursor != "" && seen[nextCursor] {
			// Курсор не продвинулся при непустой странице — обход крутился бы вечно.
			return invalidResponse(endpoint, fmt.Sprintf("repeated cursor on page %d", no))
		}
		seen[nextCursor] = true

		if err := onPage(Page{No: no, Body: resp.Body, Cursor: nextCursor, ContentType: "application/json"}); err != nil {
			return err
		}
		if nextCursor == "" {
			return nil
		}
		cursor = nextCursor
	}
}
