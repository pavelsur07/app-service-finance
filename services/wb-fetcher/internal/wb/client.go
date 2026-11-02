// Package wb — клиент WB finance-api: детальный отчёт о реализации
// (docs/wb-api.md). Ответы классифицируются в apierr с Source "wb"; токен
// не попадает ни в текст ошибок, ни в логи.
package wb

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

const (
	// Source — источник в тексте ошибок и префикс ключей S3.
	Source = "wb"
	// DefaultBaseURL — хост finance-api.
	DefaultBaseURL = "https://finance-api.wildberries.ru"
	// EndpointSalesReport — детальный отчёт о реализации (день или неделя).
	EndpointSalesReport = "/api/finance/v1/sales-reports/detailed"
	// PageLimit — строк на страницу. Максимум метода — 100 000 (≈ 150 МБ тела);
	// 20 000 (≈ 30 МБ) держит две параллельные страницы с копией строк при
	// разборе в лимите памяти контейнера. Маленький кабинет — всё равно одна
	// страница с данными.
	PageLimit = 20000
	// DefaultTimeout — таймаут запроса страницы (как в Symfony).
	DefaultTimeout = 120 * time.Second
	// DefaultRateLimitPause — пауза после 429 без заголовков паузы.
	DefaultRateLimitPause = 70 * time.Second
	// PeriodDaily, PeriodWeekly — значения period тела запроса.
	PeriodDaily  = "daily"
	PeriodWeekly = "weekly"

	// maxBodyBytes — потолок страницы: 20 000 строк отчёта ≈ 30 МБ, запас ×2.
	maxBodyBytes = 64 << 20
	excerptRunes = 200
)

// Limiter — лимитер пар (кабинет, метод): fetchkit/ratelimit.Registry.
type Limiter interface {
	Wait(ctx context.Context, ref, endpoint string) error
	Observe(ref, endpoint string, apiErr *apierr.APIError)
}

// Client — HTTP-клиент finance-api.
type Client struct {
	baseURL string
	http    *http.Client
	now     func() time.Time
	limiter Limiter
	maxBody int64
}

func NewClient(baseURL string, httpClient *http.Client, now func() time.Time) *Client {
	return &Client{baseURL: strings.TrimRight(baseURL, "/"), http: httpClient, now: now, maxBody: maxBodyBytes}
}

// WithLimiter — ожидание токена перед запросом и учёт исхода (пауза после 429).
func (c *Client) WithLimiter(l Limiter) *Client {
	c.limiter = l
	return c
}

// ReportRequest — страница отчёта. RrdID — курсор: rrdId последней строки
// предыдущей страницы, 0 — первая страница.
type ReportRequest struct {
	ConnectionRef string
	Token         string
	DateFrom      string // YYYY-MM-DD
	DateTo        string
	Period        string
	RrdID         int64
	Limit         int
}

// ReportPage — байты ответа как есть и курсор следующей страницы.
type ReportPage struct {
	Body      []byte
	Rows      int
	NextRrdID int64 // rrdId последней строки; 0 — строк нет
	More      bool  // страница непустая — запросить следующую (конец отчёта — 204 или [])
}

// SalesReportPage — одна страница детального отчёта.
func (c *Client) SalesReportPage(ctx context.Context, r ReportRequest) (ReportPage, error) {
	if c.limiter != nil {
		if err := c.limiter.Wait(ctx, r.ConnectionRef, EndpointSalesReport); err != nil {
			return ReportPage{}, err
		}
	}
	page, err := c.salesReportPage(ctx, r)
	if c.limiter != nil {
		var apiErr *apierr.APIError
		if errors.As(err, &apiErr) {
			c.limiter.Observe(r.ConnectionRef, EndpointSalesReport, apiErr)
		} else if err == nil {
			c.limiter.Observe(r.ConnectionRef, EndpointSalesReport, nil)
		}
	}
	return page, err
}

