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
	type product struct {
		nmID int64
		raw  json.RawMessage
	}
	var products []product
	seen := map[int64]bool{}
	currency := ""
	for page := 0; ; page++ {
		if page >= maxFunnelPages {
			return FunnelDay{}, invalidAds(EndpointFunnel, fmt.Errorf("more than %d full pages", maxFunnelPages))
		}
		payload, err := json.Marshal(map[string]any{
			"selectedPeriod": map[string]string{"start": day, "end": day},
			"limit":          FunnelPageLimit, "offset": page * FunnelPageLimit,
		})
		if err != nil {
			return FunnelDay{}, fmt.Errorf("wb: encode request: %w", err)
		}
		body, err := a.post(ctx, ref, token, payload)
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
			return FunnelDay{}, invalidAds(EndpointFunnel, errors.New(`body has no "data.products" list`))
		}
		for _, raw := range resp.Data.Products {
			var row struct {
				Product struct {
					NmID int64 `json:"nmId"`
				} `json:"product"`
			}
			if json.Unmarshal(raw, &row) != nil || row.Product.NmID <= 0 || seen[row.Product.NmID] {
				return FunnelDay{}, invalidAds(EndpointFunnel, errors.New("product without nmId or nmId repeated between pages"))
			}
			seen[row.Product.NmID] = true
			products = append(products, product{nmID: row.Product.NmID, raw: raw})
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
	out, err := json.Marshal(map[string]any{"schema": FunnelSchema, "currency": currency, "products": rows})
	if err != nil {
		return FunnelDay{}, fmt.Errorf("wb: encode funnel day: %w", err)
	}
	return FunnelDay{Body: out, Products: len(rows)}, nil
}

func (a *Analytics) post(ctx context.Context, ref, token string, payload []byte) ([]byte, error) {
	if a.limiter != nil {
		if err := a.limiter.Wait(ctx, ref, EndpointFunnel); err != nil {
			return nil, err
		}
	}
	body, err := roundTrip(ctx, a.http, a.now, http.MethodPost, a.baseURL+EndpointFunnel, payload, token, EndpointFunnel)
	if a.limiter != nil {
		var apiErr *apierr.APIError
		if errors.As(err, &apiErr) {
			a.limiter.Observe(ref, EndpointFunnel, apiErr)
		} else if err == nil {
			a.limiter.Observe(ref, EndpointFunnel, nil)
		}
	}
	return body, err
}
