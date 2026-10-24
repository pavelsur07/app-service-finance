package ozon

import (
	"context"
	"encoding/json"
	"strings"
)

// Цены — снимок по всем товарам кабинета
// (docs/tasks/marketplace-data-ingestion/stages/ozon-8-prices-warehouse-stocks.md §2).
const EndpointPrices = "/v5/product/info/prices"

// PricesPageLimit — максимум Ozon (1001 → 400 «inside range (0, 1000]»).
const PricesPageLimit = 1000

// MaxPricesPages — защитный предел страниц снимка (миллион товаров).
const MaxPricesPages = 1000

type pricesShape struct {
	Items *[]struct {
		ProductID *int64 `json:"product_id"`
	} `json:"items"`
	Cursor *string `json:"cursor"`
	Total  *int64  `json:"total"`
}

// PricesPage — счётчики страницы цен.
type PricesPage struct {
	Items int
	Total int64
	Next  string
}

// ParsePricesPage проверяет страницу снимка цен. Пагинация — как у остатков:
// последняя страница с товарами отдаёт непустой cursor, конец — пустая
// страница (живая проверка 24.10.2026).
func ParsePricesPage(body []byte) (PricesPage, error) {
	var shape pricesShape
	if err := json.Unmarshal(body, &shape); err != nil {
		return PricesPage{}, invalidResponse(EndpointPrices, "response is not a JSON object")
	}
	if shape.Items == nil {
		return PricesPage{}, invalidResponse(EndpointPrices, `response has no "items" array`)
	}
	if shape.Cursor == nil {
		return PricesPage{}, invalidResponse(EndpointPrices, `response has no string "cursor"`)
	}
	if shape.Total == nil || *shape.Total < 0 {
		return PricesPage{}, invalidResponse(EndpointPrices, `response has no non-negative "total"`)
	}
	for _, it := range *shape.Items {
		if it.ProductID == nil || *it.ProductID <= 0 {
			return PricesPage{}, invalidResponse(EndpointPrices, `item has no positive "product_id"`)
		}
	}
	page := PricesPage{Items: len(*shape.Items), Total: *shape.Total}
	if page.Items == 0 {
		// Пустая страница завершает обход и при непустом cursor.
		return page, nil
	}
	page.Next = strings.TrimSpace(*shape.Cursor)
	if page.Next == "" {
		// Конец подтверждает только пустая страница: товары без курсора —
		// тихая обрезка снимка.
		return PricesPage{}, invalidResponse(EndpointPrices, "non-empty page without cursor")
	}
	return page, nil
}

// FetchPrices выгружает снимок цен (visibility ALL) постранично, начиная с
// fromCursor ("" — с начала); контракт onPage — как у FetchStocks.
func (c *Client) FetchPrices(ctx context.Context, creds Credentials, connectionRef, fromCursor string, firstPageNo int, onPage PageHandler) error {
	return c.walkSnapshot(ctx, creds, connectionRef, EndpointPrices, PricesPageLimit, MaxPricesPages, fromCursor, firstPageNo,
		func(body []byte) (string, error) {
			page, err := ParsePricesPage(body)
			return page.Next, err
		}, onPage)
}
