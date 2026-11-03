package limits

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/ratelimit"
)

func TestDefaultConfigMatchesApiDoc(t *testing.T) {
	cfg := DefaultConfig()
	assert.Equal(t, ratelimit.EndpointLimit{Start: 0.3, Floor: 0.05, Ceiling: 2, DefaultPause: 10 * time.Second}, cfg.Endpoints[ozon.EndpointAccrualByDay])
	for endpoint, l := range cfg.Endpoints {
		assert.LessOrEqual(t, l.Floor, l.Start, endpoint)
		assert.LessOrEqual(t, l.Start, l.Ceiling, endpoint)
		assert.Positive(t, l.DefaultPause, endpoint)
	}
}

func TestDefaultConfigCoversPostingLists(t *testing.T) {
	cfg := DefaultConfig()
	for _, endpoint := range []string{ozon.EndpointPostingFBOList, ozon.EndpointPostingFBSList, ozon.EndpointAccrualPostings} {
		limit, ok := cfg.Endpoints[endpoint]
		require.True(t, ok, endpoint)
		assert.LessOrEqual(t, limit.Start, 0.2, "ключ кабинета общий с Symfony — старт не выше 0,2 запроса/с")
		assert.Positive(t, limit.Floor)
		assert.Positive(t, limit.DefaultPause)
	}
}

func TestDefaultConfigSnapshotsShareKeyQuota(t *testing.T) {
	cfg := DefaultConfig()
	for _, endpoint := range []string{ozon.EndpointPrices, ozon.EndpointWarehouseStocks, ozon.EndpointSearchQueries, ozon.EndpointSearchQueriesDetails, ozon.EndpointReturns,
		ozon.EndpointActions, ozon.EndpointActionProducts, ozon.EndpointActionCandidates, ozon.EndpointSupplyOrderList,
		ozon.EndpointSupplyOrderGet, ozon.EndpointSupplyOrderBundle, ozon.EndpointClusters} {
		limit, ok := cfg.Endpoints[endpoint]
		require.True(t, ok, endpoint)
		assert.True(t, limit.SharedQuota, "квота ключа общая — 429 только пауза: %s", endpoint)
		assert.LessOrEqual(t, limit.Start, 0.2, endpoint)
	}
}
