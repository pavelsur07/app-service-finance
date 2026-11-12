package httpapi

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

const supplies = "ozon.supplies"

type snapshotFake struct {
	period      string
	periodErr   error
	content     store.Content
	contentErr  error
	bodies      map[string]string
	readErr     error
	contentKeys []store.TaskKey
}

func (f *snapshotFake) deps() *SnapshotDeps {
	return &SnapshotDeps{
		Resources: []string{supplies},
		LatestPeriod: func(_ context.Context, _, _ string) (string, error) {
			return f.period, f.periodErr
		},
		ContentPages: func(_ context.Context, key store.TaskKey) (store.Content, error) {
			f.contentKeys = append(f.contentKeys, key)
			return f.content, f.contentErr
		},
		ReadRawObject: func(_ context.Context, uri, sha string) ([]byte, error) {
			if f.readErr != nil {
				return nil, f.readErr
			}
			return []byte(f.bodies[uri+"#"+sha]), nil
		},
	}
}

func okFake() *snapshotFake {
	return &snapshotFake{
		period: "2026-11-12",
		content: store.Content{
			StartedAt: time.Date(2026, 11, 12, 2, 1, 0, 0, time.UTC),
			Pages: []store.RawObject{
				{PageNo: 1, StorageURI: "s3://b/p1", SHA256: "h1"},
				{PageNo: 2, StorageURI: "s3://b/p2", SHA256: "h2"},
			},
		},
		bodies: map[string]string{"s3://b/p1#h1": `{"order_ids":[1]}`, "s3://b/p2#h2": `{"orders":[{"order_id":1}]}`},
	}
}

func serveSnapshot(t *testing.T, s *SnapshotDeps, query string, auth bool) *httptest.ResponseRecorder {
	t.Helper()
	h := New(Deps{Service: "ozon-fetcher", Token: token, Location: time.UTC, Snapshot: s,
		Logger: slog.New(slog.NewTextHandler(io.Discard, nil)), Now: time.Now})
	req := httptest.NewRequestWithContext(context.Background(), http.MethodGet, "/internal/snapshots/latest?"+query, nil)
	if auth {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	return rec
}

func errorCode(t *testing.T, rec *httptest.ResponseRecorder) string {
	t.Helper()
	var body struct {
		Error struct {
			Code string `json:"code"`
		} `json:"error"`
	}
	require.NoError(t, json.Unmarshal(rec.Body.Bytes(), &body))
	return body.Error.Code
}

func TestSnapshotServesPagesOfLatestRevisionInOrder(t *testing.T) {
	f := okFake()
	rec := serveSnapshot(t, f.deps(), "connection_ref=ref-a&resource_type="+supplies, true)

	require.Equal(t, http.StatusOK, rec.Code, rec.Body.String())
	var body struct {
		SchemaVersion string            `json:"schema_version"`
		Service       string            `json:"service"`
		ConnectionRef string            `json:"connection_ref"`
		ResourceType  string            `json:"resource_type"`
		PeriodKey     string            `json:"period_key"`
		LoadedAt      string            `json:"loaded_at"`
		Pages         []json.RawMessage `json:"pages"`
	}
	require.NoError(t, json.Unmarshal(rec.Body.Bytes(), &body))
	assert.Equal(t, "1", body.SchemaVersion)
	assert.Equal(t, "ozon-fetcher", body.Service)
	assert.Equal(t, "ref-a", body.ConnectionRef)
	assert.Equal(t, supplies, body.ResourceType)
	assert.Equal(t, "2026-11-12", body.PeriodKey)
	assert.Equal(t, "2026-11-12T02:01:00Z", body.LoadedAt)
	require.Len(t, body.Pages, 2)
	assert.JSONEq(t, `{"order_ids":[1]}`, string(body.Pages[0]))
	assert.JSONEq(t, `{"orders":[{"order_id":1}]}`, string(body.Pages[1]))
	assert.Equal(t, []store.TaskKey{{ConnectionRef: "ref-a", ResourceType: supplies, PeriodKey: "2026-11-12"}}, f.contentKeys,
		"страницы — ревизии последнего периода")
}

func TestSnapshotRejects(t *testing.T) {
	long := fmt.Sprintf("%065d", 0)
	cases := []struct {
		name   string
		query  string
		auth   bool
		fake   func() *snapshotFake
		status int
		code   string
	}{
		{"без токена", "connection_ref=ref-a&resource_type=" + supplies, false, okFake, http.StatusUnauthorized, "internal_auth_required"},
		{"ресурс вне списка", "connection_ref=ref-a&resource_type=ozon.finance.accrual-by-day", true, okFake, http.StatusUnprocessableEntity, "invalid_request"},
		{"без кабинета", "resource_type=" + supplies, true, okFake, http.StatusUnprocessableEntity, "invalid_request"},
		{"длинный кабинет", "connection_ref=" + long + "&resource_type=" + supplies, true, okFake, http.StatusUnprocessableEntity, "invalid_request"},
		{"ревизии нет", "connection_ref=ref-a&resource_type=" + supplies, true, func() *snapshotFake {
			f := okFake()
			f.periodErr = store.ErrNoContent
			return f
		}, http.StatusNotFound, "snapshot_not_found"},
		{"сбой БД", "connection_ref=ref-a&resource_type=" + supplies, true, func() *snapshotFake {
			f := okFake()
			f.contentErr = errors.New("db down")
			return f
		}, http.StatusServiceUnavailable, "snapshot_unavailable"},
		{"сбой S3", "connection_ref=ref-a&resource_type=" + supplies, true, func() *snapshotFake {
			f := okFake()
			f.readErr = errors.New("timeout")
			return f
		}, http.StatusServiceUnavailable, "snapshot_unavailable"},
		{"страница повреждена", "connection_ref=ref-a&resource_type=" + supplies, true, func() *snapshotFake {
			f := okFake()
			f.readErr = rawstore.ErrChecksumMismatch
			return f
		}, http.StatusBadGateway, "snapshot_invalid"},
		{"страница не JSON", "connection_ref=ref-a&resource_type=" + supplies, true, func() *snapshotFake {
			f := okFake()
			f.bodies["s3://b/p2#h2"] = "not json"
			return f
		}, http.StatusBadGateway, "snapshot_invalid"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			rec := serveSnapshot(t, tc.fake().deps(), tc.query, tc.auth)
			assert.Equal(t, tc.status, rec.Code)
			assert.Equal(t, tc.code, errorCode(t, rec))
		})
	}
}

func TestSnapshotDisabledWithoutDeps(t *testing.T) {
	rec := serveSnapshot(t, nil, "connection_ref=ref-a&resource_type="+supplies, true)
	assert.Equal(t, http.StatusServiceUnavailable, rec.Code)
	assert.Equal(t, "ingestion_disabled", errorCode(t, rec))
}
