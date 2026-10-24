package ozon

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"
)

// ErrSupplyOrderMissing — заявка из списка не пришла в деталях (пропала между
// запросами): снимок поставок — заново.
var ErrSupplyOrderMissing = errors.New("ozon: supply order of the list is missing in details")

// IsUnknownBundle — 400 code 9 «Bundles … not found» состава поставок:
// поставку отменили между деталями и составом (живая проверка 24.10.2026).
// Сверка по началу текста: Message обрезан до messageLimit, и при нескольких
// bundle_id хвост «not found» теряется.
func IsUnknownBundle(err error) bool {
	var apiErr *APIError
	return errors.As(err, &apiErr) && apiErr.Endpoint == EndpointSupplyOrderBundle && apiErr.Status == http.StatusBadRequest &&
		apiErr.Code == obsoleteCode && strings.HasPrefix(strings.ToLower(strings.TrimSpace(apiErr.Message)), "bundles ")
}

// Снимки акций, поставок в пути и кластеров
// (docs/tasks/marketplace-data-ingestion/stages/ozon-11-actions-supplies-clusters.md §2).
const (
	EndpointActions           = "/v1/actions"
	EndpointActionProducts    = "/v1/actions/products"
	EndpointActionCandidates  = "/v1/actions/candidates"
	EndpointSupplyOrderList   = "/v3/supply-order/list"
	EndpointSupplyOrderGet    = "/v3/supply-order/get"
	EndpointSupplyOrderBundle = "/v1/supply-order/bundle"
	EndpointClusters          = "/v1/cluster/list"
)

const (
	ActionProductsLimit = 1000
	SupplyListLimit     = 100
	// MaxSupplyOrderGet — предел order_ids в деталях (51 → 400 «between 1 and 50»).
	MaxSupplyOrderGet = 50
	SupplyBundleLimit = 100
)

// ClusterTypes — кластеры России и СНГ (без типа — 400).
var ClusterTypes = []string{"CLUSTER_TYPE_OZON", "CLUSTER_TYPE_CIS"}

// SupplyActiveStates — статусы заявок, товар которых ещё не в остатках.
var SupplyActiveStates = []string{
	"DATA_FILLING", "READY_TO_SUPPLY", "ACCEPTED_AT_SUPPLY_WAREHOUSE", "IN_TRANSIT",
	"ACCEPTANCE_AT_STORAGE_WAREHOUSE", "REPORTS_CONFIRMATION_AWAITING", "REPORT_REJECTED",
}

func jsonPage(resp Response) Page {
	return Page{No: 1, Body: resp.Body, ContentType: "application/json"}
}

// FetchActions — список акций кабинета (GET); id — для участников и кандидатов.
func (c *Client) FetchActions(ctx context.Context, creds Credentials, connectionRef string) (Page, []int64, error) {
	resp, err := c.Get(ctx, creds, connectionRef, EndpointActions)
	if err != nil {
		return Page{}, nil, err
	}
	var shape struct {
		Result *[]struct {
			ID *int64 `json:"id"`
		} `json:"result"`
	}
	if json.Unmarshal(resp.Body, &shape) != nil || shape.Result == nil {
		return Page{}, nil, invalidResponse(EndpointActions, `response has no "result" array`)
	}
	ids := make([]int64, 0, len(*shape.Result))
	seen := map[int64]bool{}
	for _, a := range *shape.Result {
		if a.ID == nil || *a.ID <= 0 || seen[*a.ID] {
			return Page{}, nil, invalidResponse(EndpointActions, "action without positive unique id")
		}
		seen[*a.ID] = true
		ids = append(ids, *a.ID)
	}
	return jsonPage(resp), ids, nil
}

// ActionProductsPage — страница участников или кандидатов акции.
type ActionProductsPage struct {
	Products int
	Total    int
	LastID   string
}

