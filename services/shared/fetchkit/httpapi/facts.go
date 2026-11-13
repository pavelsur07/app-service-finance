package httpapi

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

// Контракт — services/_contracts/source-fact-envelope/v1.
const factsSchemaVersion = "1"

const (
	factsDefaultLimit = 100
	factsMaxLimit     = 500
	factsMaxAck       = 1000
	factsMaxAckBody   = 1 << 20
)

// FactRecord — одна запись источника: стабильный ключ и исходный JSON как пришёл.
type FactRecord struct {
	SourceKey string
	Payload   json.RawMessage
}

// FactsDeps — выдача фактов Symfony: пачка — загруженная ревизия периода кабинета.
type FactsDeps struct {
	// Resources — белый список публикуемых ресурсов; вне списка не отдаётся ничего.
	Resources     []string
	NewBatches    func(ctx context.Context, resourceType string, afterCreatedAt time.Time, afterBatchID string, limit int) ([]store.FactBatch, error)
	Batch         func(ctx context.Context, batchID string) (store.FactBatch, error)
	BatchPage     func(ctx context.Context, b store.FactBatch, pageNo int) (store.RawObject, error)
	Ack           func(ctx context.Context, batchIDs []string, now time.Time) (int64, error)
	ReadRawObject func(ctx context.Context, uri, sha256 string) ([]byte, error)
	// Split режет страницу ресурса на записи; смысла записей не касается.
	Split func(resourceType string, page []byte) ([]FactRecord, error)
}

type factBatchJSON struct {
	BatchID       string `json:"batch_id"`
	ConnectionRef string `json:"connection_ref"`
	ResourceType  string `json:"resource_type"`
	PeriodKey     string `json:"period_key"`
	Revision      int    `json:"revision"`
	ContentHash   string `json:"content_hash"`
	PageCount     int    `json:"page_count"`
	CreatedAt     string `json:"created_at"`
}

type factBatchesResponse struct {
	SchemaVersion string          `json:"schema_version"`
	Service       string          `json:"service"`
	Batches       []factBatchJSON `json:"batches"`
	NextCursor    *string         `json:"next_cursor"`
}

type factRecordJSON struct {
	SourceKey   string          `json:"source_key"`
	PayloadHash string          `json:"payload_hash"`
	Payload     json.RawMessage `json:"payload"`
}

type factPageResponse struct {
	SchemaVersion string           `json:"schema_version"`
	Service       string           `json:"service"`
	BatchID       string           `json:"batch_id"`
	Page          int              `json:"page"`
	PageCount     int              `json:"page_count"`
	RecordCount   int              `json:"record_count"`
	Records       []factRecordJSON `json:"records"`
}

func factsEnabled(w http.ResponseWriter, d Deps) *FactsDeps {
	if d.Facts == nil {
		writeError(w, http.StatusServiceUnavailable, "ingestion_disabled", "loading is disabled")
	}
	return d.Facts
}

func factBatches(w http.ResponseWriter, r *http.Request, d Deps) {
	f := factsEnabled(w, d)
	if f == nil {
		return
	}
	q := r.URL.Query()
	rt := q.Get("resource_type")
	limit := factsDefaultLimit
	if raw := q.Get("limit"); raw != "" {
		n, err := strconv.Atoi(raw)
		if err != nil || n < 1 || n > factsMaxLimit {
			writeError(w, http.StatusUnprocessableEntity, "invalid_request", "limit must be between 1 and 500")
			return
		}
		limit = n
	}
	afterAt, afterID, ok := decodeFactCursor(q.Get("after"))
	if !ok || !slices.Contains(f.Resources, rt) {
		writeError(w, http.StatusUnprocessableEntity, "invalid_request", "resource_type must be one of the published fact resources and after must be a cursor of this endpoint")
		return
	}
	// На одну больше — узнать, есть ли следующая страница.
	batches, err := f.NewBatches(r.Context(), rt, afterAt, afterID, limit+1)
	if err != nil {
		d.Logger.Warn("fact batches unavailable", slog.String("stage", "facts"), slog.String("resource_type", rt), slog.String("error", err.Error()))
		writeError(w, http.StatusServiceUnavailable, "facts_unavailable", "fact batches are unavailable")
		return
	}
	resp := factBatchesResponse{SchemaVersion: factsSchemaVersion, Service: d.Service, Batches: []factBatchJSON{}}
	if len(batches) > limit {
		batches = batches[:limit]
		last := batches[limit-1]
		cursor := encodeFactCursor(last.CreatedAt, last.BatchID)
		resp.NextCursor = &cursor
	}
	for _, b := range batches {
		resp.Batches = append(resp.Batches, factBatchJSON{BatchID: b.BatchID, ConnectionRef: b.ConnectionRef, ResourceType: b.ResourceType,
			PeriodKey: b.PeriodKey, Revision: b.Revision, ContentHash: b.ContentHash, PageCount: len(b.PageHashes),
			CreatedAt: b.CreatedAt.UTC().Format(time.RFC3339Nano)})
	}
	writeJSON(w, http.StatusOK, resp)
}

