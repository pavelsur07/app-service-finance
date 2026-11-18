package facts

import (
	"encoding/json"
	"errors"
	"fmt"

	fkhttp "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/httpapi"
)

// ResourceFunnel — воронка продаж по товарам за день (executor.ResourceFunnel).
const ResourceFunnel = "ozon.analytics.funnel"

const funnelKeyPrefix = "ozon:analytics.funnel:"

// FunnelUnavailableKey — запись дня, который Ozon не отдал по тарифу кабинета
// (служебная страница executor.FunnelSkippedPage): «нет данных», а не нули.
const FunnelUnavailableKey = funnelKeyPrefix + "unavailable"

// splitFunnel — запись = строка SKU дня как в ответе: {"dimensions","metrics"},
// метрики — по порядку ozon.FunnelMetrics (порядок — часть контракта).
func splitFunnel(page []byte) ([]fkhttp.FactRecord, error) {
	var shape struct {
		Skipped bool `json:"subscription_required"`
		Result  *struct {
			Data []json.RawMessage `json:"data"`
		} `json:"result"`
	}
	if err := json.Unmarshal(page, &shape); err != nil {
		return nil, fmt.Errorf("facts: funnel page: %w", err)
	}
	if shape.Skipped {
		return []fkhttp.FactRecord{{SourceKey: FunnelUnavailableKey, Payload: page}}, nil
	}
	if shape.Result == nil {
		return nil, errors.New(`facts: funnel page has no "result" object`)
	}
	out := make([]fkhttp.FactRecord, 0, len(shape.Result.Data))
	for i, raw := range shape.Result.Data {
		var row struct {
			Dimensions []struct {
				ID string `json:"id"`
			} `json:"dimensions"`
		}
		if json.Unmarshal(raw, &row) != nil || len(row.Dimensions) == 0 || !keyPart.MatchString(row.Dimensions[0].ID) {
			return nil, fmt.Errorf("facts: funnel row %d has no usable sku", i)
		}
		out = append(out, fkhttp.FactRecord{SourceKey: funnelKeyPrefix + row.Dimensions[0].ID, Payload: raw})
	}
	return out, nil
}
