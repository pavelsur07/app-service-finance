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

var returnsMSK = time.FixedZone("MSK", 3*60*60)

func returnsDay() (time.Time, time.Time) {
	from := time.Date(2026, 10, 10, 0, 0, 0, 0, returnsMSK)
	return from, from.AddDate(0, 0, 1)
}

// returnsBody — живая форма 24.10.2026 (сокращена); at — logistic.return_date.
func returnsBody(hasNext bool, at string, ids ...int64) string {
	items := make([]string, len(ids))
	for i, id := range ids {
		items[i] = fmt.Sprintf(`{"id":%d,"type":"Cancellation","schema":"Fbo","product":{"sku":10},"logistic":{"return_date":%q,"final_moment":null}}`, id, at)
	}
	return fmt.Sprintf(`{"returns":[%s],"has_next":%t}`, strings.Join(items, ","), hasNext)
}

func TestFetchReturnsWalksLastIDForMoscowDay(t *testing.T) {
	handler, bodies := productServer(t, EndpointReturns, func(body map[string]any) (int, string) {
		switch body["last_id"] {
		case float64(0):
			return http.StatusOK, returnsBody(true, "2026-10-09T21:19:36.270Z", 1001852505, 1001853268)
		default:
			return http.StatusOK, returnsBody(false, "2026-10-10T17:32:41.647Z", 1001853269)
		}
	})
	from, to := returnsDay()
	var pages []Page
	err := clientFor(t, handler).FetchReturns(context.Background(), testCreds, "conn-1", from, to, "", 1, func(p Page) error {
		pages = append(pages, p)
		return nil
	})

	require.NoError(t, err)
	require.Len(t, pages, 2)
	assert.Equal(t, []string{"1001853268", ""}, []string{pages[0].Cursor, pages[1].Cursor})
	assert.Equal(t, map[string]any{
		"filter": map[string]any{"logistic_return_date": map[string]any{"time_from": "2026-10-10T00:00:00+03:00", "time_to": "2026-10-10T23:59:59.999999999+03:00"}},
		"limit":  float64(ReturnsPageLimit), "last_id": float64(0),
	}, (*bodies)[0])
	assert.Equal(t, float64(1001853268), (*bodies)[1]["last_id"])
	assert.True(t, ReturnsDrift([]byte(`{"returns":[],"has_next":false}`)).Empty())
}

func TestFetchReturnsKeepsLastFractionalSecond(t *testing.T) {
	// Мок применяет фильтр как Ozon (включительно): возврат 23:59:59.647 входит в день.
	handler, _ := productServer(t, EndpointReturns, func(body map[string]any) (int, string) {
		f := body["filter"].(map[string]any)["logistic_return_date"].(map[string]any)
		to, err := time.Parse(time.RFC3339Nano, f["time_to"].(string))
		require.NoError(t, err)
		at := time.Date(2026, 10, 10, 23, 59, 59, 647_000_000, returnsMSK)
		if at.After(to) {
			return http.StatusOK, returnsBody(false, "")
		}
		return http.StatusOK, returnsBody(false, at.UTC().Format(time.RFC3339Nano), 9)
	})
	from, to := returnsDay()
	got := 0
	err := clientFor(t, handler).FetchReturns(context.Background(), testCreds, "conn-1", from, to, "", 1, func(p Page) error {
		page, err := ParseReturnsPage(p.Body, 0, from, to)
		got += page.Returns
		return err
	})
	require.NoError(t, err)
	assert.Equal(t, 1, got)
}

func TestFetchReturnsResumesFromCursor(t *testing.T) {
	handler, bodies := productServer(t, EndpointReturns, func(map[string]any) (int, string) {
		return http.StatusOK, returnsBody(false, "2026-10-10T10:00:00Z", 7)
	})
	from, to := returnsDay()
	err := clientFor(t, handler).FetchReturns(context.Background(), testCreds, "conn-1", from, to, "5", 3, func(p Page) error {
		assert.Equal(t, 3, p.No)
		return nil
	})
	require.NoError(t, err)
	assert.Equal(t, float64(5), (*bodies)[0]["last_id"])

	err = clientFor(t, handler).FetchReturns(context.Background(), testCreds, "conn-1", from, to, "x", 3, func(Page) error { return nil })
	assert.Error(t, err)
}

func TestFetchReturnsRejectsBrokenPages(t *testing.T) {
	cases := map[string]string{
		"not object":        `[]`,
		"no has_next":       `{"returns":[]}`,
		"no returns":        `{"has_next":false}`,
		"empty with next":   `{"returns":[],"has_next":true}`,
		"id not above last": returnsBody(false, "2026-10-10T10:00:00Z", 5),
		"ids not ascending": returnsBody(false, "2026-10-10T10:00:00Z", 9, 8),
		"day before":        returnsBody(false, "2026-10-09T20:59:59Z", 9),
		"day after":         returnsBody(false, "2026-10-10T21:00:00Z", 9),
		"no return date":    `{"returns":[{"id":9,"logistic":{}}],"has_next":false}`,
	}
	from, to := returnsDay()
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			handler, _ := productServer(t, EndpointReturns, func(map[string]any) (int, string) { return http.StatusOK, body })
			err := clientFor(t, handler).FetchReturns(context.Background(), testCreds, "conn-1", from, to, "5", 2, func(Page) error { return nil })
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestFetchReturnsPageLimitIsAbsolute(t *testing.T) {
	handler, bodies := productServer(t, EndpointReturns, func(map[string]any) (int, string) { return http.StatusOK, `{}` })
	from, to := returnsDay()
	err := clientFor(t, handler).FetchReturns(context.Background(), testCreds, "conn-1", from, to, "5", MaxReturnsPages+1, func(Page) error { return nil })
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
	assert.Empty(t, *bodies)
}
