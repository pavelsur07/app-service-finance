package wb

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

func serveFunnel(t *testing.T, respond func(body map[string]any) (int, string)) (*Analytics, *[]map[string]any) {
	t.Helper()
	var bodies []map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, http.MethodPost, r.Method)
		assert.Equal(t, EndpointFunnel, r.URL.Path)
		assert.Equal(t, token, r.Header.Get("Authorization"))
		var body map[string]any
		require.NoError(t, json.NewDecoder(r.Body).Decode(&body))
		bodies = append(bodies, body)
		status, out := respond(body)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(out))
	}))
	t.Cleanup(srv.Close)
	return NewAnalytics(srv.URL, srv.Client(), func() time.Time { return t0 }).WithLimiter(&observed{}), &bodies
}

// funnelProducts — ответ с карточками nmId ids (форма спецификации WB, сокращена).
func funnelProducts(ids ...int) string {
	rows := make([]string, len(ids))
	for i, id := range ids {
		rows[i] = fmt.Sprintf(`{"product":{"nmId":%d,"vendorCode":"a"},"statistic":{"selected":{"openCount":%d}}}`, id, id)
	}
	return `{"data":{"products":[` + strings.Join(rows, ",") + `],"currency":"RUB"}}`
}

func TestFunnelDayRequestsOneDayAndSortsCardsAcrossPages(t *testing.T) {
	first := make([]int, FunnelPageLimit)
	for i := range first {
		first[i] = 5000 - i // убывание: порядок выдачи WB не закреплён
	}
	a, bodies := serveFunnel(t, func(body map[string]any) (int, string) {
		if body["offset"].(float64) == 0 {
			return http.StatusOK, funnelProducts(first...)
		}
		return http.StatusOK, funnelProducts(7, 3)
	})

	day, err := a.FunnelDay(context.Background(), "ref-1", token, "2026-11-17")

	require.NoError(t, err)
	assert.Equal(t, FunnelPageLimit+2, day.Products)
	assert.Equal(t, map[string]any{
		"selectedPeriod": map[string]any{"start": "2026-11-17", "end": "2026-11-17"},
		"limit":          float64(FunnelPageLimit), "offset": float64(0),
	}, (*bodies)[0])
	require.Len(t, *bodies, 2, "полная страница — запрос следующей")
	var body struct {
		Schema   string `json:"schema"`
		Currency string `json:"currency"`
		Products []struct {
			Product struct {
				NmID int `json:"nmId"`
			} `json:"product"`
			Statistic json.RawMessage `json:"statistic"`
		} `json:"products"`
	}
	require.NoError(t, json.Unmarshal(day.Body, &body))
	assert.Equal(t, FunnelSchema, body.Schema)
	assert.Equal(t, "RUB", body.Currency)
	assert.Equal(t, []int{3, 7, 4001}, []int{body.Products[0].Product.NmID, body.Products[1].Product.NmID, body.Products[2].Product.NmID})
	assert.JSONEq(t, `{"selected":{"openCount":3}}`, string(body.Products[0].Statistic), "карточка — как прислал WB")
	assert.NotContains(t, string(day.Body), token)
}

func TestFunnelDayWithoutCardsIsEmptyPage(t *testing.T) {
	a, _ := serveFunnel(t, func(map[string]any) (int, string) { return http.StatusOK, funnelProducts() })
	day, err := a.FunnelDay(context.Background(), "ref-1", token, "2026-11-17")
	require.NoError(t, err)
	assert.JSONEq(t, `{"schema":"wb-funnel-daily-v1","currency":"RUB","products":[]}`, string(day.Body))
}

func TestFunnelDayClassifiesResponses(t *testing.T) {
	full := make([]int, FunnelPageLimit)
	for i := range full {
		full[i] = i + 1
	}
	for name, tc := range map[string]struct {
		status int
		body   string
		class  apierr.Class
	}{
		"rate limit":        {http.StatusTooManyRequests, `{}`, apierr.ClassRateLimit},
		"no analytics":      {http.StatusForbidden, `{}`, apierr.ClassAuth},
		"bad period":        {http.StatusBadRequest, `{"detail":"bad period"}`, apierr.ClassInvalidRequest},
		"not object":        {http.StatusOK, `[]`, apierr.ClassInvalidResponse},
		"no products":       {http.StatusOK, `{"data":{}}`, apierr.ClassInvalidResponse},
		"card without nmId": {http.StatusOK, `{"data":{"products":[{"product":{}}]}}`, apierr.ClassInvalidResponse},
		"repeat on page 2":  {http.StatusOK, funnelProducts(full...), apierr.ClassInvalidResponse},
	} {
		t.Run(name, func(t *testing.T) {
			a, _ := serveFunnel(t, func(map[string]any) (int, string) { return tc.status, tc.body })
			_, err := a.FunnelDay(context.Background(), "ref-1", token, "2026-11-17")
			var apiErr *apierr.APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, tc.class, apiErr.Class)
		})
	}
}
