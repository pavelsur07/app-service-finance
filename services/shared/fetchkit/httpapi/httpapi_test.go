package httpapi

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

const token = "service-direction-token-0123456789abcdef" //nolint:gosec // G101: фиктивный токен теста

func TestCoverageCarriesServiceAndExpectation(t *testing.T) {
	var gotRefs []string
	h := New(Deps{
		Service: "wb-fetcher", Token: token, Location: time.UTC, Logger: slog.New(slog.NewTextHandler(io.Discard, nil)),
		Now: func() time.Time { return time.Date(2026, 11, 2, 9, 0, 0, 0, time.UTC) },
		Expected: func() Expectation {
			return Expectation{Refs: []string{"a", "b"}, Total: 2, ByResource: map[string]int{"wb.r": 1}}
		},
		Coverage: func(_ context.Context, refs []string, _, _ string) ([]store.CoverageRow, error) {
			gotRefs = refs
			return []store.CoverageRow{{ResourceType: "wb.r", PeriodKey: "2026-W44", ByStatus: map[string]int{"done": 1}}}, nil
		},
	})
	req := httptest.NewRequestWithContext(context.Background(), http.MethodGet, "/internal/coverage", nil)
	req.Header.Set("Authorization", "Bearer "+token)
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)

	require.Equal(t, http.StatusOK, rec.Code)
	var body struct {
		Service       string `json:"service"`
		CabinetsTotal int    `json:"cabinets_total"`
		Rows          []struct {
			PeriodKey        string `json:"period_key"`
			CabinetsExpected int    `json:"cabinets_expected"`
		} `json:"rows"`
	}
	require.NoError(t, json.Unmarshal(rec.Body.Bytes(), &body))
	assert.Equal(t, "wb-fetcher", body.Service)
	assert.Equal(t, 2, body.CabinetsTotal)
	assert.Equal(t, []string{"a", "b"}, gotRefs)
	require.Len(t, body.Rows, 1)
	assert.Equal(t, 1, body.Rows[0].CabinetsExpected)
}
