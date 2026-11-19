// Package facts — публикация загруженного Symfony фактами
// (services/_contracts/source-fact-envelope/v1): какие ресурсы публикуются и
// как страница режется на записи. Строки отчёта не разбираются — это делает
// Symfony (MarketplaceWb).
package facts

import (
	"cmp"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"regexp"
	"slices"
	"strings"

	fkhttp "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/httpapi"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

// ResourceDaily — дневной отчёт о продажах (executor.ResourceDaily; здесь —
// чтобы app не тянул executor ради списка).
const ResourceDaily = "wb.finance.sales-report.daily"

// ResourceAds — реклама за день (executor.ResourceAds).
const ResourceAds = "wb.ads.daily"

// Resources — ресурсы, чьи ревизии публикуются фактами.
var Resources = []string{ResourceDaily, ResourceAds, ResourceFunnel, ResourceSearch}

// ResourceFunnel — воронка продаж по карточкам за день (executor.ResourceFunnel).
const ResourceFunnel = "wb.analytics.funnel"

const funnelKeyPrefix = "wb:analytics.funnel:"

// ResourceSearch — поиск по карточкам за день (executor.ResourceSearch).
const ResourceSearch = "wb.analytics.search"

const searchKeyPrefix = "wb:analytics.search:"

// SearchUnavailableKey — запись дня, который WB не отдал по тарифу кабинета
// (wb.SearchUnavailablePage): «нет данных», а не нули.
const SearchUnavailableKey = searchKeyPrefix + "unavailable"

// searchMetrics — показатели карточки в поиске; в запись идёт значение дня (`current`).
var searchMetrics = []string{"openCard", "addToCart", "orders", "visibility", "avgPosition"}

const adsKeyPrefix = "wb:ads.daily:"

const dailyKeyPrefix = "wb:sales-report.daily:"

var rrdID = regexp.MustCompile(`^[0-9]{1,20}$`)

// Split режет страницу (JSON-массив строк отчёта как есть) на записи; пустая
// последняя страница `[]` — ноль записей.
func Split(resourceType string, page []byte) ([]fkhttp.FactRecord, error) {
	if resourceType == ResourceAds {
		return splitAds(page)
	}
	if resourceType == ResourceFunnel {
		return splitFunnel(page)
	}
	if resourceType == ResourceSearch {
		return splitSearch(page)
	}
	if resourceType != ResourceDaily {
		return nil, fmt.Errorf("facts: resource %q is not published", resourceType)
	}
	var rows []json.RawMessage
	if err := json.Unmarshal(page, &rows); err != nil {
		return nil, fmt.Errorf("facts: sales-report page is not an array: %w", err)
	}
	out := make([]fkhttp.FactRecord, 0, len(rows))
	for i, raw := range rows {
		key, err := rowKey(raw)
		if err != nil {
			return nil, fmt.Errorf("facts: row %d: %w", i, err)
		}
		out = append(out, fkhttp.FactRecord{SourceKey: key, Payload: raw})
	}
	return out, nil
}

// rowKey — wb:sales-report.daily:{rrdId}; строка без пригодного rrdId получает
// ключ от содержимого (fallback-{sha256[:16]}), как у Ozon.
func rowKey(raw json.RawMessage) (string, error) {
	var row struct {
		RrdID json.RawMessage `json:"rrdId"`
	}
	if err := json.Unmarshal(raw, &row); err != nil {
		return "", fmt.Errorf("not an object: %w", err)
	}
	if id := strings.Trim(string(row.RrdID), `"`); rrdID.MatchString(id) {
		return dailyKeyPrefix + id, nil
	}
	sum := sha256.Sum256(raw)
	return dailyKeyPrefix + "fallback-" + hex.EncodeToString(sum[:])[:16], nil
}

// splitAds — запись = кампания дня: её списания и её строка fullstats (null — статистики нет),
// ключ wb:ads.daily:{advertId}; порядок — по номеру кампании.
func splitAds(page []byte) ([]fkhttp.FactRecord, error) {
	var day struct {
		Schema     string            `json:"schema"`
		Expenses   []json.RawMessage `json:"expenses"`
		Statistics []json.RawMessage `json:"statistics"`
	}
	if err := json.Unmarshal(page, &day); err != nil || day.Schema != wb.AdsSchema {
		return nil, fmt.Errorf("facts: ads page is not %s", wb.AdsSchema)
	}
	expenses := map[string][]json.RawMessage{}
	var ids []string
	for i, raw := range day.Expenses {
		id, err := wb.AdvertID(raw)
		if err != nil {
			return nil, fmt.Errorf("facts: expenses[%d]: %w", i, err)
		}
		if _, ok := expenses[id]; !ok {
			ids = append(ids, id)
		}
		expenses[id] = append(expenses[id], raw)
	}
	stats := map[string]json.RawMessage{}
	for i, raw := range day.Statistics {
		id, err := wb.AdvertID(raw)
		if err != nil {
			return nil, fmt.Errorf("facts: statistics[%d]: %w", i, err)
		}
		stats[id] = raw
	}
	slices.SortFunc(ids, func(a, b string) int {
		if c := cmp.Compare(len(a), len(b)); c != 0 {
			return c
		}
		return strings.Compare(a, b)
	})
	out := make([]fkhttp.FactRecord, 0, len(ids))
	for _, id := range ids {
		st := stats[id]
		if st == nil {
			st = json.RawMessage("null")
		}
		payload, err := json.Marshal(map[string]any{"advertId": id, "expenses": expenses[id], "statistics": st})
		if err != nil {
			return nil, fmt.Errorf("facts: encode campaign %s: %w", id, err)
		}
		out = append(out, fkhttp.FactRecord{SourceKey: adsKeyPrefix + id, Payload: payload})
	}
	return out, nil
}

// splitFunnel — запись = воронка карточки за день: {"product":{"nmId"},"statistic":{"selected"}},
// ключ wb:analytics.funnel:{nmId}. Остальное в карточке (остатки, рейтинг, прошлый период) — состояние
// «на сейчас»: с ним каждое суточное перечитывание меняло бы запись при тех же цифрах дня.
func splitFunnel(page []byte) ([]fkhttp.FactRecord, error) {
	var day struct {
		Schema   string            `json:"schema"`
		Products []json.RawMessage `json:"products"`
	}
	if err := json.Unmarshal(page, &day); err != nil || day.Schema != wb.FunnelSchema {
		return nil, fmt.Errorf("facts: funnel page is not %s", wb.FunnelSchema)
	}
	out := make([]fkhttp.FactRecord, 0, len(day.Products))
	for i, raw := range day.Products {
		var row struct {
			Product struct {
				NmID int64 `json:"nmId"`
			} `json:"product"`
			Statistic struct {
				Selected json.RawMessage `json:"selected"`
			} `json:"statistic"`
		}
		if json.Unmarshal(raw, &row) != nil || row.Product.NmID <= 0 {
			return nil, fmt.Errorf("facts: funnel product %d has no nmId", i)
		}
		if row.Statistic.Selected == nil {
			row.Statistic.Selected = json.RawMessage("null")
		}
		payload, err := json.Marshal(map[string]any{
			"product":   map[string]int64{"nmId": row.Product.NmID},
			"statistic": map[string]json.RawMessage{"selected": row.Statistic.Selected},
		})
		if err != nil {
			return nil, fmt.Errorf("facts: encode funnel product %d: %w", i, err)
		}
		out = append(out, fkhttp.FactRecord{SourceKey: fmt.Sprintf("%s%d", funnelKeyPrefix, row.Product.NmID), Payload: payload})
	}
	return out, nil
}

// splitSearch — запись = карточка в поиске за день: {"nmId", показатель: значение дня | null},
// ключ wb:analytics.search:{nmId}. Динамика, рейтинг, цена и название — не цифры дня: с ними
// перечитывание меняло бы запись. Служебная страница — одна запись SearchUnavailableKey с кодом отказа.
func splitSearch(page []byte) ([]fkhttp.FactRecord, error) {
	var day struct {
		Schema      string                       `json:"schema"`
		Unavailable bool                         `json:"unavailable"`
		Status      int                          `json:"status"`
		Products    []map[string]json.RawMessage `json:"products"`
	}
	if err := json.Unmarshal(page, &day); err != nil || day.Schema != wb.SearchSchema {
		return nil, fmt.Errorf("facts: search page is not %s", wb.SearchSchema)
	}
	if day.Unavailable {
		return []fkhttp.FactRecord{{SourceKey: SearchUnavailableKey, Payload: fmt.Appendf(nil, `{"unavailable":true,"status":%d}`, day.Status)}}, nil
	}
	out := make([]fkhttp.FactRecord, 0, len(day.Products))
	for i, product := range day.Products {
		var nmID int64
		if json.Unmarshal(product["nmId"], &nmID) != nil || nmID <= 0 {
			return nil, fmt.Errorf("facts: search product %d has no nmId", i)
		}
		record := map[string]json.RawMessage{"nmId": product["nmId"]}
		for _, metric := range searchMetrics {
			var value struct {
				Current json.RawMessage `json:"current"`
			}
			_ = json.Unmarshal(product[metric], &value) // показателя нет либо он другой формы — «нет данных»
			if value.Current == nil {
				value.Current = json.RawMessage("null")
			}
			record[metric] = value.Current
		}
		payload, err := json.Marshal(record)
		if err != nil {
			return nil, fmt.Errorf("facts: encode search product %d: %w", i, err)
		}
		out = append(out, fkhttp.FactRecord{SourceKey: fmt.Sprintf("%s%d", searchKeyPrefix, nmID), Payload: payload})
	}
	return out, nil
}
