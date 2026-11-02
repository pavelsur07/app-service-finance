package wb

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

const token = "wb-secret-token-never-in-errors"

var t0 = time.Date(2026, 11, 2, 9, 0, 0, 0, time.UTC)

type observed struct {
	waits    []string
	observes []*apierr.APIError
}

func (o *observed) Wait(_ context.Context, ref, endpoint string) error {
	o.waits = append(o.waits, ref+" "+endpoint)
	return nil
}
func (o *observed) Observe(_, _ string, e *apierr.APIError) { o.observes = append(o.observes, e) }

func serve(t *testing.T, h http.HandlerFunc) (*Client, *observed) {
	t.Helper()
	srv := httptest.NewServer(h)
	t.Cleanup(srv.Close)
	lim := &observed{}
	return NewClient(srv.URL, srv.Client(), func() time.Time { return t0 }).WithLimiter(lim), lim
}

func req(rrd int64) ReportRequest {
	return ReportRequest{ConnectionRef: "ref-1", Token: token, DateFrom: "2026-11-01", DateTo: "2026-11-01", Period: PeriodDaily, RrdID: rrd, Limit: 3}
}

func TestRequestShape(t *testing.T) {
	c, lim := serve(t, func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, http.MethodPost, r.Method)
		assert.Equal(t, EndpointSalesReport, r.URL.Path)
		assert.Equal(t, token, r.Header.Get("Authorization"), "токен без Bearer")
		assert.Equal(t, "application/json", r.Header.Get("Content-Type"))
		var body map[string]any
		require.NoError(t, json.NewDecoder(r.Body).Decode(&body))
		assert.Equal(t, map[string]any{"dateFrom": "2026-11-01", "dateTo": "2026-11-01", "limit": float64(3), "rrdId": float64(42), "period": "daily"}, body)
		w.WriteHeader(http.StatusNoContent)
	})

	_, err := c.SalesReportPage(context.Background(), req(42))

	require.NoError(t, err)
	assert.Equal(t, []string{"ref-1 " + EndpointSalesReport}, lim.waits, "лимитер — по кабинету и методу")
	require.Len(t, lim.observes, 1)
	assert.Nil(t, lim.observes[0], "успех")
}

func TestFullPageHasMoreAndCursorFromLastRrdID(t *testing.T) {
	body := `[{"rrdId":5,"x":1},{"rrdId":7},{"rrdId":9}]`
	c, _ := serve(t, func(w http.ResponseWriter, _ *http.Request) { _, _ = io.WriteString(w, body) })

	page, err := c.SalesReportPage(context.Background(), req(0))

	require.NoError(t, err)
	assert.Equal(t, []byte(body), page.Body, "raw — байты ответа как есть")
	assert.Equal(t, 3, page.Rows)
	assert.Equal(t, int64(9), page.NextRrdID)
	assert.True(t, page.More, "строк = limit — есть следующая страница")
}

func TestShortPageIsLast(t *testing.T) {
	c, _ := serve(t, func(w http.ResponseWriter, _ *http.Request) { _, _ = io.WriteString(w, `[{"rrdId":11}]`) })

	page, err := c.SalesReportPage(context.Background(), req(9))

	require.NoError(t, err)
	assert.False(t, page.More)
	assert.Equal(t, int64(11), page.NextRrdID)
}

func TestNoContentAndEmptyListAreEmptyLastPage(t *testing.T) {
	for name, h := range map[string]http.HandlerFunc{
		"204": func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusNoContent) },
		"[]":  func(w http.ResponseWriter, _ *http.Request) { _, _ = io.WriteString(w, " [] ") },
	} {
		t.Run(name, func(t *testing.T) {
			c, _ := serve(t, h)
			page, err := c.SalesReportPage(context.Background(), req(0))
			require.NoError(t, err)
			assert.Equal(t, 0, page.Rows)
			assert.False(t, page.More)
			assert.Equal(t, []byte("[]"), page.Body, "пустой день — канонический пустой список")
		})
	}
}

func TestCursorMustGrow(t *testing.T) {
	c, _ := serve(t, func(w http.ResponseWriter, _ *http.Request) { _, _ = io.WriteString(w, `[{"rrdId":1},{"rrdId":9}]`) })

	_, err := c.SalesReportPage(context.Background(), req(9))

	_ = assertClass(t, err, apierr.ClassInvalidResponse)
}