func (c *Client) salesReportPage(ctx context.Context, r ReportRequest) (ReportPage, error) {
	payload, err := json.Marshal(map[string]any{
		"dateFrom": r.DateFrom, "dateTo": r.DateTo, "limit": r.Limit, "rrdId": r.RrdID, "period": r.Period,
	})
	if err != nil {
		return ReportPage{}, fmt.Errorf("wb: encode request: %w", err)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+EndpointSalesReport, bytes.NewReader(payload))
	if err != nil {
		return ReportPage{}, fmt.Errorf("wb: build request: %w", err)
	}
	req.Header.Set("Authorization", r.Token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		if ctx.Err() != nil {
			return ReportPage{}, ctx.Err()
		}
		return ReportPage{}, &apierr.APIError{Source: Source, Class: apierr.ClassTransient, Endpoint: EndpointSalesReport, Err: redactErr(err, r.Token)}
	}
	defer func() { _ = resp.Body.Close() }()

	body, err := io.ReadAll(io.LimitReader(resp.Body, c.maxBody+1))
	if err != nil {
		if ctx.Err() != nil {
			return ReportPage{}, ctx.Err()
		}
		return ReportPage{}, &apierr.APIError{Source: Source, Class: apierr.ClassTransient, Endpoint: EndpointSalesReport, Status: resp.StatusCode, Err: redactErr(err, r.Token)}
	}

	switch resp.StatusCode {
	case http.StatusNoContent:
		return ReportPage{Body: []byte("[]")}, nil
	case http.StatusOK:
	case http.StatusTooManyRequests:
		return ReportPage{}, c.apiError(apierr.ClassRateLimit, resp, body, r.Token, retryAfter(resp.Header, c.now()))
	case http.StatusUnauthorized, http.StatusForbidden:
		return ReportPage{}, c.apiError(apierr.ClassAuth, resp, body, r.Token, 0)
	case http.StatusBadRequest:
		return ReportPage{}, c.apiError(apierr.ClassInvalidRequest, resp, body, r.Token, 0)
	case http.StatusNotFound, http.StatusGone:
		return ReportPage{}, c.apiError(apierr.ClassObsoleteEndpoint, resp, body, r.Token, 0)
	default: // 5xx и прочие — временная, как в Symfony
		return ReportPage{}, c.apiError(apierr.ClassTransient, resp, body, r.Token, 0)
	}

	if int64(len(body)) > c.maxBody {
		return ReportPage{}, invalid(resp.StatusCode, fmt.Errorf("body exceeds %d bytes", c.maxBody))
	}
	return parsePage(resp.StatusCode, body, r.RrdID)
}

// parsePage проверяет форму: JSON-список объектов, rrdId последней строки
// растёт относительно курсора. Документация WB: запрашивать с rrdId последней
// строки, пока ответ не станет 204 или []; неполная страница концом не
// считается.
func parsePage(status int, body []byte, cursor int64) (ReportPage, error) {
	var rows []json.RawMessage
	if err := json.Unmarshal(body, &rows); err != nil || rows == nil {
		return ReportPage{}, invalid(status, errors.New("body is not a JSON list"))
	}
	if len(rows) == 0 {
		return ReportPage{Body: []byte("[]")}, nil
	}
	for _, row := range rows {
		if len(row) == 0 || row[0] != '{' {
			return ReportPage{}, invalid(status, errors.New("list item is not an object"))
		}
	}
	var last struct {
		RrdID *int64 `json:"rrdId"`
	}
	if err := json.Unmarshal(rows[len(rows)-1], &last); err != nil || last.RrdID == nil {
		return ReportPage{}, invalid(status, errors.New("last row has no numeric rrdId"))
	}
	if *last.RrdID <= cursor {
		return ReportPage{}, invalid(status, fmt.Errorf("rrdId %d does not grow after cursor %d", *last.RrdID, cursor))
	}
	return ReportPage{Body: body, Rows: len(rows), NextRrdID: *last.RrdID, More: true}, nil
}

func invalid(status int, err error) *apierr.APIError {
	return &apierr.APIError{Source: Source, Class: apierr.ClassInvalidResponse, Endpoint: EndpointSalesReport, Status: status, Err: err}
}

func (c *Client) apiError(class apierr.Class, resp *http.Response, body []byte, token string, pause time.Duration) *apierr.APIError {
	return &apierr.APIError{Source: Source, Class: class, Endpoint: EndpointSalesReport, Status: resp.StatusCode,
		Message: excerpt(body, token), RetryAfter: pause}
}

// retryAfter — пауза 429 в порядке Symfony (WbFinanceSalesReportClient):
// Retry-After (секунды или HTTP-дата), X-Ratelimit-Retry (секунды),
// X-Ratelimit-Reset (unix-время); иначе DefaultRateLimitPause.
func retryAfter(h http.Header, now time.Time) time.Duration {
	for _, c := range []struct {
		name     string
		relative bool
	}{{"Retry-After", true}, {"X-Ratelimit-Retry", true}, {"X-Ratelimit-Reset", false}} {
		v := strings.TrimSpace(h.Get(c.name))
		if v == "" {
			continue
		}
		if n, err := strconv.ParseInt(v, 10, 64); err == nil && n >= 0 {
			if c.relative && n <= 86400 {
				return time.Duration(max(n, 1)) * time.Second
			}
			if at := time.Unix(n, 0); at.After(now) {
				return at.Sub(now)
			}
			continue
		}
		if at, err := http.ParseTime(v); err == nil && at.After(now) {
			return at.Sub(now)
		}
	}
	return DefaultRateLimitPause
}

// excerpt — начало тела ошибки для журнала, без токена.
func excerpt(body []byte, token string) string {
	s := strings.TrimSpace(string(body))
	if token != "" {
		s = strings.ReplaceAll(s, token, "[redacted]")
	}
	if utf8.RuneCountInString(s) > excerptRunes {
		s = string([]rune(s)[:excerptRunes])
	}
	return s
}

type redacted struct{ msg string }

func (e redacted) Error() string { return e.msg }

func redactErr(err error, token string) error {
	if token == "" || !strings.Contains(err.Error(), token) {
		return err
	}
	return redacted{strings.ReplaceAll(err.Error(), token, "[redacted]")}
}
