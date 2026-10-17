package store

import (
	"context"
	"fmt"
	"time"
)

// Limit — сохранённый лимит пары (кабинет, endpoint); 0 — не задано.
type Limit struct {
	ConnectionRef string
	Endpoint      string
	Override      float64
	Current       float64
}

// LoadLimits — все сохранённые лимиты (для ratelimit.Registry.Seed при старте).
func (t *Tasks) LoadLimits(ctx context.Context) ([]Limit, error) {
	rows, err := t.pool.Query(ctx, `
		SELECT connection_ref, endpoint, COALESCE(rps_override, 0)::float8, COALESCE(rps_current, 0)::float8
		FROM connection_limit`)
	if err != nil {
		return nil, fmt.Errorf("store: load limits: %w", err)
	}
	defer rows.Close()
	var limits []Limit
	for rows.Next() {
		var l Limit
		if err := rows.Scan(&l.ConnectionRef, &l.Endpoint, &l.Override, &l.Current); err != nil {
			return nil, fmt.Errorf("store: scan limit: %w", err)
		}
		limits = append(limits, l)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("store: load limits: %w", err)
	}
	return limits, nil
}

// SaveCurrentRate — адаптированная скорость пары (ratelimit OnChange).
func (t *Tasks) SaveCurrentRate(ctx context.Context, connectionRef, endpoint string, rps float64) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO connection_limit (connection_ref, endpoint, rps_current, updated_at) VALUES ($1, $2, $3, now())
		ON CONFLICT (connection_ref, endpoint) DO UPDATE SET rps_current = EXCLUDED.rps_current, updated_at = now()`,
		connectionRef, endpoint, rps); err != nil {
		return fmt.Errorf("store: save rate: %w", err)
	}
	return nil
}

// SetOverride — ручное значение лимита пары (0 — снять).
func (t *Tasks) SetOverride(ctx context.Context, connectionRef, endpoint string, rps float64) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO connection_limit (connection_ref, endpoint, rps_override, updated_at) VALUES ($1, $2, NULLIF($3, 0::float8), now())
		ON CONFLICT (connection_ref, endpoint) DO UPDATE SET rps_override = EXCLUDED.rps_override, updated_at = now()`,
		connectionRef, endpoint, rps); err != nil {
		return fmt.Errorf("store: set override: %w", err)
	}
	return nil
}

// SuspendConnection — пауза кабинета (auth): задачи не выдаются до until.
func (t *Tasks) SuspendConnection(ctx context.Context, connectionRef, reason string, until time.Time) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO connection_state (connection_ref, suspended_until, suspend_reason, updated_at) VALUES ($1, $2, $3, now())
		ON CONFLICT (connection_ref) DO UPDATE SET suspended_until = EXCLUDED.suspended_until,
			suspend_reason = EXCLUDED.suspend_reason, updated_at = now()`,
		connectionRef, until, reason); err != nil {
		return fmt.Errorf("store: suspend connection: %w", err)
	}
	return nil
}

// ResumeConnection снимает паузу (успешный запрос после смены ключа).
func (t *Tasks) ResumeConnection(ctx context.Context, connectionRef string) error {
	if _, err := t.pool.Exec(ctx, `
		UPDATE connection_state SET suspended_until = NULL, suspend_reason = NULL, updated_at = now()
		WHERE connection_ref = $1 AND suspended_until IS NOT NULL`, connectionRef); err != nil {
		return fmt.Errorf("store: resume connection: %w", err)
	}
	return nil
}

// StopResource — ресурс снят у провайдера: задачи не выдаются ни одному кабинету.
func (t *Tasks) StopResource(ctx context.Context, resourceType, reason string) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO resource_stop (resource_type, reason) VALUES ($1, $2) ON CONFLICT (resource_type) DO NOTHING`,
		resourceType, reason); err != nil {
		return fmt.Errorf("store: stop resource: %w", err)
	}
	return nil
}
