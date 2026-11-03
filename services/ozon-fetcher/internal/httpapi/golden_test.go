package httpapi

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Эталон тела /internal/coverage снят на коде до выноса ядра в fetchkit
// (wb-fetcher-finance, этап 0): перенос не должен менять ответ ни на байт.
const coverageGolden = `{"schema_version":"1","service":"ozon-fetcher","generated_at":"2026-10-17T09:00:00Z","from":"2026-08-01","to":"2026-10-31","cabinets_total":2,"rows":[{"resource_type":"ozon.finance.accrual-by-day","period_key":"2026-10-15","cabinets_expected":2,"by_status":{"done":2,"failed_permanent":0,"failed_retryable":0,"not_ready":0,"pending":0,"running":0},"last_success_at":"2026-10-16T21:05:00Z"},{"resource_type":"ozon.finance.realization","period_key":"2026-09","cabinets_expected":1,"by_status":{"done":0,"failed_permanent":0,"failed_retryable":0,"not_ready":0,"pending":1,"running":0},"last_success_at":null},{"resource_type":"ozon.ads.expense","period_key":"2026-10-15","cabinets_expected":1,"by_status":{"done":1,"failed_permanent":0,"failed_retryable":0,"not_ready":0,"pending":0,"running":0},"last_success_at":null}]}`

func TestCoverageGolden(t *testing.T) {
	var call coverageCall
	req := httptest.NewRequestWithContext(context.Background(), http.MethodGet, "/internal/coverage", nil)
	req.Header.Set("Authorization", "Bearer "+token)
	rec := httptest.NewRecorder()
	New(coverageDeps(t, &call)).ServeHTTP(rec, req)

	require.Equal(t, http.StatusOK, rec.Code)
	assert.Equal(t, coverageGolden+"\n", rec.Body.String())
}
