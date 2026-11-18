package wb

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"slices"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

const (
	// EndpointFunnel — воронка продаж по карточкам за период (хост аналитики,
	// категория токена «Аналитика»)
	// (docs/tasks/marketplace-funnel/TASK.md, этап 1).
	EndpointFunnel = "/api/analytics/v3/sales-funnel/products"
	// FunnelPageLimit — карточек в ответе, максимум WB.
	FunnelPageLimit = 1000
	// FunnelSchema — форма страницы дня воронки в raw S3.
	FunnelSchema = "wb-funnel-daily-v1"

	// EndpointSearch — поиск по карточкам за период: видимость, позиция, переходы,
	// корзины и заказы из поиска. По документации WB — только с подпиской «Джем»;
	// форма отказа кабинету без неё не описана
	// (docs/tasks/marketplace-funnel/TASK.md, этап 3).
	EndpointSearch = "/api/v2/search-report/table/details"
	// SearchSchema — форма страницы дня поиска в raw S3.
	SearchSchema = "wb-search-daily-v1"

	// maxFunnelPages — защитный предел страниц дня (100 000 карточек).
	maxFunnelPages = 100
)

// Analytics — клиент аналитики продавца: воронка продаж дня.
type Analytics struct {
	baseURL string
	http    *http.Client
	now     func() time.Time
	limiter Limiter
}

func NewAnalytics(baseURL string, httpClient *http.Client, now func() time.Time) *Analytics {
	return &Analytics{baseURL: strings.TrimRight(baseURL, "/"), http: httpClient, now: now}
}

// WithLimiter — темп метода воронки на кабинет.
func (a *Analytics) WithLimiter(l Limiter) *Analytics {
	a.limiter = l
	return a
}

// FunnelDay — страница дня: карточки с их статистикой, как пришли.
type FunnelDay struct {
	Body     []byte
	Products int
}

// FunnelDay — воронка всех карточек кабинета за день day (YYYY-MM-DD): период
// в один день, страницы по offset. Карточки — по возрастанию nmId: порядок
// выдачи WB не закреплён, а перечитывание без изменений не должно давать
// новую ревизию. Карточка без nmId и повтор nmId между страницами — invalid_response.
func (a *Analytics) FunnelDay(ctx context.Context, ref, token, day string) (FunnelDay, error) {
	return a.dayReport(ctx, ref, token, EndpointFunnel, FunnelSchema,
		map[string]any{"selectedPeriod": map[string]string{"start": day, "end": day}},
		func(raw json.RawMessage) int64 {
			var row struct {
				Product struct {
					NmID int64 `json:"nmId"`
				} `json:"product"`
			}
			_ = json.Unmarshal(raw, &row)
			return row.Product.NmID
		})
}

// SearchDay — поиск по всем карточкам кабинета за день day: та же сборка
// страницы дня, что у воронки. Сортировка обязательна в запросе; итоговый
// порядок — по nmId. Отказ в доступе (402, 403) — конечный: без подписки
// повтор ничего не изменит, а текст отказа нужен в покрытии как есть.
func (a *Analytics) SearchDay(ctx context.Context, ref, token, day string) (FunnelDay, error) {
	out, err := a.searchDay(ctx, ref, token, day)
	var apiErr *apierr.APIError
	if errors.As(err, &apiErr) && (apiErr.Status == http.StatusPaymentRequired || apiErr.Status == http.StatusForbidden) {
		apiErr.Class = apierr.ClassInvalidRequest
	}
	return out, err
}

func (a *Analytics) searchDay(ctx context.Context, ref, token, day string) (FunnelDay, error) {
	return a.dayReport(ctx, ref, token, EndpointSearch, SearchSchema,
		map[string]any{
			"currentPeriod":   map[string]string{"start": day, "end": day},
			"orderBy":         map[string]string{"field": "openCard", "mode": "desc"},
			"positionCluster": "all", "includeSubstitutedSKUs": true, "includeSearchTexts": true,
		},
		func(raw json.RawMessage) int64 {
			var row struct {
				NmID int64 `json:"nmId"`
			}
			_ = json.Unmarshal(raw, &row)
			return row.NmID
		})
}

// dayReport — отчёт по карточкам за день одной страницей raw: запрос request
// со страницами по offset, карточки по возрастанию nmId (nmIDOf).
func (a *Analytics) dayReport(ctx context.Context, ref, token, endpoint, schema string, request map[string]any, nmIDOf func(json.RawMessage) int64) (FunnelDay, error) {
	type product struct {
		nmID int64
		raw  json.RawMessage
	}
	var products []product
	seen := map[int64]bool{}
	currency := ""
	for page := 0; ; page++ {
		if page >= maxFunnelPages {
			return FunnelDay{}, invalidAds(endpoint, fmt.Errorf("more than %d full pages", maxFunnelPages))
		}
		request["limit"], request["offset"] = FunnelPageLimit, page*FunnelPageLimit
		payload, err := json.Marshal(request)
		if err != nil {
			return FunnelDay{}, fmt.Errorf("wb: encode request: %w", err)
		}
		body, err := a.post(ctx, ref, token, endpoint, payload)
		if err != nil {
			return FunnelDay{}, err
		}
		var resp struct {
			Data *struct {
				Products []json.RawMessage `json:"products"`
				Currency string            `json:"currency"`
			} `json:"data"`
		}
		if json.Unmarshal(body, &resp) != nil || resp.Data == nil || resp.Data.Products == nil {
			return FunnelDay{}, invalidAds(endpoint, errors.New(`body has no "data.products" list`))
		}
		for _, raw := range resp.Data.Products {
			nmID := nmIDOf(raw)
			if nmID <= 0 || seen[nmID] {
				return FunnelDay{}, invalidAds(endpoint, errors.New("product without nmId or nmId repeated between pages"))
			}
			seen[nmID] = true
			products = append(products, product{nmID: nmID, raw: raw})
		}
		currency = cmp.Or(currency, resp.Data.Currency)
		if len(resp.Data.Products) < FunnelPageLimit {
			break
		}
	}
	slices.SortFunc(products, func(x, y product) int { return cmp.Compare(x.nmID, y.nmID) })
	rows := make([]json.RawMessage, len(products))
	for i, p := range products {
		rows[i] = p.raw
	}
	out, err := json.Marshal(map[string]any{"schema": schema, "currency": currency, "products": rows})
	if err != nil {
		return FunnelDay{}, fmt.Errorf("wb: encode funnel day: %w", err)
	}
	return FunnelDay{Body: out, Products: len(rows)}, nil
}

func (a *Analytics) post(ctx context.Context, ref, token, endpoint string, payload []byte) ([]byte, error) {
	if a.limiter != nil {
		if err := a.limiter.Wait(ctx, ref, endpoint); err != nil {
			return nil, err
		}
	}
	body, err := roundTrip(ctx, a.http, a.now, http.MethodPost, a.baseURL+endpoint, payload, token, endpoint)
	if a.limiter != nil {
		var apiErr *apierr.APIError
		if errors.As(err, &apiErr) {
			a.limiter.Observe(ref, endpoint, apiErr)
		} else if err == nil {
			a.limiter.Observe(ref, endpoint, nil)
		}
	}
	return body, err
}
