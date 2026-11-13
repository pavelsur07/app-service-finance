package httpapi

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

const byDay = "ozon.finance.accrual-by-day"

type factsFake struct {
	batches  []store.FactBatch
	listErr  error
	readErr  error
	splitErr error
	bodies   map[string]string
	acked    []string
}

func factBatchOf(period string, minute int, pages ...string) store.FactBatch {
	key := store.TaskKey{ConnectionRef: "ref-a", ResourceType: byDay, PeriodKey: period}
	return store.FactBatch{BatchID: store.FactBatchID(key, 1), TaskKey: key, Revision: 1, ContentHash: "c-" + period,
		PageHashes: pages, CreatedAt: time.Date(2026, 11, 13, 2, minute, 0, 0, time.UTC)}
}

func (f *factsFake) deps() *FactsDeps {
	return &FactsDeps{
		Resources: []string{byDay},
		NewBatches: func(_ context.Context, _ string, afterAt time.Time, afterID string, limit int) ([]store.FactBatch, error) {
			if f.listErr != nil {
				return nil, f.listErr
			}
			var out []store.FactBatch
			for _, b := range f.batches {
				if (b.CreatedAt.After(afterAt) || (b.CreatedAt.Equal(afterAt) && b.BatchID > afterID)) && len(out) < limit {
					out = append(out, b)
				}
			}
			return out, nil
		},
		Batch: func(_ context.Context, id string) (store.FactBatch, error) {
			for _, b := range f.batches {
				if b.BatchID == id {
					return b, nil
				}
			}
			return store.FactBatch{}, store.ErrNoBatch
		},
		BatchPage: func(_ context.Context, b store.FactBatch, pageNo int) (store.RawObject, error) {
			if pageNo > len(b.PageHashes) {
				return store.RawObject{}, store.ErrNoBatch
			}
			h := b.PageHashes[pageNo-1]
			if h == "lost" {
				return store.RawObject{}, store.ErrMissingObject
			}
			return store.RawObject{PageNo: pageNo, StorageURI: "s3://b/" + h, SHA256: h}, nil
		},
		Ack: func(_ context.Context, ids []string, _ time.Time) (int64, error) {
			f.acked = append(f.acked, ids...)
			return int64(len(ids)), nil
		},
		ReadRawObject: func(_ context.Context, uri, sha string) ([]byte, error) {
			if f.readErr != nil {
				return nil, f.readErr
			}
			return []byte(f.bodies[uri+"#"+sha]), nil
		},
		Split: func(_ string, page []byte) ([]FactRecord, error) {
			if f.splitErr != nil {
				return nil, f.splitErr
			}
			var items []json.RawMessage
			if err := json.Unmarshal(page, &items); err != nil {
				return nil, err
			}
			out := make([]FactRecord, 0, len(items))
			for i, item := range items {
				out = append(out, FactRecord{SourceKey: "k" + string(rune('1'+i)), Payload: item})
			}
			return out, nil
		},
	}
}

