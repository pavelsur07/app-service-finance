package planner

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

func specFor(specs []store.TaskSpec, resource, period string) *store.TaskSpec {
	for i := range specs {
		if specs[i].ResourceType == resource && specs[i].PeriodKey == period {
			return &specs[i]
		}
	}
	return nil
}

func TestOrdersNotPlannedWithoutResource(t *testing.T) {
	p := Build(at("2026-10-21 12:00"), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))

	for _, r := range ordersResources {
		assert.Empty(t, periods(p.Ensure, r), r)
	}
	assert.Nil(t, refresh(p, KindOrders))
}

func TestOrdersEnsureFromOrdersFromToTodayAndTodayRefreshes(t *testing.T) {
	p := Build(at("2026-10-21 12:00"), []connections.Cabinet{seller("a", executor.ResourceOrdersFBO)}, DefaultConfig(msk))

	assert.Equal(t, []string{"2026-10-18", "2026-10-19", "2026-10-20", "2026-10-21"}, periods(p.Ensure, executor.ResourceOrdersFBO))
	assert.Empty(t, periods(p.Ensure, executor.ResourceOrdersFBS), "только объявленные ресурсы")

	today := specFor(p.Ensure, executor.ResourceOrdersFBO, "2026-10-21")
	require.NotNil(t, today)
	assert.True(t, today.Refresh, "сегодня перезагружается каждый проход")
	assert.Equal(t, PriorityCurrentMonth, today.Priority)

	backfill := specFor(p.Ensure, executor.ResourceOrdersFBO, "2026-10-18")
	require.NotNil(t, backfill)
	assert.False(t, backfill.Refresh)
	assert.Equal(t, PriorityBackfill, backfill.Priority)
}

func TestOrdersTailOncePerDay(t *testing.T) {
	cabs := []connections.Cabinet{seller("a", executor.ResourceOrdersFBO, executor.ResourceOrdersFBS)}
	p := Build(at("2026-10-21 00:20"), cabs, DefaultConfig(msk))

	tail := refresh(p, KindOrders)
	require.NotNil(t, tail)
	assert.Equal(t, "2026-10-21", tail.RunDate)
	assert.Equal(t, []string{"2026-10-20", "2026-10-19", "2026-10-18"}, periods(tail.Specs, executor.ResourceOrdersFBO), "хвост не глубже OrdersFrom")
	assert.Equal(t, []string{"2026-10-20", "2026-10-19", "2026-10-18"}, periods(tail.Specs, executor.ResourceOrdersFBS))
	for _, s := range tail.Specs {
		assert.True(t, s.Refresh)
		assert.Equal(t, PriorityRoutine, s.Priority)
	}
}

func TestOrdersTailCoversThirtyDaysForStatusChanges(t *testing.T) {
	cfg := DefaultConfig(msk)
	cfg.OrdersFrom = time.Date(2026, 8, 1, 0, 0, 0, 0, msk)
	p := Build(at("2026-10-21 00:20"), []connections.Cabinet{seller("a", executor.ResourceOrdersFBO)}, cfg)

	days := periods(refresh(p, KindOrders).Specs, executor.ResourceOrdersFBO)
	require.Len(t, days, 30)
	assert.Equal(t, "2026-10-20", days[0])
	assert.Equal(t, "2026-09-21", days[29])
}

func TestOrdersTailDoesNotReachBeforeOrdersFrom(t *testing.T) {
	p := Build(at("2026-10-19 10:00"), []connections.Cabinet{seller("a", executor.ResourceOrdersFBO)}, DefaultConfig(msk))

	assert.Equal(t, []string{"2026-10-18", "2026-10-19"}, periods(p.Ensure, executor.ResourceOrdersFBO))
	assert.Equal(t, []string{"2026-10-18"}, periods(refresh(p, KindOrders).Specs, executor.ResourceOrdersFBO))
}

func TestOrdersBackfillNotDeeperThanM2(t *testing.T) {
	cfg := DefaultConfig(msk)
	cfg.OrdersFrom = time.Date(2026, 1, 1, 0, 0, 0, 0, msk)
	p := Build(at("2026-10-21 12:00"), []connections.Cabinet{seller("a", executor.ResourceOrdersFBO)}, cfg)

	days := periods(p.Ensure, executor.ResourceOrdersFBO)
	require.NotEmpty(t, days)
	assert.Equal(t, "2026-08-01", days[0])
	assert.Equal(t, "2026-10-21", days[len(days)-1])
}

func TestOrdersMoscowMidnightDecidesToday(t *testing.T) {
	// 20.10 21:30 UTC = 21.10 00:30 МСК.
	p := Build(time.Date(2026, 10, 20, 21, 30, 0, 0, time.UTC), []connections.Cabinet{seller("a", executor.ResourceOrdersFBO)}, DefaultConfig(msk))

	today := specFor(p.Ensure, executor.ResourceOrdersFBO, "2026-10-21")
	require.NotNil(t, today)
	assert.True(t, today.Refresh)
}

func TestOrdersDoNotChangeFinancePlan(t *testing.T) {
	now := at("2026-10-21 12:00")
	finance := Build(now, []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))
	both := Build(now, []connections.Cabinet{seller("a", append(append([]string(nil), allFinance...), ordersResources...)...)}, DefaultConfig(msk))

	for _, r := range allFinance {
		assert.Equal(t, periods(finance.Ensure, r), periods(both.Ensure, r), r)
		for _, s := range finance.Ensure {
			if s.ResourceType == r {
				got := specFor(both.Ensure, r, s.PeriodKey)
				require.NotNil(t, got)
				assert.Equal(t, s.Priority, got.Priority, "приоритет начислений не меняется")
			}
		}
	}
	assert.Equal(t, refresh(finance, KindTail).Specs, refresh(both, KindTail).Specs)
}
