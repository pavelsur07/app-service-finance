package planner

import (
	"encoding/json"
	"flag"
	"os"
	"path/filepath"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozonperf"
)

// updateGolden — перезапись эталонов: go test ./internal/planner -run Golden -update.
var updateGolden = flag.Bool("update", false, "rewrite golden files")

// Эталон плана снят на коде до выноса ядра в fetchkit (wb-fetcher-finance, этап 0):
// перенос не должен менять ни одной задачи.
func TestBuildGolden(t *testing.T) {
	all := []string{
		executor.ResourceAccrualByDay, executor.ResourceAccrualTypes, executor.ResourceRealization, executor.ResourceMutualSettlement,
		executor.ResourceOrdersFBO, executor.ResourceOrdersFBS, executor.ResourceAccrualPostings,
		executor.ResourceProductsList, executor.ResourceProductsInfo, executor.ResourceStocks, executor.ResourcePrices,
		executor.ResourceStocksWarehouses, executor.ResourceSearchQueries, executor.ResourceSearchDetails, executor.ResourceReturns,
		executor.ResourceActions, executor.ResourceSupplies, executor.ResourceClusters,
		executor.ResourceAdsCampaigns, executor.ResourceAdsExpense, executor.ResourceAdsDaily,
		executor.ResourceAdsStatistics, executor.ResourceAdsStatisticsTail,
	}
	cabinets := []connections.Cabinet{
		{Ref: "full", Seller: &ozon.Credentials{ClientID: "c", APIKey: "k"},
			Performance: &ozonperf.Credentials{ClientID: "p", ClientSecret: "s"}, HasPerformance: true, ResourceTypes: all},
		seller("basic", allFinance...),
		{Ref: "nokey", ResourceTypes: allFinance},
	}
	moments := map[string]string{
		"monday_0600":    "2026-11-02 06:00",
		"tuesday_0300":   "2026-11-03 03:00",
		"first_day_0030": "2026-12-01 00:30",
		"sunday_2330":    "2026-11-08 23:30",
	}
	for name, moment := range moments {
		t.Run(name, func(t *testing.T) {
			got, err := json.MarshalIndent(Build(at(moment), cabinets, DefaultConfig(msk)), "", " ")
			require.NoError(t, err)
			path := filepath.Join("testdata", "plan_"+name+".golden.json")
			if *updateGolden {
				require.NoError(t, os.MkdirAll("testdata", 0o755))
				require.NoError(t, os.WriteFile(path, got, 0o600))
			}
			want, err := os.ReadFile(path)
			require.NoError(t, err)
			assert.JSONEq(t, string(want), string(got))
		})
	}
}
