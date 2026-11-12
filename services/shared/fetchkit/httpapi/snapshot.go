package httpapi

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"slices"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

// Контракт ответа — services/_contracts/service-snapshot/v1.
const snapshotSchemaVersion = "1"

// maxConnectionRefLen — id подключения Symfony (UUID); запас на будущие форматы.
const maxConnectionRefLen = 64

// SnapshotDeps — выдача последнего загруженного снимка ресурса кабинета.
type SnapshotDeps struct {
	// Resources — белый список ресурсов; вне списка не отдаётся ничего.
	Resources     []string
	LatestPeriod  func(ctx context.Context, connectionRef, resourceType string) (string, error)
	ContentPages  func(ctx context.Context, key store.TaskKey) (store.Content, error)
	ReadRawObject func(ctx context.Context, uri, sha256 string) ([]byte, error)
}

type snapshotResponse struct {
	SchemaVersion string            `json:"schema_version"`
	Service       string            `json:"service"`
	ConnectionRef string            `json:"connection_ref"`
	ResourceType  string            `json:"resource_type"`
	PeriodKey     string            `json:"period_key"`
	LoadedAt      string            `json:"loaded_at"`
	Pages         []json.RawMessage `json:"pages"`
}

func snapshot(w http.ResponseWriter, r *http.Request, d Deps) {
	s := d.Snapshot
	if s == nil {
		writeError(w, http.StatusServiceUnavailable, "ingestion_disabled", "loading is disabled")
		return
	}
	ref, rt := r.URL.Query().Get("connection_ref"), r.URL.Query().Get("resource_type")
	if ref == "" || len(ref) > maxConnectionRefLen || !slices.Contains(s.Resources, rt) {
		writeError(w, http.StatusUnprocessableEntity, "invalid_request", "connection_ref is required and resource_type must be one of the published snapshots")
		return
	}
	log := d.Logger.With(slog.String("stage", "snapshot"), slog.String("connection_ref", ref), slog.String("resource_type", rt))
	unavailable := func(err error) {
		log.Warn("snapshot unavailable", slog.String("error", err.Error()))
		writeError(w, http.StatusServiceUnavailable, "snapshot_unavailable", "snapshot is unavailable")
	}

	period, err := s.LatestPeriod(r.Context(), ref, rt)
	if errors.Is(err, store.ErrNoContent) {
		writeError(w, http.StatusNotFound, "snapshot_not_found", "no loaded snapshot")
		return
	}
	if err != nil {
		unavailable(err)
		return
	}
	content, err := s.ContentPages(r.Context(), store.TaskKey{ConnectionRef: ref, ResourceType: rt, PeriodKey: period})
	if err != nil {
		// ErrNoContent между двумя запросами — ревизию только что заменили: повтор отдаст новую.
		unavailable(err)
		return
	}

	pages := make([]json.RawMessage, 0, len(content.Pages))
	for _, page := range content.Pages {
		body, err := s.ReadRawObject(r.Context(), page.StorageURI, page.SHA256)
		if errors.Is(err, rawstore.ErrObjectInvalid) || errors.Is(err, rawstore.ErrObjectNotFound) || (err == nil && !json.Valid(body)) {
			// Повреждённая или пропавшая страница — нарушен инвариант хранения, будить человека.
			cause := "not json"
			if err != nil {
				cause = err.Error()
			}
			log.Error("snapshot page invalid", slog.String("period_key", period), slog.Int("page", page.PageNo), slog.String("error", cause))
			writeError(w, http.StatusBadGateway, "snapshot_invalid", "stored snapshot page is invalid")
			return
		}
		if err != nil {
			unavailable(err)
			return
		}
		pages = append(pages, body)
	}

	log.Info("snapshot served", slog.String("period_key", period), slog.Int("pages", len(pages)))
	writeJSON(w, http.StatusOK, snapshotResponse{
		SchemaVersion: snapshotSchemaVersion, Service: d.Service, ConnectionRef: ref, ResourceType: rt,
		PeriodKey: period, LoadedAt: content.StartedAt.UTC().Format(time.RFC3339), Pages: pages,
	})
}