func serveFacts(t *testing.T, f *FactsDeps, method, target, body string, auth bool) *httptest.ResponseRecorder {
	t.Helper()
	h := New(Deps{Service: "ozon-fetcher", Token: token, Location: time.UTC, Facts: f,
		Logger: slog.New(slog.NewTextHandler(io.Discard, nil)), Now: time.Now})
	req := httptest.NewRequestWithContext(context.Background(), method, target, strings.NewReader(body))
	if auth {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	return rec
}

func TestFactBatchesPageInOrderAndCursorAdvances(t *testing.T) {
	fake := &factsFake{batches: []store.FactBatch{factBatchOf("2026-11-10", 1, "h1"), factBatchOf("2026-11-11", 2, "h2", "h3"), factBatchOf("2026-11-12", 3, "h4")}}

	rec := serveFacts(t, fake.deps(), http.MethodGet, "/internal/source-facts?resource_type="+byDay+"&limit=2", "", true)
	require.Equal(t, http.StatusOK, rec.Code)
	var first factBatchesResponse
	require.NoError(t, json.Unmarshal(rec.Body.Bytes(), &first))
	assert.Equal(t, "1", first.SchemaVersion)
	assert.Equal(t, "ozon-fetcher", first.Service)
	require.Len(t, first.Batches, 2)
	assert.Equal(t, factBatchJSON{BatchID: "ref-a:" + byDay + ":2026-11-11:r1", ConnectionRef: "ref-a", ResourceType: byDay,
		PeriodKey: "2026-11-11", Revision: 1, ContentHash: "c-2026-11-11", PageCount: 2, CreatedAt: "2026-11-13T02:02:00Z"}, first.Batches[1])
	require.NotNil(t, first.NextCursor, "есть ещё пачки — курсор обязателен")

	rec = serveFacts(t, fake.deps(), http.MethodGet, "/internal/source-facts?resource_type="+byDay+"&limit=2&after="+*first.NextCursor, "", true)
	require.Equal(t, http.StatusOK, rec.Code)
	var second factBatchesResponse
	require.NoError(t, json.Unmarshal(rec.Body.Bytes(), &second))
	require.Len(t, second.Batches, 1)
	assert.Equal(t, "2026-11-12", second.Batches[0].PeriodKey)
	assert.Nil(t, second.NextCursor, "последняя страница — без курсора")

	rec = serveFacts(t, (&factsFake{}).deps(), http.MethodGet, "/internal/source-facts?resource_type="+byDay, "", true)
	require.Equal(t, http.StatusOK, rec.Code)
	assert.JSONEq(t, `{"schema_version":"1","service":"ozon-fetcher","batches":[],"next_cursor":null}`, rec.Body.String())
}

func TestFactPageServesRecordsWithHashes(t *testing.T) {
	fake := &factsFake{batches: []store.FactBatch{factBatchOf("2026-11-11", 2, "h2", "h3")},
		bodies: map[string]string{"s3://b/h3#h3": `[{"accrual_id":"7","amount":"-10.50"},{"accrual_id":"8"}]`}}

	rec := serveFacts(t, fake.deps(), http.MethodGet, "/internal/source-facts/page?batch_id=ref-a:"+byDay+":2026-11-11:r1&page=2", "", true)
	require.Equal(t, http.StatusOK, rec.Code)
	var page factPageResponse
	require.NoError(t, json.Unmarshal(rec.Body.Bytes(), &page))
	assert.Equal(t, []int{2, 2, 2}, []int{page.Page, page.PageCount, page.RecordCount})
	require.Len(t, page.Records, 2)
	sum := sha256.Sum256([]byte(`{"accrual_id":"7","amount":"-10.50"}`))
	assert.Equal(t, "k1", page.Records[0].SourceKey)
	assert.Equal(t, hex.EncodeToString(sum[:]), page.Records[0].PayloadHash, "хеш — от байтов записи как в источнике")
	assert.JSONEq(t, `{"accrual_id":"7","amount":"-10.50"}`, string(page.Records[0].Payload))
}

func TestFactEndpointsReject(t *testing.T) {
	batch := factBatchOf("2026-11-11", 2, "h2", "lost")
	other := factBatchOf("2026-11-12", 3, "h9")
	other.ResourceType = "ozon.orders.fbo"
	pageURL := "/internal/source-facts/page?batch_id=" + batch.BatchID
	cases := []struct {
		name   string
		fake   *factsFake
		method string
		target string
		body   string
		noAuth bool
		noDeps bool
		status int
		code   string
	}{
		{name: "без токена", target: "/internal/source-facts?resource_type=" + byDay, noAuth: true, status: 401, code: "internal_auth_required"},
		{name: "загрузка выключена", target: "/internal/source-facts?resource_type=" + byDay, noDeps: true, status: 503, code: "ingestion_disabled"},
		{name: "ресурс не публикуется", target: "/internal/source-facts?resource_type=ozon.orders.fbo", status: 422, code: "invalid_request"},
		{name: "чужой курсор", target: "/internal/source-facts?resource_type=" + byDay + "&after=zzz", status: 422, code: "invalid_request"},
		{name: "limit вне границ", target: "/internal/source-facts?resource_type=" + byDay + "&limit=501", status: 422, code: "invalid_request"},
		{name: "БД недоступна", fake: &factsFake{listErr: errors.New("db down")}, target: "/internal/source-facts?resource_type=" + byDay, status: 503, code: "facts_unavailable"},
		{name: "страница без номера", target: pageURL, status: 422, code: "invalid_request"},
		{name: "нет пачки", target: "/internal/source-facts/page?batch_id=nope&page=1", status: 404, code: "batch_not_found"},
		{name: "страница вне пачки", target: pageURL + "&page=3", status: 404, code: "batch_not_found"},
		{name: "пачка непубликуемого ресурса", target: "/internal/source-facts/page?batch_id=" + other.BatchID + "&page=1", status: 404, code: "batch_not_found"},
		{name: "объект страницы потерян", target: pageURL + "&page=2", status: 502, code: "fact_page_invalid"},
		{name: "объект повреждён", fake: &factsFake{readErr: rawstore.ErrObjectInvalid}, target: pageURL + "&page=1", status: 502, code: "fact_page_invalid"},
		{name: "S3 недоступен", fake: &factsFake{readErr: errors.New("timeout")}, target: pageURL + "&page=1", status: 503, code: "facts_unavailable"},
		{name: "страница не разбирается", fake: &factsFake{splitErr: errors.New("no accruals")}, target: pageURL + "&page=1", status: 502, code: "fact_page_invalid"},
		{name: "ack без идентификаторов", method: http.MethodPost, target: "/internal/source-facts/ack", body: `{"batch_ids":[]}`, status: 422, code: "invalid_request"},
		{name: "ack с пустым идентификатором", method: http.MethodPost, target: "/internal/source-facts/ack", body: `{"batch_ids":[""]}`, status: 422, code: "invalid_request"},
		{name: "ack не json", method: http.MethodPost, target: "/internal/source-facts/ack", body: `nope`, status: 422, code: "invalid_request"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			fake := tc.fake
			if fake == nil {
				fake = &factsFake{}
			}
			fake.batches = []store.FactBatch{batch, other}
			deps := fake.deps()
			if tc.noDeps {
				deps = nil
			}
			method := tc.method
			if method == "" {
				method = http.MethodGet
			}
			rec := serveFacts(t, deps, method, tc.target, tc.body, !tc.noAuth)
			assert.Equal(t, tc.status, rec.Code)
			assert.Equal(t, tc.code, errorCode(t, rec))
		})
	}
}

