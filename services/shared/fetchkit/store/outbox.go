package store

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
)

// Публикация фактов (services/_contracts/source-fact-envelope/v1): пачка —
// одна загруженная ревизия одного периода кабинета. В outbox_batch лежит
// только указатель на страницы ревизии (sha256), данных источника в БД нет.

// ErrNoBatch — пачки с таким batch_id нет.
var ErrNoBatch = errors.New("store: no such fact batch")

// FactBatch — пачка фактов: ревизия Revision задачи TaskKey.
type FactBatch struct {
	BatchID string
	TaskKey
	Revision    int
	ContentHash string
	PageHashes  []string // sha256 страниц ревизии по порядку
	CreatedAt   time.Time
}

type factBatchPointer struct {
	ConnectionRef string `json:"connection_ref"`
	ResourceType  string `json:"resource_type"`
	PeriodKey     string `json:"period_key"`
	Revision      int    `json:"revision"`
	Pages         string `json:"pages"`
}

// FactBatchID — идентификатор пачки: стабилен для ревизии, поэтому повторная
// постановка той же ревизии ничего не создаёт.
func FactBatchID(key TaskKey, revision int) string {
	return key.ConnectionRef + ":" + key.ResourceType + ":" + key.PeriodKey + ":r" + strconv.Itoa(revision)
}

func enqueueFactBatch(ctx context.Context, tx pgx.Tx, key TaskKey, revision int, contentHash, pages string, now time.Time) error {
	payload, err := json.Marshal(factBatchPointer{ConnectionRef: key.ConnectionRef, ResourceType: key.ResourceType,
		PeriodKey: key.PeriodKey, Revision: revision, Pages: pages})
	if err != nil {
		return fmt.Errorf("store: fact batch payload: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		INSERT INTO outbox_batch (batch_id, envelope_hash, payload, created_at) VALUES ($1, $2, $3, $4)
		ON CONFLICT (batch_id) DO NOTHING`, FactBatchID(key, revision), contentHash, payload, now); err != nil {
		return fmt.Errorf("store: enqueue fact batch: %w", err)
	}
	return nil
}

// NewFactBatches — неподтверждённые пачки ресурса после курсора (createdAt,
// batchID), по порядку постановки. Нулевой курсор — с начала.
func (t *Tasks) NewFactBatches(ctx context.Context, resourceType string, afterCreatedAt time.Time, afterBatchID string, limit int) ([]FactBatch, error) {
	rows, err := t.pool.Query(ctx, `
		SELECT batch_id, envelope_hash, payload, created_at FROM outbox_batch
		WHERE status = 'new' AND payload->>'resource_type' = $1 AND (created_at, batch_id) > ($2, $3)
		ORDER BY created_at, batch_id LIMIT $4`, resourceType, afterCreatedAt, afterBatchID, limit)
	if err != nil {
		return nil, fmt.Errorf("store: new fact batches: %w", err)
	}
	defer rows.Close()
	var out []FactBatch
	for rows.Next() {
		b, err := scanFactBatch(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, b)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("store: new fact batches: %w", err)
	}
	return out, nil
}

// FactBatch — пачка по идентификатору в любом статусе; нет — ErrNoBatch.
func (t *Tasks) FactBatch(ctx context.Context, batchID string) (FactBatch, error) {
	b, err := scanFactBatch(t.pool.QueryRow(ctx, `
		SELECT batch_id, envelope_hash, payload, created_at FROM outbox_batch WHERE batch_id = $1`, batchID))
	if errors.Is(err, pgx.ErrNoRows) {
		return FactBatch{}, ErrNoBatch
	}
	return b, err
}

// FactBatchPage — объект страницы pageNo (с 1) пачки. Номер вне пачки —
// ErrNoBatch; страница без объекта — ErrMissingObject.
func (t *Tasks) FactBatchPage(ctx context.Context, b FactBatch, pageNo int) (RawObject, error) {
	if pageNo < 1 || pageNo > len(b.PageHashes) {
		return RawObject{}, ErrNoBatch
	}
	objects, err := t.pageObjects(ctx, b.TaskKey, b.PageHashes)
	if err != nil {
		return RawObject{}, err
	}
	return objects[pageNo-1], nil
}

// AckFactBatches подтверждает получение: пачки больше не выдаются. Повтор и
// неизвестные идентификаторы — не ошибка. Возвращает число подтверждённых впервые.
func (t *Tasks) AckFactBatches(ctx context.Context, batchIDs []string, now time.Time) (int64, error) {
	tag, err := t.pool.Exec(ctx, `
		UPDATE outbox_batch SET status = 'acked', acked_at = $2 WHERE batch_id = ANY($1) AND status = 'new'`, batchIDs, now)
	if err != nil {
		return 0, fmt.Errorf("store: ack fact batches: %w", err)
	}
	return tag.RowsAffected(), nil
}

func scanFactBatch(row pgx.Row) (FactBatch, error) {
	var b FactBatch
	var payload []byte
	if err := row.Scan(&b.BatchID, &b.ContentHash, &payload, &b.CreatedAt); err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return FactBatch{}, err
		}
		return FactBatch{}, fmt.Errorf("store: fact batch scan: %w", err)
	}
	var p factBatchPointer
	if err := json.Unmarshal(payload, &p); err != nil {
		return FactBatch{}, fmt.Errorf("store: fact batch %s payload: %w", b.BatchID, err)
	}
	b.TaskKey = TaskKey{ConnectionRef: p.ConnectionRef, ResourceType: p.ResourceType, PeriodKey: p.PeriodKey}
	b.Revision = p.Revision
	b.PageHashes = strings.Split(p.Pages, ",")
	return b, nil
}
