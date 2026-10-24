package ozon

import (
	"context"
	"net/http"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestFetchActionsUsesGet(t *testing.T) {
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, http.MethodGet, r.Method, "POST — 405")
		assert.Equal(t, EndpointActions, r.URL.Path)
		_, _ = w.Write([]byte(`{"result":[{"id":1977747,"title":"Эластичный бустинг","action_type":"ELASTIC_BOOSTING"},{"id":4375943}]}`))
	})
	_, ids, err := clientFor(t, handler).FetchActions(context.Background(), testCreds, "conn-1")
	require.NoError(t, err)
	assert.Equal(t, []int64{1977747, 4375943}, ids)

	for name, body := range map[string]string{"no result": `{}`, "no id": `{"result":[{"title":"x"}]}`, "dup": `{"result":[{"id":1},{"id":1}]}`} {
		bad := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte(body)) })
		_, _, err := clientFor(t, bad).FetchActions(context.Background(), testCreds, "conn-1")
		var apiErr *APIError
		require.ErrorAs(t, err, &apiErr, name)
		assert.Equal(t, ClassInvalidResponse, apiErr.Class, name)
	}
}

// Живая форма 24.10.2026: последняя страница с товарами — с непустым last_id,
// конец — пустая страница с тем же last_id.
func TestFetchActionProductsPagesAndGuards(t *testing.T) {
	handler, bodies := productServer(t, EndpointActionCandidates, func(body map[string]any) (int, string) {
		if body["last_id"] == "" {
			return http.StatusOK, `{"result":{"products":[{"id":49244839,"price":2800}],"total":1,"last_id":"4702983662"}}`
		}
		return http.StatusOK, `{"result":{"products":[],"total":1,"last_id":"4702983662"}}`
	})
	_, first, err := clientFor(t, handler).FetchActionProducts(context.Background(), testCreds, "conn-1", true, 7, "")
	require.NoError(t, err)
	assert.Equal(t, ActionProductsPage{Products: 1, Total: 1, LastID: "4702983662"}, first)
	assert.Equal(t, map[string]any{"action_id": float64(7), "limit": float64(ActionProductsLimit), "last_id": ""}, (*bodies)[0])
	_, end, err := clientFor(t, handler).FetchActionProducts(context.Background(), testCreds, "conn-1", true, 7, first.LastID)
	require.NoError(t, err)
	assert.Equal(t, 0, end.Products)

	for name, body := range map[string]string{
		"no products":  `{"result":{"total":1}}`,
		"same last_id": `{"result":{"products":[{}],"total":1,"last_id":"L"}}`,
	} {
		bad, _ := productServer(t, EndpointActionProducts, func(map[string]any) (int, string) { return http.StatusOK, body })
		_, _, err := clientFor(t, bad).FetchActionProducts(context.Background(), testCreds, "conn-1", false, 7, "L")
		var apiErr *APIError
		require.ErrorAs(t, err, &apiErr, name)
		assert.Equal(t, ClassInvalidResponse, apiErr.Class, name)
	}
}

