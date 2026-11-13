// Package facts — публикация загруженного Symfony фактами
// (services/_contracts/source-fact-envelope/v1): какие ресурсы публикуются и
// как страница ресурса режется на записи. Смысла записей пакет не касается —
// разбор начислений делает Symfony.
package facts

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"

	fkhttp "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/httpapi"
)

// ResourceAccrualByDay — начисления дня (executor.ResourceAccrualByDay; здесь —
// чтобы ozonstore не зависел от executor).
const ResourceAccrualByDay = "ozon.finance.accrual-by-day"

// Resources — ресурсы, чьи ревизии публикуются фактами. Новый ресурс — правкой
// списка, разбором в Split и CHANGELOG контракта.
var Resources = []string{ResourceAccrualByDay}

const accrualKeyPrefix = "ozon:accrual-by-day:"

// accrualID — идентификатор начисления, пригодный в ключ как есть.
var accrualID = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,128}$`)

// Split режет страницу ресурса на записи с ключом source_key.
func Split(resourceType string, page []byte) ([]fkhttp.FactRecord, error) {
	if resourceType != ResourceAccrualByDay {
		return nil, fmt.Errorf("facts: resource %q is not published", resourceType)
	}
	var shape struct {
		Accruals []json.RawMessage `json:"accruals"`
	}
	if err := json.Unmarshal(page, &shape); err != nil {
		return nil, fmt.Errorf("facts: accrual-by-day page: %w", err)
	}
	if shape.Accruals == nil {
		return nil, errors.New(`facts: accrual-by-day page has no "accruals" array`)
	}
	out := make([]fkhttp.FactRecord, 0, len(shape.Accruals))
	for i, raw := range shape.Accruals {
		key, err := accrualKey(raw)
		if err != nil {
			return nil, fmt.Errorf("facts: accrual %d: %w", i, err)
		}
		out = append(out, fkhttp.FactRecord{SourceKey: key, Payload: raw})
	}
	return out, nil
}

// accrualKey — ozon:accrual-by-day:{accrual_id}; начисление без пригодного
// идентификатора получает ключ от своего содержимого (fallback-{sha256[:16]}):
// стабилен, пока запись не меняется.
func accrualKey(raw json.RawMessage) (string, error) {
	var row struct {
		AccrualID json.RawMessage `json:"accrual_id"`
	}
	if err := json.Unmarshal(raw, &row); err != nil {
		return "", fmt.Errorf("not an object: %w", err)
	}
	// Идентификатор приходит строкой; число — тоже допустимо.
	id := strings.Trim(string(row.AccrualID), `"`)
	if accrualID.MatchString(id) && id != "null" {
		return accrualKeyPrefix + id, nil
	}
	sum := sha256.Sum256(raw)
	return accrualKeyPrefix + "fallback-" + hex.EncodeToString(sum[:])[:16], nil
}
