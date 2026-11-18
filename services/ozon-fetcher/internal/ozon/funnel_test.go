package ozon

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// funnelBody — страница воронки со строками SKU from..to−1 (форма документации Ozon).
func funnelBody(from, to int) string {
	rows := make([]string, 0, to-from)
	for sku := from; sku < to; sku++ {
		rows = append(rows, fmt.Sprintf(`{"dimensions":[{"id":"%d","name":"Товар"}],"metrics":[0,0,0]}`, sku))
	}
	return `{"result":{"data":[` + strings.Join(rows, ",") + `],"totals":[0,0,0]},"timestamp":"2026-11-18 10:00:00"}`
}

func collectFunnel(t *testing.T, handler http.Handler) ([]Page, int, error) {
	t.Helper()
	var pages []Page
	rows, err := clientFor(t, handler).FetchFunnel(context.Background(), testCreds, "conn-1", "2026-11-17", 1, func(p Page) error {
		pages = append(pages, p)
		return nil
	})
	return pages, rows, err
}

func TestFetchFunnelRequestsDayBySKUAndPagesByOffset(t *testing.T) {
	handler, bodies := productServer(t, EndpointFunnel, func(body map[string]any) (int, string) {
		if body["offset"].(float64) == 0 {
			return http.StatusOK, funnelBody(0, FunnelPageSize)
		}
		return http.StatusOK, funnelBody(FunnelPageSize, FunnelPageSize+2)
	})

	pages, rows, err := collectFunnel(t, handler)

	require.NoError(t, err)
	assert.Equal(t, FunnelPageSize+2, rows)
	require.Len(t, pages, 2)
	assert.Equal(t, []int{1, 2}, []int{pages[0].No, pages[1].No})
	assert.Equal(t, []string{"1000", ""}, []string{pages[0].Cursor, pages[1].Cursor}, "полная страница — есть следующая")
	metrics := make([]any, len(FunnelMetrics))
	for i, m := range FunnelMetrics {
		metrics[i] = m
	}
	assert.Equal(t, map[string]any{
		"date_from": "2026-11-17", "date_to": "2026-11-17", "metrics": metrics,
		"dimension": []any{"sku"}, "limit": float64(FunnelPageSize), "offset": float64(0),
	}, (*bodies)[0])
	assert.InDelta(t, FunnelPageSize, (*bodies)[1]["offset"], 0)
	assert.LessOrEqual(t, len(FunnelMetrics), 14, "Ozon принимает не больше 14 метрик")
}

func TestFetchFunnelEmptyDayIsOnePage(t *testing.T) {
	handler, _ := productServer(t, EndpointFunnel, func(map[string]any) (int, string) { return http.StatusOK, funnelBody(0, 0) })
	pages, rows, err := collectFunnel(t, handler)
	require.NoError(t, err)
	assert.Zero(t, rows)
	require.Len(t, pages, 1)
	assert.Empty(t, pages[0].Cursor)
}

func TestFetchFunnelRejectsBrokenResponses(t *testing.T) {
	for name, respond := range map[string]func(map[string]any) (int, string){
		"not object": func(map[string]any) (int, string) { return http.StatusOK, `[]` },
		"no result":  func(map[string]any) (int, string) { return http.StatusOK, `{}` },
		"row without sku": func(map[string]any) (int, string) {
			return http.StatusOK, `{"result":{"data":[{"dimensions":[],"metrics":[]}]}}`
		},
	} {
		t.Run(name, func(t *testing.T) {
			handler, _ := productServer(t, EndpointFunnel, respond)
			_, _, err := collectFunnel(t, handler)
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestIsSubscriptionRequired(t *testing.T) {
	// Живой ответ 24.10.2026 кабинету без нужного тарифа.
	handler, _ := productServer(t, EndpointFunnel, func(map[string]any) (int, string) {
		return http.StatusBadRequest, `{"code":3,"message":"deprecated metrics used","details":[]}`
	})
	_, _, err := collectFunnel(t, handler)
	assert.True(t, IsSubscriptionRequired(err))

	other, _ := productServer(t, EndpointFunnel, func(map[string]any) (int, string) {
		return http.StatusBadRequest, `{"code":3,"message":"invalid date_from"}`
	})
	_, _, err = collectFunnel(t, other)
	require.Error(t, err)
	assert.False(t, IsSubscriptionRequired(err))
}

func TestSubscriptionRefusalIsLoggedAsInfo(t *testing.T) {
	client, logs := newTestClient(t, func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		_, _ = w.Write([]byte(`{"code":3,"message":"deprecated metrics used"}`))
	}, time.Second)

	_, err := client.FetchFunnel(context.Background(), testCreds, "conn-1", "2026-11-17", 1, func(Page) error { return nil })

	require.True(t, IsSubscriptionRequired(err))
	assert.Contains(t, logs.String(), `"outcome":"subscription_required"`)
	assert.NotContains(t, logs.String(), `"level":"WARN"`, "нет тарифа — штатно, на каждом дне догона")
}

func TestFetchFunnelNullDataAndShiftedOrder(t *testing.T) {
	empty, _ := productServer(t, EndpointFunnel, func(map[string]any) (int, string) { return http.StatusOK, `{"result":{"data":null}}` })
	pages, rows, err := collectFunnel(t, empty)
	require.NoError(t, err)
	assert.Zero(t, rows)
	assert.Len(t, pages, 1)

	repeat, _ := productServer(t, EndpointFunnel, func(map[string]any) (int, string) { return http.StatusOK, funnelBody(0, FunnelPageSize) })
	_, _, err = collectFunnel(t, repeat)
	assert.ErrorIs(t, err, ErrFunnelShifted, "та же страница дважды — порядок сдвинулся")
}
