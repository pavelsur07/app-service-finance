package wb

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"slices"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

const (
	// DefaultAdvertBaseURL — хост API продвижения (тот же токен кабинета, что у отчёта).
	DefaultAdvertBaseURL = "https://advert-api.wildberries.ru"
	// EndpointAdsExpenses — списания по кампаниям за период (фактический расход, updSum).
	EndpointAdsExpenses = "/adv/v1/upd"
	// EndpointAdsFullStats — статистика кампаний по дням и nmId (веса разнесения).
	EndpointAdsFullStats = "/adv/v3/fullstats"
	// FullStatsBatch — кампаний в одном запросе fullstats (предел WB).
	FullStatsBatch = 50
	// AdsSchema — форма страницы дня рекламы в raw S3 и в фактах.
	AdsSchema = "wb-ads-daily-v1"

	adsMaxBodyBytes = 32 << 20
)

// Ads — клиент API продвижения: расход дня и статистика его кампаний.
type Ads struct {
	baseURL string
	http    *http.Client
	now     func() time.Time
	limiter Limiter
}

func NewAds(baseURL string, httpClient *http.Client, now func() time.Time) *Ads {
	return &Ads{baseURL: strings.TrimRight(baseURL, "/"), http: httpClient, now: now}
}

// WithLimiter — темп своего семейства методов (не квота отчёта Symfony).
func (a *Ads) WithLimiter(l Limiter) *Ads {
	a.limiter = l
	return a
}

// AdsDay — страница дня: списания и статистика кампаний из них, как пришли.
type AdsDay struct {
	Body      []byte
	Campaigns int
}

// Day — upd за день, затем fullstats по его кампаниям порциями FullStatsBatch.
func (a *Ads) Day(ctx context.Context, ref, token, day string) (AdsDay, error) {
	expenses, err := a.list(ctx, ref, token, EndpointAdsExpenses, url.Values{"from": {day}, "to": {day}})
	if err != nil {
		return AdsDay{}, err
	}
	ids, err := campaignIDs(expenses)
	if err != nil {
		return AdsDay{}, invalidAds(EndpointAdsExpenses, err)
	}
	stats := []json.RawMessage{}
	for start := 0; start < len(ids); start += FullStatsBatch {
		batch := ids[start:min(start+FullStatsBatch, len(ids))]
		rows, err := a.list(ctx, ref, token, EndpointAdsFullStats,
			url.Values{"ids": {strings.Join(batch, ",")}, "beginDate": {day}, "endDate": {day}})
		if err != nil {
			return AdsDay{}, err
		}
		stats = append(stats, rows...)
	}
	body, err := json.Marshal(map[string]any{"schema": AdsSchema, "expenses": expenses, "statistics": stats})
	if err != nil {
		return AdsDay{}, fmt.Errorf("wb: encode ads day: %w", err)
	}
	return AdsDay{Body: body, Campaigns: len(ids)}, nil
}

// list — GET метода, ответ — JSON-список объектов (null и 204 — пустой).
func (a *Ads) list(ctx context.Context, ref, token, endpoint string, q url.Values) ([]json.RawMessage, error) {
	if a.limiter != nil {
		if err := a.limiter.Wait(ctx, ref, endpoint); err != nil {
			return nil, err
		}
	}
	rows, err := a.get(ctx, token, endpoint, q)
	if a.limiter != nil {
		var apiErr *apierr.APIError
		if errors.As(err, &apiErr) {
			a.limiter.Observe(ref, endpoint, apiErr)
		} else if err == nil {
			a.limiter.Observe(ref, endpoint, nil)
		}
	}
	return rows, err
}

func (a *Ads) get(ctx context.Context, token, endpoint string, q url.Values) ([]json.RawMessage, error) {
	body, err := roundTrip(ctx, a.http, a.now, http.MethodGet, a.baseURL+endpoint+"?"+q.Encode(), nil, token, endpoint)
	if err != nil {
		return nil, err
	}
	if body == nil {
		return []json.RawMessage{}, nil
	}
	if strings.TrimSpace(string(body)) == "null" {
		return []json.RawMessage{}, nil
	}
	var rows []json.RawMessage
	if err := json.Unmarshal(body, &rows); err != nil || rows == nil {
		return nil, invalidAds(endpoint, errors.New("body is not a JSON list"))
	}
	for _, row := range rows {
		if len(row) == 0 || row[0] != '{' {
			return nil, invalidAds(endpoint, errors.New("list item is not an object"))
		}
	}
	return rows, nil
}

