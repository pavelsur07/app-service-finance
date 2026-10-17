package store

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
)

// Статусы fetch_task.
const (
	StatusPending         = "pending"
	StatusRunning         = "running"
	StatusDone            = "done"
	StatusNotReady        = "not_ready"
	StatusFailedRetryable = "failed_retryable"
	StatusFailedPermanent = "failed_permanent"
)

// ErrNoTask — готовой задачи нет (или её перехватил другой воркер).
var ErrNoTask = errors.New("store: no task ready")

// ErrLeaseLost — lease задачи истёк и перехвачен; результат попытки не записан.
var ErrLeaseLost = errors.New("store: lease lost")

// TaskKey — идентичность задачи.
type TaskKey struct {
	ConnectionRef string
	ResourceType  string
	PeriodKey     string
}

// TaskSpec — постановка задачи планировщиком.
type TaskSpec struct {
	TaskKey
	Priority int
	// Refresh — перевести done или failed_permanent задачу в pending.
	Refresh bool
	RunAt   time.Time
}

// Task — захваченная задача.
type Task struct {
	TaskKey
	AttemptID      string
	Attempts       int
	Cursor         string
	NextPageNo     int
	PageHashes     []string // sha256 страниц текущей загрузки, включая прежние попытки
	ContentHash    string
	Revision       int
	LastErrorClass string
	LeaseUntil     time.Time
}

// Tasks — очередь fetch_task.
type Tasks struct {
	pool *Pool
}

func NewTasks(pool *Pool) *Tasks { return &Tasks{pool: pool} }

// Upsert ставит задачу идемпотентно: running не трогает, приоритет —
// максимум, done и failed_permanent переводит в pending только при Refresh
// (плановая перезагрузка повторяет и задачи, упавшие на ответе Ozon).
func (t *Tasks) Upsert(ctx context.Context, specs []TaskSpec) error {
	if len(specs) == 0 {
		return nil
	}
	return sendUpserts(ctx, t.pool, specs)
}

// batchSender — пул или транзакция.
type batchSender interface {
	SendBatch(ctx context.Context, b *pgx.Batch) pgx.BatchResults
}

func sendUpserts(ctx context.Context, conn batchSender, specs []TaskSpec) error {
	batch := &pgx.Batch{}
	for _, s := range specs {
		batch.Queue(`
			INSERT INTO fetch_task (connection_ref, resource_type, period_key, priority, next_run_at)
			VALUES ($1, $2, $3, $4, $5)
			ON CONFLICT (connection_ref, resource_type, period_key) DO UPDATE SET
				priority    = GREATEST(fetch_task.priority, EXCLUDED.priority),
				status      = CASE WHEN fetch_task.status IN ('done', 'failed_permanent') AND $6 THEN 'pending' ELSE fetch_task.status END,
				next_run_at = CASE
					WHEN fetch_task.status IN ('done', 'failed_permanent') AND $6 THEN EXCLUDED.next_run_at
					WHEN fetch_task.status IN ('pending', 'not_ready', 'failed_retryable') AND EXCLUDED.priority > fetch_task.priority
						THEN LEAST(fetch_task.next_run_at, EXCLUDED.next_run_at)
					ELSE fetch_task.next_run_at END,
				updated_at  = now()`,
			s.ConnectionRef, s.ResourceType, s.PeriodKey, s.Priority, s.RunAt, s.Refresh)
	}
	if err := conn.SendBatch(ctx, batch).Close(); err != nil {
		return fmt.Errorf("store: upsert tasks: %w", err)
	}
	return nil
}

