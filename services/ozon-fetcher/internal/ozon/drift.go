package ozon

import (
	"encoding/json"
	"slices"
	"sort"
)

// Drift — расхождение набора полей ответа с ожидаемым (spec-ozon.md п. 14):
// имена неизвестных и пропавших полей вида "items[].name", без значений.
type Drift struct {
	unknown, missing map[string]bool
}

// Add объединяет расхождения страниц задачи.
func (d *Drift) Add(o Drift) {
	for k := range o.unknown {
		d.mark(&d.unknown, k)
	}
	for k := range o.missing {
		d.mark(&d.missing, k)
	}
}

func (d *Drift) mark(m *map[string]bool, k string) {
	if *m == nil {
		*m = map[string]bool{}
	}
	(*m)[k] = true
}

func (d Drift) Empty() bool { return len(d.unknown) == 0 && len(d.missing) == 0 }

func (d Drift) Unknown() []string { return sortedKeys(d.unknown) }

func (d Drift) Missing() []string { return sortedKeys(d.missing) }

func sortedKeys(m map[string]bool) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// shapeDrift сверяет ключи верхнего уровня и ключи каждого элемента массива
// array с ожидаемыми. Неразборчивый ответ — не расхождение: его отвергает
// разбор страницы.
func shapeDrift(body []byte, top []string, array string, item []string) Drift {
	var d Drift
	var root map[string]json.RawMessage
	if json.Unmarshal(body, &root) != nil {
		return d
	}
	compare(&d, "", root, top)
	var rows []map[string]json.RawMessage
	if json.Unmarshal(root[array], &rows) != nil {
		return d
	}
	for _, row := range rows {
		compare(&d, array+"[].", row, item)
	}
	return d
}

func compare(d *Drift, prefix string, got map[string]json.RawMessage, want []string) {
	for k := range got {
		if !slices.Contains(want, k) {
			d.mark(&d.unknown, prefix+k)
		}
	}
	for _, k := range want {
		if _, ok := got[k]; !ok {
			d.mark(&d.missing, prefix+k)
		}
	}
}

// Живые формы 24.10.2026.
var (
	pricesTop  = []string{"items", "cursor", "total", "total_items"}
	pricesItem = []string{"acquiring", "commissions", "marketing_actions", "offer_id", "price", "price_indexes", "product_id", "volume_weight"}

	warehouseStocksTop  = []string{"items"}
	warehouseStocksItem = []string{
		"ads", "ads_cluster", "available_stock_count", "cluster_id", "cluster_name", "days_without_sales",
		"days_without_sales_cluster", "excess_stock_count", "expiring_stock_count", "idc", "idc_cluster",
		"inbound_replenishment", "item_tags", "macrolocal_cluster_id", "name", "offer_id", "other_stock_count",
		"outbound_pending_delivery", "outbound_returns_picking", "outbound_returns_ready_to_ship",
		"outbound_returns_return_to_seller", "placement_zone", "requested_stock_count",
		"return_from_customer_stock_count", "return_to_seller_stock_count", "sku", "stock_defect_stock_count",
		"stock_not_being_sold", "transit_defect_stock_count", "transit_stock_count", "turnover_grade",
		"turnover_grade_cluster", "valid_stock_count", "waiting_docs_stock_count",
		"waiting_docs_to_export_stock_count", "warehouse_id", "warehouse_name",
	}
)

// PricesDrift — расхождение страницы цен с живой формой.
func PricesDrift(body []byte) Drift { return shapeDrift(body, pricesTop, "items", pricesItem) }

// WarehouseStocksDrift — расхождение ответа остатков по складам с живой формой.
func WarehouseStocksDrift(body []byte) Drift {
	return shapeDrift(body, warehouseStocksTop, "items", warehouseStocksItem)
}
