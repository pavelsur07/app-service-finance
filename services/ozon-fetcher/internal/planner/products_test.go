package planner

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/executor"
)

func TestProductsSnapshotOnlyForTodayWithoutRefresh(t *testing.T) {
	cabs := []connections.Cabinet{seller("a", executor.ResourceProductsList, executor.ResourceProductsInfo)}
	// 00:05 МСК 22.10 — первый проход нового дня.
	p := Build(at("2026-10-22 00:05"), cabs, DefaultConfig(msk))

	for _, r := range []string{executor.ResourceProductsList, executor.ResourceProductsInfo} {
		assert.Equal(t, []string{"2026-10-22"}, periods(p.Ensure, r), r)
		s := specFor(p.Ensure, r, "2026-10-22")
		require.NotNil(t, s, r)
		assert.False(t, s.Refresh, "снимок дня — один раз")
		assert.Equal(t, PriorityRoutine, s.Priority)
		if r == executor.ResourceProductsInfo {
			assert.Equal(t, at("2026-10-22 00:06"), s.RunAt, "подробности — после списка")
		}
		for _, rf := range p.Refreshes {
			assert.Empty(t, periods(rf.Specs, r), "каталог не перечитывается")
		}
	}
}

func TestProductsNotPlannedWithoutResource(t *testing.T) {
	p := Build(at("2026-10-22 12:00"), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))
	assert.Empty(t, periods(p.Ensure, executor.ResourceProductsList))
	assert.Empty(t, periods(p.Ensure, executor.ResourceProductsInfo))

	onlyInfo := Build(at("2026-10-22 12:00"), []connections.Cabinet{seller("a", executor.ResourceProductsInfo)}, DefaultConfig(msk))
	assert.Empty(t, periods(onlyInfo.Ensure, executor.ResourceProductsInfo), "подробности — только со списком")
}

func TestProductsDoNotChangeOtherPlans(t *testing.T) {
	base := []string{executor.ResourceOrdersFBO, executor.ResourceAccrualPostings}
	base = append(base, allFinance...)
	without := Build(at("2026-10-22 03:00"), []connections.Cabinet{seller("a", base...)}, DefaultConfig(msk))
	with := Build(at("2026-10-22 03:00"), []connections.Cabinet{seller("a", append(base, executor.ResourceProductsList, executor.ResourceProductsInfo)...)}, DefaultConfig(msk))

	assert.Len(t, with.Ensure, len(without.Ensure)+2)
	assert.Equal(t, without.Refreshes, with.Refreshes)
}

func TestStocksSnapshotTodayFrom0500(t *testing.T) {
	cabs := []connections.Cabinet{seller("a", executor.ResourceStocks)}

	for _, now := range []string{"2026-10-22 00:05", "2026-10-25 12:00", "2026-10-26 00:05"} {
		p := Build(at(now), cabs, DefaultConfig(msk))
		day := now[:10]
		assert.Equal(t, []string{day}, periods(p.Ensure, executor.ResourceStocks), now)
		s := specFor(p.Ensure, executor.ResourceStocks, day)
		require.NotNil(t, s)
		assert.Equal(t, at(day+" 05:00"), s.RunAt, "и в воскресенье, и в понедельник — один снимок в 05:00")
		assert.False(t, s.Refresh)
		assert.Len(t, p.Ensure, 1, "отдельного снимка конца недели нет")
	}
}

func TestStocksRemovedWeekCloseFromStaleSnapshotIsIgnored(t *testing.T) {
	// Снимок кабинетов, закэшированный до снятия ресурса, ещё объявляет его.
	p := Build(at("2026-10-25 12:00"), []connections.Cabinet{seller("a", executor.ResourceStocks, "ozon.stocks.week-close")}, DefaultConfig(msk))
	assert.Equal(t, []string{"2026-10-25"}, periods(p.Ensure, executor.ResourceStocks))
	assert.Empty(t, periods(p.Ensure, "ozon.stocks.week-close"))
}

func TestStocksNotPlannedWithoutResource(t *testing.T) {
	p := Build(at("2026-10-25 12:00"), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))
	assert.Empty(t, periods(p.Ensure, executor.ResourceStocks))
}

func TestPricesAndWarehouseStocksSnapshotsFrom0500(t *testing.T) {
	cabs := []connections.Cabinet{seller("a", executor.ResourceStocks, executor.ResourceStocksWarehouses, executor.ResourcePrices)}

	p := Build(at("2026-10-25 00:05"), cabs, DefaultConfig(msk))

	day := "2026-10-25"
	assert.Equal(t, []string{day}, periods(p.Ensure, executor.ResourcePrices))
	assert.Equal(t, []string{day}, periods(p.Ensure, executor.ResourceStocksWarehouses))
	prices := specFor(p.Ensure, executor.ResourcePrices, day)
	require.NotNil(t, prices)
	assert.Equal(t, at(day+" 05:00"), prices.RunAt)
	assert.False(t, prices.Refresh)
	wh := specFor(p.Ensure, executor.ResourceStocksWarehouses, day)
	require.NotNil(t, wh)
	assert.Equal(t, at(day+" 05:01"), wh.RunAt, "минутой позже снимка остатков: SKU берутся из него")
	assert.Empty(t, p.Refreshes)
}

func TestWarehouseStocksNeedStocksResource(t *testing.T) {
	p := Build(at("2026-10-25 12:00"), []connections.Cabinet{seller("a", executor.ResourceStocksWarehouses, executor.ResourcePrices)}, DefaultConfig(msk))
	assert.Empty(t, periods(p.Ensure, executor.ResourceStocksWarehouses), "без снимка остатков нет SKU")
	assert.Equal(t, []string{"2026-10-25"}, periods(p.Ensure, executor.ResourcePrices), "цены от остатков не зависят")
}
