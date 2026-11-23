package wb

import (
	"context"
	"encoding/json"
	"errors"
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

func serveAds(t *testing.T, h http.HandlerFunc) (*Ads, *observed) {
	t.Helper()
	srv := httptest.NewServer(h)
	t.Cleanup(srv.Close)
	lim := &observed{}
	return NewAds(srv.URL, srv.Client(), func() time.Time { return t0 }).WithLimiter(lim), lim
}

func TestAdsDayReadsExpensesThenFullStatsInBatches(t *testing.T) {
	var fullstats []string
	a, lim := serveAds(t, func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, token, r.Header.Get("Authorization"))
		switch r.URL.Path {
		case EndpointAdsExpenses:
			assert.Equal(t, "2026-11-10", r.URL.Query().Get("from"))
			assert.Equal(t, "2026-11-10", r.URL.Query().Get("to"))
			rows := []string{`{"advertId":10,"updSum":5}`, `{"advertId":"9","updSum":1}`, `{"advertId":10,"updSum":2}`}
			for i := 100; i < 150; i++ {
				rows = append(rows, fmt.Sprintf(`{"advertId":%d,"updSum":1}`, i))
			}
			_, _ = w.Write([]byte("[" + strings.Join(rows, ",") + "]"))
		case EndpointAdsFullStats:
			assert.Equal(t, "2026-11-10", r.URL.Query().Get("beginDate"))
			fullstats = append(fullstats, r.URL.Query().Get("ids"))
			_, _ = w.Write([]byte(`[{"advertId":9,"days":[]}]`))
		default:
			t.Errorf("unexpected %s", r.URL.Path)
		}
	})

	day, err := a.Day(context.Background(), "ref-1", token, "2026-11-10")
	require.NoError(t, err)

	assert.Equal(t, 52, day.Campaigns, "повтор кампании 10 — один id")
	require.Len(t, fullstats, 2, "52 кампании — две порции по 50")
	assert.True(t, strings.HasPrefix(fullstats[0], "9,10,100,"), "по числовому значению: %s", fullstats[0])
	assert.Equal(t, "148,149", fullstats[1])
	var body struct {
		Schema     string            `json:"schema"`
		Expenses   []json.RawMessage `json:"expenses"`
		Statistics []json.RawMessage `json:"statistics"`
	}
	require.NoError(t, json.Unmarshal(day.Body, &body))
	assert.Equal(t, AdsSchema, body.Schema)
	assert.Len(t, body.Expenses, 53)
	assert.Len(t, body.Statistics, 2)
	assert.Equal(t, "ref-1 "+EndpointAdsExpenses, lim.waits[0])
	assert.Len(t, lim.observes, 3)
}

func TestAdsDayWithoutExpensesSkipsFullStats(t *testing.T) {
	a, _ := serveAds(t, func(w http.ResponseWriter, r *http.Request) {
		require.Equal(t, EndpointAdsExpenses, r.URL.Path)
		_, _ = w.Write([]byte(`null`))
	})

	day, err := a.Day(context.Background(), "ref-1", token, "2026-11-10")
	require.NoError(t, err)
	assert.JSONEq(t, `{"schema":"wb-ads-daily-v1","expenses":[],"statistics":[]}`, string(day.Body))
}

func TestAdsErrorsAreClassifiedWithoutToken(t *testing.T) {
	for status, class := range map[int]apierr.Class{
		http.StatusUnauthorized: apierr.ClassAuth, http.StatusTooManyRequests: apierr.ClassRateLimit, http.StatusBadGateway: apierr.ClassTransient,
	} {
		a, _ := serveAds(t, func(w http.ResponseWriter, _ *http.Request) {
			w.Header().Set("X-Ratelimit-Retry", "7")
			w.WriteHeader(status)
			_, _ = w.Write([]byte(`{"error":"bad token ` + token + `"}`))
		})

		_, err := a.Day(context.Background(), "ref-1", token, "2026-11-10")
		var apiErr *apierr.APIError
		require.True(t, errors.As(err, &apiErr), "status %d", status)
		assert.Equal(t, class, apiErr.Class)
		assert.NotContains(t, err.Error()+apiErr.Message, token)
		if class == apierr.ClassRateLimit {
			assert.Equal(t, 7*time.Second, apiErr.RetryAfter)
		}
	}
}

func TestAdsRejectsBadAdvertID(t *testing.T) {
	a, _ := serveAds(t, func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`[{"advertId":"x1","updSum":1}]`))
	})

	_, err := a.Day(context.Background(), "ref-1", token, "2026-11-10")
	var apiErr *apierr.APIError
	require.True(t, errors.As(err, &apiErr))
	assert.Equal(t, apierr.ClassInvalidResponse, apiErr.Class)
}
