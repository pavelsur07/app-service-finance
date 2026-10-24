package ozon

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
)

// Остатки по складам и кластерам — аналитика по списку SKU
// (docs/tasks/marketplace-data-ingestion/stages/ozon-8-prices-warehouse-stocks.md §2).
const EndpointWarehouseStocks = "/v1/analytics/stocks"

// MaxWarehouseStocksSKUs — предел SKU в запросе (101 → 400 «between 1 and 100 items»).
const MaxWarehouseStocksSKUs = 100

// WarehouseStocks — счётчики ответа на порцию SKU.
type WarehouseStocks struct {
	Rows       int
	SKUs       int // разных SKU в строках
	Warehouses int // разных складов в строках
}

// FetchWarehouseStocks — строки «SKU × склад» по порции SKU. Пагинации у
// метода нет; строка с SKU вне порции — invalid_response (чужие данные).
// SKU без строк — норма: товар нигде не лежит.
func (c *Client) FetchWarehouseStocks(ctx context.Context, creds Credentials, connectionRef string, skus []int64) (Page, WarehouseStocks, error) {
	if len(skus) == 0 || len(skus) > MaxWarehouseStocksSKUs {
		return Page{}, WarehouseStocks{}, fmt.Errorf("ozon: warehouse stocks need 1..%d skus, got %d", MaxWarehouseStocksSKUs, len(skus))
	}
	requested := make(map[int64]bool, len(skus))
	list := make([]string, len(skus))
	for i, sku := range skus {
		if sku <= 0 || requested[sku] {
			return Page{}, WarehouseStocks{}, fmt.Errorf("ozon: warehouse stocks: non-positive or duplicate sku %d", sku)
		}
		requested[sku] = true
		list[i] = strconv.FormatInt(sku, 10)
	}

	resp, err := c.Post(ctx, creds, connectionRef, EndpointWarehouseStocks, map[string][]string{"skus": list})
	if err != nil {
		return Page{}, WarehouseStocks{}, err
	}
	stats, err := ParseWarehouseStocks(resp.Body, requested)
	if err != nil {
		return Page{}, WarehouseStocks{}, err
	}
	return Page{No: 1, Body: resp.Body, ContentType: "application/json"}, stats, nil
}

// ParseWarehouseStocks проверяет ответ: items — массив, у строки — sku из
// запрошенных (requested nil — без проверки принадлежности).
func ParseWarehouseStocks(body []byte, requested map[int64]bool) (WarehouseStocks, error) {
	var shape struct {
		Items *[]struct {
			SKU         *int64      `json:"sku"`
			WarehouseID json.Number `json:"warehouse_id"`
		} `json:"items"`
	}
	if err := json.Unmarshal(body, &shape); err != nil || shape.Items == nil {
		return WarehouseStocks{}, invalidResponse(EndpointWarehouseStocks, `response has no "items" array`)
	}
	skus, warehouses := map[int64]bool{}, map[string]bool{}
	for _, row := range *shape.Items {
		if row.SKU == nil || *row.SKU <= 0 {
			return WarehouseStocks{}, invalidResponse(EndpointWarehouseStocks, `row has no positive "sku"`)
		}
		if requested != nil && !requested[*row.SKU] {
			return WarehouseStocks{}, invalidResponse(EndpointWarehouseStocks, fmt.Sprintf("unexpected sku %d in response", *row.SKU))
		}
		skus[*row.SKU] = true
		if row.WarehouseID != "" {
			warehouses[row.WarehouseID.String()] = true
		}
	}
	return WarehouseStocks{Rows: len(*shape.Items), SKUs: len(skus), Warehouses: len(warehouses)}, nil
}
