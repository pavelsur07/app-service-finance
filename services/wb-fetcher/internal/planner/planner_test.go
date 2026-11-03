package planner

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/schedule"
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

func both(ref string) connections.Cabinet {
	return connections.Cabinet{Ref: ref, ResourceTypes: []string{executor.ResourceDaily, executor.ResourceWeekly}}
}

func weekly(ref string) connections.Cabinet {
	return connections.Cabinet{Ref: ref, ResourceTypes: []string{executor.ResourceWeekly}}
}

func byResource(specs []store.TaskSpec, resource string) map[string]store.TaskSpec {
	out := map[string]store.TaskSpec{}
	for _, s := range specs {
		if s.ResourceType == resource {
			out[s.PeriodKey] = s
		}
	}
	return out
}

func refresh(t *testing.T, plan schedule.Plan, kind string) schedule.Refresh {
	t.Helper()
	for _, r := range plan.Refreshes {
		if r.Kind == kind {
			return r
		}
	}
	t.Fatalf("no refresh %s", kind)
	return schedule.Refresh{}
}

func TestMondayNightEnsuresLastWeekAfterStartAndTwoPreviousNow(t *testing.T) {
	now := at("2026-11-02 03:00") // понедельник
	plan := Build(now, []connections.Cabinet{weekly("wb-1")}, DefaultConfig(msk))

	weeks := byResource(plan.Ensure, executor.ResourceWeekly)
	require.Len(t, weeks, 3)
	last := weeks["2026-W44"]
	assert.Equal(t, PriorityWeek, last.Priority)
	assert.Equal(t, at("2026-11-02 04:00"), last.RunAt, "прошлая неделя — с 04:00 МСК понедельника")
	assert.False(t, last.Refresh)
	for _, k := range []string{"2026-W43", "2026-W42"} {
		assert.Equal(t, PriorityTail, weeks[k].Priority, k)
		assert.Equal(t, now, weeks[k].RunAt, k)
	}
	assert.NotContains(t, weeks, "2026-W45", "текущая неделя не грузится")
	assert.Empty(t, byResource(plan.Ensure, executor.ResourceDaily), "дневного ресурса нет — дней нет")

	r := refresh(t, plan, KindWeeklyTail)
	assert.Equal(t, "2026-11-02", r.RunDate)
	assert.ElementsMatch(t, []string{"2026-W44", "2026-W43", "2026-W42"}, periods(r.Specs))
	for _, s := range r.Specs {
		assert.True(t, s.Refresh)
		assert.Equal(t, at("2026-11-02 04:00"), s.RunAt)
	}
}

func TestSundayStillPlansPreviousWeek(t *testing.T) {
	plan := Build(at("2026-11-08 23:00"), []connections.Cabinet{weekly("wb-1")}, DefaultConfig(msk))

	weeks := byResource(plan.Ensure, executor.ResourceWeekly)
	assert.Contains(t, weeks, "2026-W44")
	assert.NotContains(t, weeks, "2026-W45", "неделя ещё идёт")
}

func TestWeekBoundaryIsMoscow(t *testing.T) {
	now := time.Date(2026, 11, 1, 21, 30, 0, 0, time.UTC) // 00:30 МСК понедельника 2 ноября
	plan := Build(now, []connections.Cabinet{weekly("wb-1")}, DefaultConfig(msk))

	assert.Contains(t, byResource(plan.Ensure, executor.ResourceWeekly), "2026-W44")
}

func TestDailyWindowCoversRefreshedWeeksForReconciliation(t *testing.T) {
	// воскресенье: самая старая перечитываемая неделя W42 начинается 12.10,
	// вчера — 07.11; дневное окно продлевается с 14 дней до 26.
	plan := Build(at("2026-11-08 05:00"), []connections.Cabinet{both("wb-1")}, DefaultConfig(msk))

	days := byResource(plan.Ensure, executor.ResourceDaily)
	assert.Len(t, days, 27, "07.11 … 12.10")
	assert.Contains(t, days, "2026-10-12")
	assert.NotContains(t, days, "2026-10-11")
	assert.ElementsMatch(t, periods(refresh(t, plan, KindDailyTail).Specs), keys(days))
}

func TestDailyWindowNotShortenedWhenTailIsLonger(t *testing.T) {
	cfg := DefaultConfig(msk)
	cfg.TailDays = 30
	plan := Build(at("2026-11-02 05:00"), []connections.Cabinet{both("wb-1")}, cfg)

	assert.Len(t, byResource(plan.Ensure, executor.ResourceDaily), 31)
}

func TestDailyOnlyCabinetKeepsTail(t *testing.T) {
	plan := Build(at("2026-11-08 05:00"), []connections.Cabinet{daily("wb-1")}, DefaultConfig(msk))

	assert.Len(t, plan.Ensure, 15)
	assert.Len(t, plan.Refreshes, 1)
}

func TestYesterdayOutranksLastWeek(t *testing.T) {
	assert.Greater(t, PriorityYesterday, PriorityWeek)
	assert.Greater(t, PriorityWeek, PriorityTail)
}

func keys(m map[string]store.TaskSpec) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}
