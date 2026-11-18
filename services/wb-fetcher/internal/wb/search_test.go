package wb

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

func serveSearch(t *testing.T, status int, out string) (*Analytics, *[]map[string]any) {
	t.Helper()
	var bodies []map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, EndpointSearch, r.URL.Path)
		assert.Equal(t, token, r.Header.Get("Authorization"))
		var body map[string]any
		require.NoError(t, json.NewDecoder(r.Body).Decode(&body))
		bodies = append(bodies, body)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(out))
	}))
	t.Cleanup(srv.Close)
	return NewAnalytics(srv.URL, srv.Client(), func() time.Time { return t0 }).WithLimiter(&observed{}), &bodies
}

func TestSearchDayRequestsOneDayOfAllCardsSortedByNmID(t *testing.T) {
	// Форма спецификации WB (сокращена): nmId — на верхнем уровне карточки.
	a, bodies := serveSearch(t, http.StatusOK, `{"data":{"products":[
		{"nmId":9,"openCard":{"current":4}},{"nmId":2,"openCard":{"current":1}}],"currency":"RUB"}}`)

	day, err := a.SearchDay(context.Background(), "ref-1", token, "2026-11-17")

	require.NoError(t, err)
	assert.Equal(t, 2, day.Products)
	assert.Equal(t, map[string]any{
		"currentPeriod":   map[string]any{"start": "2026-11-17", "end": "2026-11-17"},
		"orderBy":         map[string]any{"field": "openCard", "mode": "desc"},
		"positionCluster": "all", "includeSubstitutedSKUs": true, "includeSearchTexts": true,
		"limit": float64(FunnelPageLimit), "offset": float64(0),
	}, (*bodies)[0])
	assert.JSONEq(t, `{"schema":"wb-search-daily-v1","currency":"RUB","products":[
		{"nmId":2,"openCard":{"current":1}},{"nmId":9,"openCard":{"current":4}}]}`, string(day.Body))
}

func TestSearchDayClassifiesRefusals(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		body   string
		class  apierr.Class
	}{
		// Отказ в доступе — конечный исход, не пауза и не повторы: проверка разовая.
		"no token category": {http.StatusForbidden, `{"title":"Authorization error"}`, apierr.ClassInvalidRequest},
		"payment required":  {http.StatusPaymentRequired, `{"title":"payment required"}`, apierr.ClassInvalidRequest},
		"bad request":       {http.StatusBadRequest, `{"title":"Invalid request body"}`, apierr.ClassInvalidRequest},
		"rate limit":        {http.StatusTooManyRequests, `{}`, apierr.ClassRateLimit},
		"card without nmId": {http.StatusOK, `{"data":{"products":[{"openCard":{}}]}}`, apierr.ClassInvalidResponse},
	} {
		t.Run(name, func(t *testing.T) {
			a, _ := serveSearch(t, tc.status, tc.body)
			_, err := a.SearchDay(context.Background(), "ref-1", token, "2026-11-17")
			var apiErr *apierr.APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, tc.class, apiErr.Class)
			assert.Equal(t, EndpointSearch, apiErr.Endpoint)
		})
	}
}