// FetchActionProducts — страница участников (candidates=false) или кандидатов
// акции после lastID. У малого кабинета (24.10.2026) последняя страница с
// товарами отдаёт непустой last_id, конец — пустая страница; у крупного форма
// конца не проверена, поэтому конец определяет исполнитель по пустой странице
// или по набранному total.
func (c *Client) FetchActionProducts(ctx context.Context, creds Credentials, connectionRef string, candidates bool, actionID int64, lastID string) (Page, ActionProductsPage, error) {
	endpoint := EndpointActionProducts
	if candidates {
		endpoint = EndpointActionCandidates
	}
	resp, err := c.Post(ctx, creds, connectionRef, endpoint, map[string]any{"action_id": actionID, "limit": ActionProductsLimit, "last_id": lastID})
	if err != nil {
		return Page{}, ActionProductsPage{}, err
	}
	var shape struct {
		Result *struct {
			Products *[]json.RawMessage `json:"products"`
			Total    *int               `json:"total"`
			LastID   *json.RawMessage   `json:"last_id"`
		} `json:"result"`
	}
	if json.Unmarshal(resp.Body, &shape) != nil || shape.Result == nil || shape.Result.Products == nil || shape.Result.Total == nil {
		return Page{}, ActionProductsPage{}, invalidResponse(endpoint, `response has no "result.products" array and "result.total"`)
	}
	page := ActionProductsPage{Products: len(*shape.Result.Products), Total: *shape.Result.Total}
	if shape.Result.LastID != nil {
		// last_id приходит строкой; число — на случай смены формы.
		page.LastID = strings.Trim(string(*shape.Result.LastID), `"`)
		if page.LastID == "null" {
			page.LastID = ""
		}
	}
	if page.Products > 0 && page.LastID != "" && page.LastID == lastID {
		// Товары с прежним last_id — обход не продвинется.
		return Page{}, ActionProductsPage{}, invalidResponse(endpoint, fmt.Sprintf("last_id of action %d did not advance", actionID))
	}
	return jsonPage(resp), page, nil
}

// FetchSupplyOrderList — страница номеров активных заявок после lastID; конец
// — last_id "" или пустая страница (форма конца у крупного кабинета не
// проверена: next "" возвращается для обоих).
func (c *Client) FetchSupplyOrderList(ctx context.Context, creds Credentials, connectionRef, lastID string) (Page, []int64, string, error) {
	body := map[string]any{
		"filter": map[string]any{"states": SupplyActiveStates},
		"limit":  SupplyListLimit, "sort_by": "ORDER_CREATION", "sort_dir": "DESC",
	}
	if lastID != "" {
		body["last_id"] = lastID
	}
	resp, err := c.Post(ctx, creds, connectionRef, EndpointSupplyOrderList, body)
	if err != nil {
		return Page{}, nil, "", err
	}
	var shape struct {
		OrderIDs *[]int64 `json:"order_ids"`
		LastID   *string  `json:"last_id"`
	}
	if json.Unmarshal(resp.Body, &shape) != nil || shape.OrderIDs == nil || shape.LastID == nil {
		return Page{}, nil, "", invalidResponse(EndpointSupplyOrderList, `response has no "order_ids" array and string "last_id"`)
	}
	next := strings.TrimSpace(*shape.LastID)
	if len(*shape.OrderIDs) == 0 {
		next = ""
	} else if next != "" && next == lastID {
		return Page{}, nil, "", invalidResponse(EndpointSupplyOrderList, "continuation marker without progress")
	}
	return jsonPage(resp), *shape.OrderIDs, next, nil
}

// FetchSupplyOrders — детали заявок (≤ MaxSupplyOrderGet); bundle_id поставок — для состава.
func (c *Client) FetchSupplyOrders(ctx context.Context, creds Credentials, connectionRef string, ids []int64) (Page, []string, error) {
	if len(ids) == 0 || len(ids) > MaxSupplyOrderGet {
		return Page{}, nil, fmt.Errorf("ozon: supply orders need 1..%d ids, got %d", MaxSupplyOrderGet, len(ids))
	}
	requested := map[int64]bool{}
	for _, id := range ids {
		requested[id] = true
	}
	resp, err := c.Post(ctx, creds, connectionRef, EndpointSupplyOrderGet, map[string]any{"order_ids": ids})
	if err != nil {
		return Page{}, nil, err
	}
	var shape struct {
		Orders *[]struct {
			OrderID  *int64 `json:"order_id"`
			Supplies []struct {
				BundleID string `json:"bundle_id"`
			} `json:"supplies"`
		} `json:"orders"`
	}
	if json.Unmarshal(resp.Body, &shape) != nil || shape.Orders == nil {
		return Page{}, nil, invalidResponse(EndpointSupplyOrderGet, `response has no "orders" array`)
	}
	var bundles []string
	got := map[int64]bool{}
	for _, o := range *shape.Orders {
		if o.OrderID == nil || !requested[*o.OrderID] || got[*o.OrderID] {
			return Page{}, nil, invalidResponse(EndpointSupplyOrderGet, "order outside the requested ids or repeated")
		}
		got[*o.OrderID] = true
		for _, s := range o.Supplies {
			if s.BundleID != "" {
				bundles = append(bundles, s.BundleID)
			}
		}
	}
	if len(got) != len(requested) {
		return Page{}, nil, fmt.Errorf("%w: %d of %d", ErrSupplyOrderMissing, len(requested)-len(got), len(requested))
	}
	return jsonPage(resp), bundles, nil
}

