package planner

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/executor"
)

var msk = func() *time.Location {
	loc, err := time.LoadLocation("Europe/Moscow")
	if err != nil {
		panic(err)
	}
	return loc
}()

func at(s string) time.Time {
	t, err := time.ParseInLocation("2006-01-02 15:04", s, msk)
	if err != nil {
		panic(err)
	}
	return t
}

func daily(ref string) connections.Cabinet {
	return connections.Cabinet{Ref: ref, ResourceTypes: []string{executor.ResourceDaily}}
}

func periods(specs []store.TaskSpec) []string {
	out := make([]string, 0, len(specs))
	for _, s := range specs {
		out = append(out, s.PeriodKey)
	}
	return out
}

func TestNightPassEnsuresTailNowAndYesterdayAfterStart(t *testing.T) {
	now := at("2026-11-02 03:00")
	plan := Build(now, []connections.Cabinet{daily("wb-1")}, DefaultConfig(msk))

	require.Len(t, plan.Ensure, 15, "вчера + 14 дней хвоста")
	byPeriod := map[string]store.TaskSpec{}
	for _, s := range plan.Ensure {
		assert.Equal(t, "wb-1", s.ConnectionRef)
		assert.Equal(t, executor.ResourceDaily, s.ResourceType)
		assert.False(t, s.Refresh, "ensure не перезагружает готовые дни")
		byPeriod[s.PeriodKey] = s
	}
	yesterday := byPeriod["2026-11-01"]
	assert.Equal(t, at("2026-11-02 04:00"), yesterday.RunAt, "отчёт за вчера — не раньше 04:00 МСК")
	assert.Equal(t, PriorityYesterday, yesterday.Priority)
	oldest := byPeriod["2026-10-18"]
	assert.Equal(t, now, oldest.RunAt, "хвост — сразу")
	assert.Equal(t, PriorityTail, oldest.Priority)

	require.Len(t, plan.Refreshes, 1)
	r := plan.Refreshes[0]
	assert.Equal(t, KindDailyTail, r.Kind)
	assert.Equal(t, "2026-11-02", r.RunDate)
	assert.Equal(t, "wb-1", r.ConnectionRef)
	assert.Equal(t, periods(plan.Ensure), periods(r.Specs))
	for _, s := range r.Specs {
		assert.True(t, s.Refresh)
		assert.Equal(t, at("2026-11-02 04:00"), s.RunAt, "перечитывание дня — после публикации вчерашнего")
	}
}

func TestDayAfterStartRunsNow(t *testing.T) {
	now := at("2026-11-02 09:15")
	plan := Build(now, []connections.Cabinet{daily("wb-1")}, DefaultConfig(msk))

	for _, s := range append(plan.Ensure, plan.Refreshes[0].Specs...) {
		assert.Equal(t, now, s.RunAt)
	}
}

func TestDayBoundaryIsMoscow(t *testing.T) {
	now := time.Date(2026, 11, 1, 21, 30, 0, 0, time.UTC) // 00:30 МСК 2 ноября
	plan := Build(now, []connections.Cabinet{daily("wb-1")}, DefaultConfig(msk))

	assert.Equal(t, "2026-11-02", plan.Refreshes[0].RunDate)
	assert.Contains(t, periods(plan.Ensure), "2026-11-01")
	assert.NotContains(t, periods(plan.Ensure), "2026-11-02", "сегодня не грузится")
}

func TestCabinetWithoutResourceGetsNothing(t *testing.T) {
	plan := Build(at("2026-11-02 05:00"), []connections.Cabinet{{Ref: "wb-1"}, {Ref: "wb-2", ResourceTypes: []string{"wb.other"}}}, DefaultConfig(msk))

	assert.Empty(t, plan.Ensure)
	assert.Empty(t, plan.Refreshes)
}

func TestTailDaysFromConfig(t *testing.T) {
	cfg := DefaultConfig(msk)
	cfg.TailDays = 2
	plan := Build(at("2026-11-02 05:00"), []connections.Cabinet{daily("a"), daily("b")}, cfg)

	assert.Len(t, plan.Ensure, 6)
	assert.Len(t, plan.Refreshes, 2, "свой проход на кабинет")
}
