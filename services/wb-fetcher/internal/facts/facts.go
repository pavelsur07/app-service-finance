// Package facts — публикация загруженного Symfony фактами
// (services/_contracts/source-fact-envelope/v1): какие ресурсы публикуются и
// как страница режется на записи. Строки отчёта не разбираются — это делает
// Symfony (MarketplaceWb).
package facts

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"

	fkhttp "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/httpapi"
)

// ResourceDaily — дневной отчёт о продажах (executor.ResourceDaily; здесь —
// чтобы app не тянул executor ради списка).
const ResourceDaily = "wb.finance.sales-report.daily"

// Resources — ресурсы, чьи ревизии публикуются фактами.
var Resources = []string{ResourceDaily}

const dailyKeyPrefix = "wb:sales-report.daily:"

var rrdID = regexp.MustCompile(`^[0-9]{1,20}$`)

// Split режет страницу (JSON-массив строк отчёта как есть) на записи; пустая
// последняя страница `[]` — ноль записей.
func Split(resourceType string, page []byte) ([]fkhttp.FactRecord, error) {
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