func factPage(w http.ResponseWriter, r *http.Request, d Deps) {
	f := factsEnabled(w, d)
	if f == nil {
		return
	}
	batchID := r.URL.Query().Get("batch_id")
	pageNo, err := strconv.Atoi(r.URL.Query().Get("page"))
	if batchID == "" || err != nil || pageNo < 1 {
		writeError(w, http.StatusUnprocessableEntity, "invalid_request", "batch_id and page (from 1) are required")
		return
	}
	log := d.Logger.With(slog.String("stage", "facts"), slog.String("batch_id", batchID), slog.Int("page", pageNo))
	unavailable := func(err error) {
		log.Warn("fact page unavailable", slog.String("error", err.Error()))
		writeError(w, http.StatusServiceUnavailable, "facts_unavailable", "fact page is unavailable")
	}
	invalid := func(cause string) {
		// Нарушен инвариант хранения или разбора — сам не пройдёт, будить человека.
		log.Error("fact page invalid", slog.String("error", cause))
		writeError(w, http.StatusBadGateway, "fact_page_invalid", "stored fact page is invalid")
	}

	batch, err := f.Batch(r.Context(), batchID)
	if err == nil && !slices.Contains(f.Resources, batch.ResourceType) {
		err = store.ErrNoBatch
	}
	var obj store.RawObject
	if err == nil {
		obj, err = f.BatchPage(r.Context(), batch, pageNo)
	}
	switch {
	case errors.Is(err, store.ErrNoBatch):
		writeError(w, http.StatusNotFound, "batch_not_found", "no such batch or page")
		return
	case errors.Is(err, store.ErrMissingObject):
		invalid(err.Error())
		return
	case err != nil:
		unavailable(err)
		return
	}
	body, err := f.ReadRawObject(r.Context(), obj.StorageURI, obj.SHA256)
	if errors.Is(err, rawstore.ErrObjectInvalid) || errors.Is(err, rawstore.ErrObjectNotFound) {
		invalid(err.Error())
		return
	}
	if err != nil {
		unavailable(err)
		return
	}
	records, err := f.Split(batch.ResourceType, body)
	if err != nil {
		invalid(err.Error())
		return
	}
	resp := factPageResponse{SchemaVersion: factsSchemaVersion, Service: d.Service, BatchID: batchID, Page: pageNo,
		PageCount: len(batch.PageHashes), RecordCount: len(records), Records: make([]factRecordJSON, 0, len(records))}
	for _, rec := range records {
		sum := sha256.Sum256(rec.Payload)
		resp.Records = append(resp.Records, factRecordJSON{SourceKey: rec.SourceKey, PayloadHash: hex.EncodeToString(sum[:]), Payload: rec.Payload})
	}
	log.Info("fact page served", slog.Int("records", len(records)))
	writeJSON(w, http.StatusOK, resp)
}

func factAck(w http.ResponseWriter, r *http.Request, d Deps) {
	f := factsEnabled(w, d)
	if f == nil {
		return
	}
	var req struct {
		BatchIDs []string `json:"batch_ids"`
	}
	raw, err := io.ReadAll(http.MaxBytesReader(w, r.Body, factsMaxAckBody))
	if err != nil || json.Unmarshal(raw, &req) != nil || len(req.BatchIDs) == 0 || len(req.BatchIDs) > factsMaxAck || slices.Contains(req.BatchIDs, "") {
		writeError(w, http.StatusUnprocessableEntity, "invalid_request", "batch_ids: from 1 to 1000 non-empty identifiers")
		return
	}
	acked, err := f.Ack(r.Context(), req.BatchIDs, d.Now())
	if err != nil {
		d.Logger.Warn("fact ack failed", slog.String("stage", "facts"), slog.String("error", err.Error()))
		writeError(w, http.StatusServiceUnavailable, "facts_unavailable", "ack is unavailable")
		return
	}
	d.Logger.Info("fact batches acked", slog.String("stage", "facts"), slog.Int("requested", len(req.BatchIDs)), slog.Int64("acked", acked))
	writeJSON(w, http.StatusOK, map[string]any{"schema_version": factsSchemaVersion, "acked": acked})
}

func encodeFactCursor(createdAt time.Time, batchID string) string {
	return base64.RawURLEncoding.EncodeToString([]byte(createdAt.UTC().Format(time.RFC3339Nano) + "|" + batchID))
}

// decodeFactCursor: пустой курсор — с начала; чужая строка — ok=false.
func decodeFactCursor(cursor string) (time.Time, string, bool) {
	if cursor == "" {
		return time.Time{}, "", true
	}
	raw, err := base64.RawURLEncoding.DecodeString(cursor)
	if err != nil {
		return time.Time{}, "", false
	}
	at, id, found := strings.Cut(string(raw), "|")
	createdAt, err := time.Parse(time.RFC3339Nano, at)
	if !found || id == "" || err != nil {
		return time.Time{}, "", false
	}
	return createdAt, id, true
}