// campaignIDs — advertId списаний (число или строка из цифр), без повторов, по возрастанию.
func campaignIDs(expenses []json.RawMessage) ([]string, error) {
	seen := map[string]bool{}
	var ids []string
	for i, raw := range expenses {
		id, err := AdvertID(raw)
		if err != nil {
			return nil, fmt.Errorf("expenses[%d]: %w", i, err)
		}
		if !seen[id] {
			seen[id] = true
			ids = append(ids, id)
		}
	}
	sortNumeric(ids)
	return ids, nil
}

// AdvertID — advertId объекта как строка цифр.
func AdvertID(raw json.RawMessage) (string, error) {
	var row struct {
		AdvertID json.RawMessage `json:"advertId"`
	}
	if err := json.Unmarshal(raw, &row); err != nil {
		return "", fmt.Errorf("not an object: %w", err)
	}
	id := strings.Trim(string(row.AdvertID), `"`)
	if id == "" || strings.Trim(id, "0123456789") != "" || len(id) > 20 {
		return "", fmt.Errorf("advertId %q is not a positive integer", string(row.AdvertID))
	}
	return id, nil
}

// sortNumeric — по числовому значению строк цифр без ведущих нулей.
func sortNumeric(ids []string) {
	slices.SortFunc(ids, func(a, b string) int {
		if c := cmp.Compare(len(a), len(b)); c != 0 {
			return c
		}
		return strings.Compare(a, b)
	})
}

// roundTrip — запрос к API WB тем же токеном кабинета: тело ответа 200, nil при
// 204, иначе ошибка класса apierr; токен в текст ошибок не попадает.
func roundTrip(ctx context.Context, client *http.Client, now func() time.Time, method, target string, payload []byte, token, endpoint string) ([]byte, error) {
	var reader io.Reader
	if payload != nil {
		reader = bytes.NewReader(payload)
	}
	req, err := http.NewRequestWithContext(ctx, method, target, reader)
	if err != nil {
		return nil, fmt.Errorf("wb: build request: %w", err)
	}
	req.Header.Set("Authorization", token)
	req.Header.Set("Accept", "application/json")
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := client.Do(req)
	if err != nil {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		return nil, &apierr.APIError{Source: Source, Class: apierr.ClassTransient, Endpoint: endpoint, Err: redactErr(err, token)}
	}
	defer func() { _ = resp.Body.Close() }()
	body, err := io.ReadAll(io.LimitReader(resp.Body, adsMaxBodyBytes+1))
	if err != nil {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		return nil, &apierr.APIError{Source: Source, Class: apierr.ClassTransient, Endpoint: endpoint, Status: resp.StatusCode, Err: redactErr(err, token)}
	}
	fail := func(class apierr.Class, pause time.Duration) error {
		return &apierr.APIError{Source: Source, Class: class, Endpoint: endpoint, Status: resp.StatusCode, Message: excerpt(body, token), RetryAfter: pause}
	}
	switch resp.StatusCode {
	case http.StatusNoContent:
		return nil, nil
	case http.StatusOK:
	case http.StatusTooManyRequests:
		return nil, fail(apierr.ClassRateLimit, retryAfter(resp.Header, now()))
	case http.StatusUnauthorized, http.StatusForbidden:
		return nil, fail(apierr.ClassAuth, 0)
	case http.StatusBadRequest:
		return nil, fail(apierr.ClassInvalidRequest, 0)
	case http.StatusNotFound, http.StatusGone:
		return nil, fail(apierr.ClassObsoleteEndpoint, 0)
	default:
		return nil, fail(apierr.ClassTransient, 0)
	}
	if len(body) > adsMaxBodyBytes {
		return nil, invalidAds(endpoint, fmt.Errorf("body exceeds %d bytes", adsMaxBodyBytes))
	}
	return body, nil
}

func invalidAds(endpoint string, err error) *apierr.APIError {
	return &apierr.APIError{Source: Source, Class: apierr.ClassInvalidResponse, Endpoint: endpoint, Status: http.StatusOK, Err: err}
}
