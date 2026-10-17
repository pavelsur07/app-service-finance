// Package planner ставит задачи загрузки под режимы плана
// (docs/tasks/ozon-fetcher-ingestion/stages/stage-11.md): только upsert в
// fetch_task, без собственной загрузки.
package planner

import (
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

// Приоритеты задач.
const (
	PriorityCurrentMonth = 10
	PriorityRoutine      = 5
	PriorityBackfill     = 1
)

// Виды проходов с перезагрузкой — не чаще одного раза за свою дату.
const (
	KindTail      = "tail"
	KindTypes     = "accrual_types"
	KindReconcile = "reconcile_m1"
	KindFinal     = "final_m2"
)

// Config — параметры окон загрузки.
type Config struct {
	Location         *time.Location // Europe/Moscow
	TailDays         int            // вчера + TailDays предыдущих
	ReconcileWeekday time.Weekday
	FinalDay         int // число месяца финальной перезагрузки M-2
}

// DefaultConfig — значения плана.
func DefaultConfig(loc *time.Location) Config {
	return Config{Location: loc, TailDays: 3, ReconcileWeekday: time.Monday, FinalDay: 10}
}

// Refresh — проход с перезагрузкой, выполняемый один раз за RunDate.
type Refresh struct {
	Kind    string
	RunDate string // YYYY-MM-DD (МСК)
	Specs   []store.TaskSpec
}

// Plan — результат прохода: Ensure ставится всегда (идемпотентно), каждый
// Refresh — один раз за свою дату.
type Plan struct {
	Ensure    []store.TaskSpec
	Refreshes []Refresh
}

// Build — задачи для активных кабинетов на момент now.
func Build(now time.Time, cabinets []connections.Cabinet, cfg Config) Plan {
	local := now.In(cfg.Location)
	today := time.Date(local.Year(), local.Month(), local.Day(), 0, 0, 0, 0, cfg.Location)
	yesterday := today.AddDate(0, 0, -1)
	monthStart := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, cfg.Location)
	m1Start, m2Start := monthStart.AddDate(0, -1, 0), monthStart.AddDate(0, -2, 0)
	runDate := today.Format(time.DateOnly)

	var plan Plan
	tail := Refresh{Kind: KindTail, RunDate: runDate}
	types := Refresh{Kind: KindTypes, RunDate: runDate}
	reconcile := Refresh{Kind: KindReconcile, RunDate: runDate}
	final := Refresh{Kind: KindFinal, RunDate: runDate}

	for _, cab := range cabinets {
		if cab.Seller == nil {
			continue
		}
		if cab.Supports(executor.ResourceAccrualByDay) {
			// Первичная загрузка и подхват пропусков: M — главнее, M-1/M-2 — фоном.
			for d := m2Start; !d.After(yesterday); d = d.AddDate(0, 0, 1) {
				priority := PriorityBackfill
				if !d.Before(monthStart) {
					priority = PriorityCurrentMonth
				}
				plan.Ensure = append(plan.Ensure, spec(cab.Ref, executor.ResourceAccrualByDay, d.Format(time.DateOnly), priority, false, now))
			}
			for i := 0; i <= cfg.TailDays; i++ {
				d := yesterday.AddDate(0, 0, -i)
				tail.Specs = append(tail.Specs, spec(cab.Ref, executor.ResourceAccrualByDay, d.Format(time.DateOnly), PriorityRoutine, true, now))
			}
			if today.Weekday() == cfg.ReconcileWeekday {
				reconcile.Specs = append(reconcile.Specs, days(cab.Ref, m1Start, monthStart.AddDate(0, 0, -1), now)...)
			}
			if today.Day() == cfg.FinalDay {
				final.Specs = append(final.Specs, days(cab.Ref, m2Start, m1Start.AddDate(0, 0, -1), now)...)
			}
		}
		for _, resource := range []string{executor.ResourceRealization, executor.ResourceMutualSettlement} {
			if !cab.Supports(resource) {
				continue
			}
			for _, month := range []time.Time{m1Start, m2Start} {
				plan.Ensure = append(plan.Ensure, spec(cab.Ref, resource, month.Format("2006-01"), PriorityRoutine, false, now))
			}
		}
		if cab.Supports(executor.ResourceAccrualTypes) {
			types.Specs = append(types.Specs, spec(cab.Ref, executor.ResourceAccrualTypes, executor.PeriodRef, PriorityRoutine, true, now))
		}
	}

	for _, r := range []Refresh{tail, types, reconcile, final} {
		if len(r.Specs) > 0 {
			plan.Refreshes = append(plan.Refreshes, r)
		}
	}
	return plan
}

// days — перезагрузка всех дней [from; to] с низким приоритетом.
func days(ref string, from, to, now time.Time) []store.TaskSpec {
	var out []store.TaskSpec
	for d := from; !d.After(to); d = d.AddDate(0, 0, 1) {
		out = append(out, spec(ref, executor.ResourceAccrualByDay, d.Format(time.DateOnly), PriorityBackfill, true, now))
	}
	return out
}

func spec(ref, resource, period string, priority int, refresh bool, now time.Time) store.TaskSpec {
	return store.TaskSpec{
		TaskKey:  store.TaskKey{ConnectionRef: ref, ResourceType: resource, PeriodKey: period},
		Priority: priority,
		Refresh:  refresh,
		RunAt:    now,
	}
}