// SupplyBundlePage — страница состава поставок.
type SupplyBundlePage struct {
	Items   int
	HasNext bool
	LastID  string
}

// FetchSupplyBundle — страница состава поставок bundleIDs после lastID.
func (c *Client) FetchSupplyBundle(ctx context.Context, creds Credentials, connectionRef string, bundleIDs []string, lastID string) (Page, SupplyBundlePage, error) {
	resp, err := c.Post(ctx, creds, connectionRef, EndpointSupplyOrderBundle,
		map[string]any{"bundle_ids": bundleIDs, "is_asc": true, "limit": SupplyBundleLimit, "last_id": lastID})
	if err != nil {
		return Page{}, SupplyBundlePage{}, err
	}
	var shape struct {
		Items *[]struct {
			SKU *int64 `json:"sku"`
		} `json:"items"`
		HasNext *bool   `json:"has_next"`
		LastID  *string `json:"last_id"`
	}
	if json.Unmarshal(resp.Body, &shape) != nil || shape.Items == nil || shape.HasNext == nil {
		return Page{}, SupplyBundlePage{}, invalidResponse(EndpointSupplyOrderBundle, `response has no "items" array and "has_next"`)
	}
	for _, it := range *shape.Items {
		if it.SKU == nil {
			return Page{}, SupplyBundlePage{}, invalidResponse(EndpointSupplyOrderBundle, "bundle item without sku")
		}
	}
	page := SupplyBundlePage{Items: len(*shape.Items), HasNext: *shape.HasNext}
	if shape.LastID != nil {
		page.LastID = *shape.LastID
	}
	if page.HasNext && (page.Items == 0 || page.LastID == "" || page.LastID == lastID) {
		return Page{}, SupplyBundlePage{}, invalidResponse(EndpointSupplyOrderBundle, "has_next without progress")
	}
	return jsonPage(resp), page, nil
}

// ClustersPage — счётчики кластеров типа.
type ClustersPage struct {
	Clusters   int
	Warehouses int
}

// FetchClusters — кластеры и склады Ozon типа clusterType.
func (c *Client) FetchClusters(ctx context.Context, creds Credentials, connectionRef, clusterType string) (Page, ClustersPage, error) {
	resp, err := c.Post(ctx, creds, connectionRef, EndpointClusters, map[string]string{"cluster_type": clusterType})
	if err != nil {
		return Page{}, ClustersPage{}, err
	}
	var shape struct {
		Clusters *[]struct {
			ID               *int64 `json:"id"`
			LogisticClusters *[]struct {
				Warehouses []json.RawMessage `json:"warehouses"`
			} `json:"logistic_clusters"`
		} `json:"clusters"`
	}
	if json.Unmarshal(resp.Body, &shape) != nil || shape.Clusters == nil {
		return Page{}, ClustersPage{}, invalidResponse(EndpointClusters, `response has no "clusters" array`)
	}
	page := ClustersPage{Clusters: len(*shape.Clusters)}
	for _, cl := range *shape.Clusters {
		if cl.ID == nil || cl.LogisticClusters == nil {
			return Page{}, ClustersPage{}, invalidResponse(EndpointClusters, `cluster without "id" and "logistic_clusters"`)
		}
		for _, lc := range *cl.LogisticClusters {
			page.Warehouses += len(lc.Warehouses)
		}
	}
	return jsonPage(resp), page, nil
}
