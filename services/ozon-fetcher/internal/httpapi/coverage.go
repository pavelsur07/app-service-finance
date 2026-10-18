package httpapi

import (
	"log/slog"
	"net/http"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

// Контракт ответа — services/_contracts/service-coverage/v1.
const (
	coverageSchemaVersion = "1"
	coverageService       = "ozon-fetcher"
	// MaxCoverageDays — окно M-2…M занимает до 92 дней.
	MaxCoverageDays = 93
)

type coverageResponse struct {
	SchemaVersion string        `json:"schema_version"`
	Service       string        `json:"service"`
	GeneratedAt   string        `json:"generated_at"`
	From          string        `json:"from"`
	To            string        `json:"to"`
	CabinetsTotal int           `json:"cabinets_total"`
	Rows          []coverageRow `json:"rows"`
}

type coverageRow struct {
	ResourceType     string         `json:"resource_type"`
	PeriodKey        string         `json:"period_key"`
	CabinetsExpected int            `json:"cabinets_expected"`
	ByStatus         map[string]int `json:"by_status"`
	LastSuccessAt    *string        `json:"last_success_at"`
}

func coverage(w http.ResponseWriter, r *http.Request, d Deps) {
	if d.Coverage == nil || d.Cabinets == nil {
		writeError(w, http.StatusServiceUnavailable, "ingestion_disabled", "loading is disabled")
		return
	}
	now := d.Now()
	from, to, ok := coverageWindow(r, now.In(d.Location), d.Location)
	if !ok {
		writeError(w, http.StatusUnprocessableEntity, "invalid_period", "from/to must be YYYY-MM-DD, from <= to, at most 93 days")
		return
	}

	// Кабинет без ключа Seller API планировщик не загружает — и не ожидается.
	var refs []string
	expected := map[string]int{}
	for _, cab := range d.Cabinets() {
		if cab.Seller == nil {
			continue
		}
		refs = append(refs, cab.Ref)
		seen := map[string]bool{}
		for _, rt := range cab.ResourceTypes {
			if !seen[rt] {
				seen[rt] = true
				expected[rt]++
			}
		}
	}

	rows, err := d.Coverage(r.Context(), refs, from.Format(time.DateOnly), to.Format(time.DateOnly))
	if err != nil {
		d.Logger.Warn("coverage failed", slog.String("stage", "coverage"), slog.String("error", err.Error()))
		writeError(w, http.StatusServiceUnavailable, "coverage_unavailable", "coverage is unavailable")
		return
	}
	writeJSON(w, http.StatusOK, coverageResponse{
		SchemaVersion: coverageSchemaVersion,
		Service:       coverageService,
		GeneratedAt:   now.UTC().Format(time.RFC3339),
		From:          from.Format(time.DateOnly),
		To:            to.Format(time.DateOnly),
		CabinetsTotal: len(refs),
		Rows:          toCoverageRows(rows, expected),
	})
}

// coverageWindow — from/to из запроса; по умолчанию окно сервиса M-2…конец M.
func coverageWindow(r *http.Request, local time.Time, loc *time.Location) (time.Time, time.Time, bool) {
	monthStart := time.Date(local.Year(), local.Month(), 1, 0, 0, 0, 0, loc)
	from, to := monthStart.AddDate(0, -2, 0), monthStart.AddDate(0, 1, -1)
	q := r.URL.Query()
	for _, p := range []struct {
		name string
		dst  *time.Time
	}{{"from", &from}, {"to", &to}} {
		if q.Has(p.name) {
			v, err := time.ParseInLocation(time.DateOnly, q.Get(p.name), loc)
			if err != nil {
				return time.Time{}, time.Time{}, false
			}
			*p.dst = v
		}
	}
	// Окно — включительно: дней в нём to-from+1.
	if from.After(to) || to.Sub(from) >= MaxCoverageDays*24*time.Hour {
		return time.Time{}, time.Time{}, false
	}
	return from, to, true
}

func toCoverageRows(rows []store.CoverageRow, expected map[string]int) []coverageRow {
	out := make([]coverageRow, 0, len(rows))
	for _, row := range rows {
		item := coverageRow{ResourceType: row.ResourceType, PeriodKey: row.PeriodKey, CabinetsExpected: expected[row.ResourceType], ByStatus: row.ByStatus}
		if row.LastSuccessAt != nil {
			v := row.LastSuccessAt.UTC().Format(time.RFC3339)
			item.LastSuccessAt = &v
		}
		out = append(out, item)
	}
	return out
}