func TestFetchSupplyListDetailsAndBundle(t *testing.T) {
	list, bodies := productServer(t, EndpointSupplyOrderList, func(map[string]any) (int, string) {
		return http.StatusOK, `{"order_ids":[134649642,1],"last_id":""}`
	})
	_, ids, next, err := clientFor(t, list).FetchSupplyOrderList(context.Background(), testCreds, "conn-1", "")
	require.NoError(t, err)
	assert.Equal(t, []int64{134649642, 1}, ids)
	assert.Empty(t, next)
	filter := (*bodies)[0]["filter"].(map[string]any)
	assert.Len(t, filter["states"], len(SupplyActiveStates))
	_, has := (*bodies)[0]["last_id"]
	assert.False(t, has, "первая страница — без last_id")

	repeat, _ := productServer(t, EndpointSupplyOrderList, func(map[string]any) (int, string) { return http.StatusOK, `{"order_ids":[1],"last_id":"L"}` })
	_, _, _, err = clientFor(t, repeat).FetchSupplyOrderList(context.Background(), testCreds, "conn-1", "L")
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)

	get, _ := productServer(t, EndpointSupplyOrderGet, func(map[string]any) (int, string) {
		return http.StatusOK, `{"orders":[{"order_id":134649642,"state":"IN_TRANSIT","supplies":[{"supply_id":1,"bundle_id":"01a13a7b"}]}]}`
	})
	_, bundles, err := clientFor(t, get).FetchSupplyOrders(context.Background(), testCreds, "conn-1", []int64{134649642})
	require.NoError(t, err)
	assert.Equal(t, []string{"01a13a7b"}, bundles)
	_, _, err = clientFor(t, get).FetchSupplyOrders(context.Background(), testCreds, "conn-1", []int64{5})
	require.ErrorAs(t, err, &apiErr, "заявка не из запрошенных")
	_, _, err = clientFor(t, get).FetchSupplyOrders(context.Background(), testCreds, "conn-1", make([]int64, MaxSupplyOrderGet+1))
	assert.Error(t, err)
	_, _, err = clientFor(t, get).FetchSupplyOrders(context.Background(), testCreds, "conn-1", []int64{134649642, 7})
	assert.ErrorIs(t, err, ErrSupplyOrderMissing, "заявка 7 не пришла")
	dup, _ := productServer(t, EndpointSupplyOrderGet, func(map[string]any) (int, string) {
		return http.StatusOK, `{"orders":[{"order_id":1,"supplies":[]},{"order_id":1,"supplies":[]}]}`
	})
	_, _, err = clientFor(t, dup).FetchSupplyOrders(context.Background(), testCreds, "conn-1", []int64{1})
	require.ErrorAs(t, err, &apiErr, "повтор заявки")

	bundle, _ := productServer(t, EndpointSupplyOrderBundle, func(map[string]any) (int, string) {
		return http.StatusOK, `{"items":[{"sku":286031076,"quantity":4}],"has_next":false,"last_id":"2029269084","total_count":1}`
	})
	_, page, err := clientFor(t, bundle).FetchSupplyBundle(context.Background(), testCreds, "conn-1", []string{"01a13a7b"}, "")
	require.NoError(t, err)
	assert.Equal(t, SupplyBundlePage{Items: 1, LastID: "2029269084"}, page)

	missing, _ := productServer(t, EndpointSupplyOrderBundle, func(map[string]any) (int, string) {
		return http.StatusBadRequest, `{"code":9,"message":"Bundles 87ff5dcb-dbe1-4dbb-9538-46f1b5e953f4 not found"}`
	})
	_, _, err = clientFor(t, missing).FetchSupplyBundle(context.Background(), testCreds, "conn-1", []string{"x"}, "")
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidRequest, apiErr.Class, "code 9 «not found» — не снятый метод")
	assert.True(t, IsUnknownBundle(err))
	six, _ := productServer(t, EndpointSupplyOrderBundle, func(map[string]any) (int, string) {
		return http.StatusBadRequest, `{"code":9,"message":"Bundles 87ff5dcb-dbe1-4dbb-9538-46f1b5e953f4, 923d7cbb-024f-4476-a601-82602cabb51c, ` +
			`489cf6b2-fea5-40db-bbd8-0fa8602c19ff, b0807854-b19f-4d87-ac5b-358ade771bec, 3b93d967-c9db-4c32-8a10-d76dbe952e08, ` +
			`66e00979-1c97-466d-8047-232e31d00000 not found"}`
	})
	_, _, err = clientFor(t, six).FetchSupplyBundle(context.Background(), testCreds, "conn-1", []string{"x"}, "")
	assert.True(t, IsUnknownBundle(err), "длинный текст обрезан до 200 символов — хвоста «not found» нет")
	other, _ := productServer(t, EndpointSupplyOrderBundle, func(map[string]any) (int, string) {
		return http.StatusBadRequest, `{"code":3,"message":"Request validation error: invalid GetSupplyOrderBundleRequest.Limit"}`
	})
	_, _, err = clientFor(t, other).FetchSupplyBundle(context.Background(), testCreds, "conn-1", []string{"x"}, "")
	assert.False(t, IsUnknownBundle(err), "прочие 400 — не снимок заново")
}

func TestFetchClustersCountsWarehouses(t *testing.T) {
	handler, bodies := productServer(t, EndpointClusters, func(map[string]any) (int, string) {
		return http.StatusOK, `{"clusters":[{"id":4,"name":"Москва","type":"CLUSTER_TYPE_OZON","macrolocal_cluster_id":4001,` +
			`"logistic_clusters":[{"warehouses":[{"warehouse_id":1,"type":"SORTING_CENTER","name":"A"},{"warehouse_id":2}]},{"warehouses":[{"warehouse_id":3}]}]}]}`
	})
	_, page, err := clientFor(t, handler).FetchClusters(context.Background(), testCreds, "conn-1", "CLUSTER_TYPE_OZON")
	require.NoError(t, err)
	assert.Equal(t, ClustersPage{Clusters: 1, Warehouses: 3}, page)
	assert.Equal(t, map[string]any{"cluster_type": "CLUSTER_TYPE_OZON"}, (*bodies)[0])

	bad, _ := productServer(t, EndpointClusters, func(map[string]any) (int, string) { return http.StatusOK, `{"clusters":[{"id":1}]}` })
	_, _, err = clientFor(t, bad).FetchClusters(context.Background(), testCreds, "conn-1", "CLUSTER_TYPE_CIS")
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
}

// Форма конца у крупного кабинета не проверена: без last_id страница не ошибка
// клиента (конец решает исполнитель по total), пустая страница списка поставок —
// конец и с непустым last_id.
func TestReferenceEndVariantsAreAccepted(t *testing.T) {
	noLast, _ := productServer(t, EndpointActionProducts, func(map[string]any) (int, string) {
		return http.StatusOK, `{"result":{"products":[{"id":1}],"total":1,"last_id":""}}`
	})
	_, page, err := clientFor(t, noLast).FetchActionProducts(context.Background(), testCreds, "conn-1", false, 7, "")
	require.NoError(t, err)
	assert.Equal(t, ActionProductsPage{Products: 1, Total: 1}, page)

	emptyEnd, _ := productServer(t, EndpointSupplyOrderList, func(map[string]any) (int, string) {
		return http.StatusOK, `{"order_ids":[],"last_id":"CXYZ"}`
	})
	_, ids, next, err := clientFor(t, emptyEnd).FetchSupplyOrderList(context.Background(), testCreds, "conn-1", "CABC")
	require.NoError(t, err)
	assert.Empty(t, ids)
	assert.Empty(t, next, "пустая страница — конец")
}
