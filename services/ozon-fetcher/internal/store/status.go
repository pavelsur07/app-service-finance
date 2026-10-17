package store

import (
	"context"
	"fmt"
	"sort"
	"time"
)

// MaxMissingListed — сколько пропущенных периодов перечислять на ресурс.
const MaxMissingListed = 31

// StatusReport — состояние загрузки для /internal/fetch-status. Без сумм,
// ключей и тела ответов.
type StatusReport struct {
	Cabinets         []CabinetStatus `json:"cabinets"`
	StoppedResources []string        `json:"stopped_resources"`
}

type CabinetStatus struct {
	ConnectionRef  string           `json:"connection_ref"`
	SuspendedUntil *time.Time       `json:"suspended_until"`
	SuspendReason  string           `json:"suspend_reason,omitempty"`
	AuthFailures   int              `json:"auth_failures"`
	Resources      []ResourceStatus `json:"resources"`
	Limits         []Limit          `json:"limits"`
	Outcomes24h    map[string]int   `json:"outcomes_24h"`
}

type ResourceStatus struct {
	ResourceType   string         `json:"resource_type"`
	ByStatus       map[string]int `json:"by_status"`
	LastSuccessAt  *time.Time     `json:"last_success_at"`
	Revised        int            `json:"revised"` // задачи, у которых источник менялся (revision > 1)
	MissingPeriods []string       `json:"missing_periods"`
	MissingCount   int            `json:"missing_count"`
}

// Status собирает отчёт; пропуски считаются по периодам >= windowFrom.
func (t *Tasks) Status(ctx context.Context, now time.Time, windowFrom string) (StatusReport, error) {
	cabinets := map[string]*CabinetStatus{}
	cab := func(ref string) *CabinetStatus {
		if c, ok := cabinets[ref]; ok {
			return c
		}
		c := &CabinetStatus{ConnectionRef: ref, Outcomes24h: map[string]int{}}
		cabinets[ref] = c
		return c
	}
	resources := map[[2]string]*ResourceStatus{}
	res := func(ref, resource string) *ResourceStatus {
		k := [2]string{ref, resource}
		if r, ok := resources[k]; ok {
			return r
		}
		r := &ResourceStatus{ResourceType: resource, ByStatus: map[string]int{}, MissingPeriods: []string{}}
		resources[k] = r
		cab(ref)
		return r
	}

	rows, err := t.pool.Query(ctx, `
		SELECT connection_ref, resource_type, status, count(*), max(last_success_at), count(*) FILTER (WHERE revision > 1)
		FROM fetch_task GROUP BY 1, 2, 3`)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status tasks: %w", err)
	}
	for rows.Next() {
		var ref, resource, status string
		var n, revised int
		var last *time.Time
		if err := rows.Scan(&ref, &resource, &status, &n, &last, &revised); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status tasks scan: %w", err)
		}
		r := res(ref, resource)
		r.ByStatus[status] = n
		r.Revised += revised
		if last != nil && (r.LastSuccessAt == nil || last.After(*r.LastSuccessAt)) {
			r.LastSuccessAt = last
		}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status tasks: %w", err)
	}

	rows, err = t.pool.Query(ctx, `
		SELECT connection_ref, resource_type, period_key FROM fetch_task
		WHERE status <> 'done' AND period_key <> 'ref'
		  AND period_key >= CASE WHEN length(period_key) = 7 THEN left($1, 7) ELSE $1 END -- YYYY-MM и YYYY-MM-DD
		ORDER BY connection_ref, resource_type, period_key`, windowFrom)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status missing: %w", err)
	}
	for rows.Next() {
		var ref, resource, period string
		if err := rows.Scan(&ref, &resource, &period); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status missing scan: %w", err)
		}
		r := res(ref, resource)
		r.MissingCount++
		if len(r.MissingPeriods) < MaxMissingListed {
			r.MissingPeriods = append(r.MissingPeriods, period)
		}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status missing: %w", err)
	}

	rows, err = t.pool.Query(ctx, `SELECT connection_ref, suspended_until, COALESCE(suspend_reason, ''), auth_failures FROM connection_state`)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status state: %w", err)
	}
	for rows.Next() {
		var ref, reason string
		var until *time.Time
		var failures int
		if err := rows.Scan(&ref, &until, &reason, &failures); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status state scan: %w", err)
		}
		c := cab(ref)
		if until != nil && until.After(now) {
			c.SuspendedUntil, c.SuspendReason = until, reason
		}
		c.AuthFailures = failures
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status state: %w", err)
	}

	limits, err := t.LoadLimits(ctx)
	if err != nil {
		return StatusReport{}, err
	}
	for _, l := range limits {
		c := cab(l.ConnectionRef)
		c.Limits = append(c.Limits, l)
	}

	rows, err = t.pool.Query(ctx, `
		SELECT connection_ref, COALESCE(outcome, 'running'), count(*) FROM fetch_attempt
		WHERE started_at >= $1 GROUP BY 1, 2`, now.Add(-24*time.Hour))
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status attempts: %w", err)
	}
	for rows.Next() {
		var ref, outcome string
		var n int
		if err := rows.Scan(&ref, &outcome, &n); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status attempts scan: %w", err)
		}
		cab(ref).Outcomes24h[outcome] = n
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status attempts: %w", err)
	}

	report := StatusReport{StoppedResources: []string{}}
	rows, err = t.pool.Query(ctx, `SELECT resource_type FROM resource_stop ORDER BY 1`)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status stops: %w", err)
	}
	for rows.Next() {
		var r string
		if err := rows.Scan(&r); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status stops scan: %w", err)
		}
		report.StoppedResources = append(report.StoppedResources, r)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status stops: %w", err)
	}

	for k, r := range resources {
		c := cabinets[k[0]]
		c.Resources = append(c.Resources, *r)
	}
	for _, c := range cabinets {
		sort.Slice(c.Resources, func(i, j int) bool { return c.Resources[i].ResourceType < c.Resources[j].ResourceType })
		if c.Resources == nil {
			c.Resources = []ResourceStatus{}
		}
		if c.Limits == nil {
			c.Limits = []Limit{}
		}
		report.Cabinets = append(report.Cabinets, *c)
	}
	sort.Slice(report.Cabinets, func(i, j int) bool { return report.Cabinets[i].ConnectionRef < report.Cabinets[j].ConnectionRef })
	if report.Cabinets == nil {
		report.Cabinets = []CabinetStatus{}
	}
	return report, nil
}