func TestFactAckPassesIdentifiers(t *testing.T) {
	fake := &factsFake{}
	rec := serveFacts(t, fake.deps(), http.MethodPost, "/internal/source-facts/ack", `{"batch_ids":["a","b"]}`, true)
	require.Equal(t, http.StatusOK, rec.Code)
	assert.JSONEq(t, `{"schema_version":"1","acked":2}`, rec.Body.String())
	assert.Equal(t, []string{"a", "b"}, fake.acked)
}

// Примеры контракта services/_contracts/source-fact-envelope/v1/examples —
// ровно то, что отдают обработчики: строгий разбор в типы ответа и обратно.
func TestFactResponsesMatchContractExamples(t *testing.T) {
	const dir = "../../../_contracts/source-fact-envelope/v1/examples/"
	for file, target := range map[string]any{
		"batches_response.json": &factBatchesResponse{},
		"page_response.json":    &factPageResponse{},
	} {
		t.Run(file, func(t *testing.T) {
			raw, err := os.ReadFile(dir + file)
			require.NoError(t, err)
			dec := json.NewDecoder(bytes.NewReader(raw))
			dec.DisallowUnknownFields()
			require.NoError(t, dec.Decode(target), "в примере нет полей вне типа ответа")
			again, err := json.Marshal(target)
			require.NoError(t, err)
			assert.JSONEq(t, string(raw), string(again), "в типе ответа нет полей вне примера")
		})
	}
}