// PlanOnce ставит specs, только если проход kind за runDate ещё не выполнялся:
// маркер planner_run и постановка — одна транзакция. false — уже выполнен.
func (t *Tasks) PlanOnce(ctx context.Context, kind, runDate string, specs []TaskSpec) (bool, error) {
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return false, fmt.Errorf("store: plan once begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	tag, err := tx.Exec(ctx, `INSERT INTO planner_run (kind, run_date) VALUES ($1, $2) ON CONFLICT DO NOTHING`, kind, runDate)
	if err != nil {
		return false, fmt.Errorf("store: plan once marker: %w", err)
	}
	if tag.RowsAffected() == 0 {
		return false, nil
	}
	if len(specs) > 0 {
		if err := sendUpserts(ctx, tx, specs); err != nil {
			return false, err
		}
	}
	if err := tx.Commit(ctx); err != nil {
		return false, fmt.Errorf("store: plan once commit: %w", err)
	}
	return true, nil
}

// ClaimNext захватывает готовую задачу одного из connectionRefs (активный
// снимок): кабинет без running-задачи и без паузы, ресурс не остановлен.
// Порядок — приоритет, давно не обслуживавшийся кабинет, время.
func (t *Tasks) ClaimNext(ctx context.Context, connectionRefs []string, owner, attemptID, traceID string, now time.Time, lease time.Duration) (Task, error) {
	if len(connectionRefs) == 0 {
		return Task{}, ErrNoTask
	}
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return Task{}, fmt.Errorf("store: claim begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var task Task
	var pageHashes string
	err = tx.QueryRow(ctx, `
		SELECT t.connection_ref, t.resource_type, t.period_key, t.attempts,
		       COALESCE(t.cursor, ''), t.next_page_no, t.page_hashes, COALESCE(t.content_hash, ''), t.revision,
		       COALESCE(t.last_error_class, '')
		FROM fetch_task t
		LEFT JOIN connection_state cs ON cs.connection_ref = t.connection_ref
		WHERE t.status IN ('pending', 'not_ready', 'failed_retryable')
		  AND t.next_run_at <= $2
		  AND t.connection_ref = ANY($1)
		  AND (cs.suspended_until IS NULL OR cs.suspended_until <= $2)
		  AND NOT EXISTS (SELECT 1 FROM fetch_task r WHERE r.connection_ref = t.connection_ref AND r.status = 'running')
		  AND NOT EXISTS (SELECT 1 FROM resource_stop s WHERE s.resource_type = t.resource_type)
		ORDER BY t.priority DESC, cs.last_served_at ASC NULLS FIRST, t.next_run_at ASC
		LIMIT 1
		FOR UPDATE OF t SKIP LOCKED`,
		connectionRefs, now,
	).Scan(&task.ConnectionRef, &task.ResourceType, &task.PeriodKey, &task.Attempts,
		&task.Cursor, &task.NextPageNo, &pageHashes, &task.ContentHash, &task.Revision, &task.LastErrorClass)
	if errors.Is(err, pgx.ErrNoRows) {
		return Task{}, ErrNoTask
	}
	if err != nil {
		return Task{}, fmt.Errorf("store: claim select: %w", err)
	}

	if pageHashes != "" {
		task.PageHashes = strings.Split(pageHashes, ",")
	}
	task.AttemptID = attemptID
	task.Attempts++
	task.LeaseUntil = now.Add(lease)
	if _, err := tx.Exec(ctx, `
		UPDATE fetch_task SET status = 'running', attempts = attempts + 1, lease_owner = $4,
			lease_until = $5, attempt_id = $6, updated_at = $7
		WHERE connection_ref = $1 AND resource_type = $2 AND period_key = $3`,
		task.ConnectionRef, task.ResourceType, task.PeriodKey, owner, task.LeaseUntil, attemptID, now,
	); err != nil {
		if isUniqueViolation(err) {
			// Другой воркер успел захватить задачу этого кабинета.
			return Task{}, ErrNoTask
		}
		return Task{}, fmt.Errorf("store: claim update: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		INSERT INTO fetch_attempt (attempt_id, connection_ref, resource_type, period_key, trace_id, lease_owner, started_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7)`,
		attemptID, task.ConnectionRef, task.ResourceType, task.PeriodKey, traceID, owner, now,
	); err != nil {
		return Task{}, fmt.Errorf("store: claim attempt: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		INSERT INTO connection_state (connection_ref, last_served_at, updated_at) VALUES ($1, $2, $2)
		ON CONFLICT (connection_ref) DO UPDATE SET last_served_at = EXCLUDED.last_served_at, updated_at = EXCLUDED.updated_at`,
		task.ConnectionRef, now,
	); err != nil {
		return Task{}, fmt.Errorf("store: claim served: %w", err)
	}
	if err := tx.Commit(ctx); err != nil {
		if isUniqueViolation(err) {
			return Task{}, ErrNoTask
		}
		return Task{}, fmt.Errorf("store: claim commit: %w", err)
	}
	return task, nil
}

// ExtendLease продлевает lease своей попытки.
func (t *Tasks) ExtendLease(ctx context.Context, key TaskKey, attemptID string, until time.Time) error {
	tag, err := t.pool.Exec(ctx, `
		UPDATE fetch_task SET lease_until = $5, updated_at = now()
		WHERE connection_ref = $1 AND resource_type = $2 AND period_key = $3 AND status = 'running' AND attempt_id = $4`,
		key.ConnectionRef, key.ResourceType, key.PeriodKey, attemptID, until)
	if err != nil {
		return fmt.Errorf("store: extend lease: %w", err)
	}
	if tag.RowsAffected() == 0 {
		return ErrLeaseLost
	}
	return nil
}

// RawObject — сохранённая в S3 страница.
type RawObject struct {
	PageNo      int
	StorageURI  string
	SHA256      string
	SizeBytes   int64
	StoredBytes int64
	ContentType string
}

// RecordPage — страница и курсор следующей одной транзакцией.
func (t *Tasks) RecordPage(ctx context.Context, key TaskKey, attemptID string, obj RawObject, nextCursor string) error {
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return fmt.Errorf("store: record page begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	tag, err := tx.Exec(ctx, `
		UPDATE fetch_task SET cursor = NULLIF($5, ''), next_page_no = $6,
			page_hashes = CASE WHEN page_hashes = '' THEN $7 ELSE page_hashes || ',' || $7 END, updated_at = now()
		WHERE connection_ref = $1 AND resource_type = $2 AND period_key = $3 AND status = 'running' AND attempt_id = $4`,
		key.ConnectionRef, key.ResourceType, key.PeriodKey, attemptID, nextCursor, obj.PageNo+1, obj.SHA256)
	if err != nil {
		return fmt.Errorf("store: record page cursor: %w", err)
	}
	if tag.RowsAffected() == 0 {
		return ErrLeaseLost
	}
	if _, err := tx.Exec(ctx, `
		INSERT INTO raw_object (attempt_id, page_no, storage_uri, sha256, size_bytes, stored_bytes, content_type)
		VALUES ($1, $2, $3, $4, $5, $6, $7)`,
		attemptID, obj.PageNo, obj.StorageURI, obj.SHA256, obj.SizeBytes, obj.StoredBytes, obj.ContentType); err != nil {
		return fmt.Errorf("store: record page object: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		UPDATE fetch_attempt SET pages = pages + 1, bytes = bytes + $2 WHERE attempt_id = $1`,
		attemptID, obj.SizeBytes); err != nil {
		return fmt.Errorf("store: record page attempt: %w", err)
	}
	if err := tx.Commit(ctx); err != nil {
		return fmt.Errorf("store: record page commit: %w", err)
	}
	return nil
}

// Outcome — итог попытки для FinishTask.
type Outcome struct {
	Status      string // done | not_ready | failed_retryable | failed_permanent | pending (released)
	Label       string // outcome в fetch_attempt: done | unchanged | <класс ошибки> | released
	ErrorClass  string
	HTTPStatus  int
	NextRunAt   time.Time // для не-done
	ContentHash string    // для done
	// KeepCursor — повтор продолжит с сохранённого курсора (released, transient).
	KeepCursor bool
}

// FinishTask закрывает попытку. Новый content_hash — revision+1; тот же — без изменений.
// Возвращает итоговую revision.
func (t *Tasks) FinishTask(ctx context.Context, key TaskKey, attemptID string, out Outcome, now time.Time) (int, error) {
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return 0, fmt.Errorf("store: finish begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var revision int
	err = tx.QueryRow(ctx, `
		UPDATE fetch_task SET
			status       = $5,
			lease_owner  = NULL, lease_until = NULL, attempt_id = NULL,
			next_run_at  = CASE WHEN $5 = 'done' THEN next_run_at ELSE $6 END,
			cursor       = CASE WHEN $7 THEN cursor ELSE NULL END,
			next_page_no = CASE WHEN $7 THEN next_page_no ELSE 1 END,
			page_hashes  = CASE WHEN $7 THEN page_hashes ELSE '' END,
			revision     = CASE WHEN $5 = 'done' AND content_hash IS DISTINCT FROM NULLIF($8, '') THEN revision + 1 ELSE revision END,
			content_hash = CASE WHEN $5 = 'done' THEN NULLIF($8, '') ELSE content_hash END,
			attempts     = CASE WHEN $5 = 'done' THEN 0 ELSE attempts END,
			last_error_class = NULLIF($9, ''),
			last_success_at  = CASE WHEN $5 = 'done' THEN $10 ELSE last_success_at END,
			updated_at   = $10
		WHERE connection_ref = $1 AND resource_type = $2 AND period_key = $3 AND status = 'running' AND attempt_id = $4
		RETURNING revision`,
		key.ConnectionRef, key.ResourceType, key.PeriodKey, attemptID,
		out.Status, out.NextRunAt, out.KeepCursor, out.ContentHash, out.ErrorClass, now,
	).Scan(&revision)
	if errors.Is(err, pgx.ErrNoRows) {
		return 0, ErrLeaseLost
	}
	if err != nil {
		return 0, fmt.Errorf("store: finish task: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		UPDATE fetch_attempt SET finished_at = $2, outcome = $3, http_status = NULLIF($4, 0) WHERE attempt_id = $1`,
		attemptID, now, out.Label, out.HTTPStatus); err != nil {
		return 0, fmt.Errorf("store: finish attempt: %w", err)
	}
	if err := tx.Commit(ctx); err != nil {
		return 0, fmt.Errorf("store: finish commit: %w", err)
	}
	return revision, nil
}

// ReleaseExpired возвращает в pending задачи с истёкшим lease; курсор
// сохраняется — следующий воркер продолжит с той же страницы.
//
// Один запрос: строки задач блокируются первыми (тот же порядок, что у
// RecordPage/FinishTask: задача → попытка), занятые воркером пропускаются
// (SKIP LOCKED), и попытки закрываются ровно для освобождённого набора —
// продление lease между выборками не может рассинхронизировать задачу и попытку.
func (t *Tasks) ReleaseExpired(ctx context.Context, now time.Time) (int64, error) {
	var released int64
	err := t.pool.QueryRow(ctx, `
		WITH expired AS (
			SELECT connection_ref, resource_type, period_key, attempt_id
			FROM fetch_task
			WHERE status = 'running' AND lease_until < $1
			FOR UPDATE SKIP LOCKED
		), released AS (
			UPDATE fetch_task t SET status = 'pending', lease_owner = NULL, lease_until = NULL, attempt_id = NULL,
				next_run_at = $1, last_error_class = 'lease_expired', updated_at = $1
			FROM expired e
			WHERE t.connection_ref = e.connection_ref AND t.resource_type = e.resource_type AND t.period_key = e.period_key
			RETURNING e.attempt_id
		), closed AS (
			UPDATE fetch_attempt a SET finished_at = $1, outcome = 'lease_expired'
			FROM released r
			WHERE a.attempt_id = r.attempt_id
			RETURNING 1
		)
		SELECT count(*) FROM released`, now).Scan(&released)
	if err != nil {
		return 0, fmt.Errorf("store: release expired: %w", err)
	}
	return released, nil
}

func isUniqueViolation(err error) bool {
	var pgErr *pgconn.PgError
	return errors.As(err, &pgErr) && pgErr.Code == "23505"
}
