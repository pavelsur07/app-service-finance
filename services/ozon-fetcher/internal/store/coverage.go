package store

import (
	"context"
	"fmt"
	"sort"
	"time"
)

// CoverageStatuses — статусы fetch_task в порядке контракта service-coverage v1.
var CoverageStatuses = []string{StatusDone, StatusRunning, StatusPending, StatusNotReady, StatusFailedRetryable, StatusFailedPermanent}

// CoverageRow — ресурс × период: число кабинетов по статусам задач.
type CoverageRow struct {
	ResourceType  string
	PeriodKey     string
	ByStatus      map[string]int
	LastSuccessAt *time.Time
}

// Coverage — сводка задач активных кабинетов (connectionRefs) за окно
// [from; to] (YYYY-MM-DD): дни окна, месяцы, на которые оно попадает, и
// справочники (period_key = 'ref'). Один запрос, без идентификаторов кабинетов
// в результате.
func (t *Tasks) Coverage(ctx context.Context, connectionRefs []string, from, to string) ([]CoverageRow, error) {
	if len(connectionRefs) == 0 {
		return []CoverageRow{}, nil
	}
	rows, err := t.pool.Query(ctx, `
		SELECT resource_type, period_key, status, count(*), max(last_success_at)
		FROM fetch_task
		WHERE connection_ref = ANY($1)
		  AND (period_key = 'ref'
		       OR (length(period_key) = 10 AND period_key BETWEEN $2 AND $3)
		       OR (length(period_key) = 7 AND period_key BETWEEN left($2, 7) AND left($3, 7)))
		GROUP BY resource_type, period_key, status`,
		connectionRefs, from, to)
	if err != nil {
		return nil, fmt.Errorf("store: coverage: %w", err)
	}
	defer rows.Close()

	byKey := map[[2]string]*CoverageRow{}
	for rows.Next() {
		var resource, period, status string
		var n int
		var last *time.Time
		if err := rows.Scan(&resource, &period, &status, &n, &last); err != nil {
			return nil, fmt.Errorf("store: coverage scan: %w", err)
		}
		k := [2]string{resource, period}
		row, ok := byKey[k]
		if !ok {
			row = &CoverageRow{ResourceType: resource, PeriodKey: period, ByStatus: map[string]int{}}
			for _, s := range CoverageStatuses {
				row.ByStatus[s] = 0
			}
			byKey[k] = row
		}
		row.ByStatus[status] += n
		if last != nil && (row.LastSuccessAt == nil || last.After(*row.LastSuccessAt)) {
			row.LastSuccessAt = last
		}
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("store: coverage: %w", err)
	}

	out := make([]CoverageRow, 0, len(byKey))
	for _, r := range byKey {
		out = append(out, *r)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].ResourceType != out[j].ResourceType {
			return out[i].ResourceType < out[j].ResourceType
		}
		return out[i].PeriodKey < out[j].PeriodKey
	})
	return out, nil
}