func TestRateLimitPause(t *testing.T) {
	cases := map[string]struct {
		headers map[string]string
		want    time.Duration
	}{
		"X-Ratelimit-Retry":      {map[string]string{"X-Ratelimit-Retry": "30"}, 30 * time.Second},
		"Retry-After":            {map[string]string{"Retry-After": "45"}, 45 * time.Second},
		"Retry-After first":      {map[string]string{"Retry-After": "45", "X-Ratelimit-Retry": "30"}, 45 * time.Second},
		"X-Ratelimit-Reset":      {map[string]string{"X-Ratelimit-Reset": strconv.FormatInt(t0.Add(2*time.Minute).Unix(), 10)}, 2 * time.Minute},
		"X-Ratelimit-Reset past": {map[string]string{"X-Ratelimit-Reset": strconv.FormatInt(t0.Add(-time.Minute).Unix(), 10)}, DefaultRateLimitPause},
		"Retry-After HTTP date":  {map[string]string{"Retry-After": t0.Add(90 * time.Second).Format(http.TimeFormat)}, 90 * time.Second},
		"no headers":             {nil, DefaultRateLimitPause},
		"garbage":                {map[string]string{"Retry-After": "soon"}, DefaultRateLimitPause},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			c, lim := serve(t, func(w http.ResponseWriter, _ *http.Request) {
				for k, v := range tc.headers {
					w.Header().Set(k, v)
				}
				w.WriteHeader(http.StatusTooManyRequests)
			})
			_, err := c.SalesReportPage(context.Background(), req(0))
			apiErr := assertClass(t, err, apierr.ClassRateLimit)
			assert.Equal(t, tc.want, apiErr.RetryAfter)
			require.Len(t, lim.observes, 1)
			assert.Same(t, apiErr, lim.observes[0], "лимитер видит 429 и ставит паузу кабинету")
		})
	}
}

func TestStatusClasses(t *testing.T) {
	for status, want := range map[int]apierr.Class{
		401: apierr.ClassAuth, 403: apierr.ClassAuth, 400: apierr.ClassInvalidRequest,
		500: apierr.ClassTransient, 502: apierr.ClassTransient, 418: apierr.ClassTransient,
		// Снятый метод: ERROR и стоп ресурса для всех кабинетов, а не тихие повторы.
		404: apierr.ClassObsoleteEndpoint, 410: apierr.ClassObsoleteEndpoint,
	} {
		c, _ := serve(t, func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(status)
			_, _ = io.WriteString(w, `{"title":"echo `+token+`"}`)
		})
		_, err := c.SalesReportPage(context.Background(), req(0))
		apiErr := assertClass(t, err, want)
		assert.Equal(t, status, apiErr.Status)
		assert.Equal(t, Source, apiErr.Source)
		assert.NotContains(t, err.Error(), token, "токен не попадает в текст ошибки, даже если API его отразил")
	}
}

func TestTransportErrorIsTransient(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	srv.Close()
	c := NewClient(srv.URL, srv.Client(), func() time.Time { return t0 })

	_, err := c.SalesReportPage(context.Background(), req(0))

	_ = assertClass(t, err, apierr.ClassTransient)
	assert.NotContains(t, err.Error(), token)
}

func TestMalformedBodies(t *testing.T) {
	for name, body := range map[string]string{
		"not json":        `<html>`,
		"object":          `{"data":[]}`,
		"item not object": `[1,2]`,
		"empty 200":       ``,
		"no rrdId":        `[{"x":1}]`,
	} {
		t.Run(name, func(t *testing.T) {
			c, _ := serve(t, func(w http.ResponseWriter, _ *http.Request) { _, _ = io.WriteString(w, body) })
			_, err := c.SalesReportPage(context.Background(), req(0))
			_ = assertClass(t, err, apierr.ClassInvalidResponse)
		})
	}
}

func TestBodyOverLimitIsInvalidResponse(t *testing.T) {
	c, _ := serve(t, func(w http.ResponseWriter, _ *http.Request) {
		_, _ = io.WriteString(w, "["+strings.Repeat(" ", 64)+"]")
	})
	c.maxBody = 16

	_, err := c.SalesReportPage(context.Background(), req(0))

	_ = assertClass(t, err, apierr.ClassInvalidResponse)
}

func assertClass(t *testing.T, err error, want apierr.Class) *apierr.APIError {
	t.Helper()
	var apiErr *apierr.APIError
	require.True(t, errors.As(err, &apiErr), "ожидалась APIError, получено %v", err)
	assert.Equal(t, want, apiErr.Class, apiErr.Error())
	assert.Equal(t, EndpointSalesReport, apiErr.Endpoint)
	return apiErr
}
