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
	KindTail            = "tail"
	KindTypes           = "accrual_types"
	KindReconcile       = "reconcile_m1"
	KindFinal           = "final_m2"
	KindOrders          = "orders_tail"
	KindAccrualPostings = "accrual_postings_tail"
)

// DefaultAccrualPostingsDays — сколько дней перед сегодня раз в сутки
// перечитываются начисления по отправлениям: они копятся неделями
// (доставка, возвраты).
const DefaultAccrualPostingsDays = 45

// DefaultOrdersFrom — первый день загрузки отправлений: день перекрытия с
// последней успешной загрузкой Symfony (problem.md §1, 19.10 09:35 МСК).
const DefaultOrdersFrom = "2026-10-18"

// DefaultOrdersTailDays — сколько дней перед сегодня перечитываются раз в
// сутки: так видны смены статусов (доставка, отмена) — фильтр по дате смены
// статуса Ozon игнорирует.
const DefaultOrdersTailDays = 30

// ordersResources — списки отправлений (executor.postingResources).
var ordersResources = []string{executor.ResourceOrdersFBO, executor.ResourceOrdersFBS}

// Config — параметры окон загрузки.
type Config struct {
	Location         *time.Location // Europe/Moscow
	TailDays         int            // вчера + TailDays предыдущих
	ReconcileWeekday time.Weekday
	FinalDay         int       // число месяца финальной перезагрузки M-2
	OrdersFrom       time.Time // первый день отправлений (полночь МСК)
	OrdersTailDays   int       // дней перед сегодня, перечитываемых раз в сутки (смены статусов)
	// AccrualPostingsDays — дней перед сегодня, в которых раз в сутки
	// перечитываются начисления по отправлениям.
	AccrualPostingsDays int
}

// DefaultConfig — значения плана.
func DefaultConfig(loc *time.Location) Config {
	from, _ := time.ParseInLocation(time.DateOnly, DefaultOrdersFrom, loc)
	return Config{Location: loc, TailDays: 3, ReconcileWeekday: time.Monday, FinalDay: 10, OrdersFrom: from, OrdersTailDays: DefaultOrdersTailDays,
		AccrualPostingsDays: DefaultAccrualPostingsDays}
}

// Refresh — проход с перезагрузкой кабинета, выполняемый один раз за RunDate.
type Refresh struct {
	Kind          string
	RunDate       string // YYYY-MM-DD (МСК)
	ConnectionRef string
	Specs         []store.TaskSpec
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
	for _, cab := range cabinets {
		if cab.Seller == nil {
			continue
		}
		tail := Refresh{Kind: KindTail, RunDate: runDate, ConnectionRef: cab.Ref}
		types := Refresh{Kind: KindTypes, RunDate: runDate, ConnectionRef: cab.Ref}
		reconcile := Refresh{Kind: KindReconcile, RunDate: runDate, ConnectionRef: cab.Ref}
		final := Refresh{Kind: KindFinal, RunDate: runDate, ConnectionRef: cab.Ref}

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
			// ensure — новый кабинет получает справочник сразу; refresh — раз в сутки.
			plan.Ensure = append(plan.Ensure, spec(cab.Ref, executor.ResourceAccrualTypes, executor.PeriodRef, PriorityRoutine, false, now))
			types.Specs = append(types.Specs, spec(cab.Ref, executor.ResourceAccrualTypes, executor.PeriodRef, PriorityRoutine, true, now))
		}
		orders := Refresh{Kind: KindOrders, RunDate: runDate, ConnectionRef: cab.Ref}
		// Отправления: догон с OrdersFrom (не глубже M-2), сегодня — каждый
		// проход, хвост — раз в сутки.
		ordersFrom := cfg.OrdersFrom.In(cfg.Location)
		ordersFrom = time.Date(ordersFrom.Year(), ordersFrom.Month(), ordersFrom.Day(), 0, 0, 0, 0, cfg.Location)
		if ordersFrom.Before(m2Start) {
			ordersFrom = m2Start
		}
		for _, resource := range ordersResources {
			if !cab.Supports(resource) {
				continue
			}
			for d := ordersFrom; d.Before(today); d = d.AddDate(0, 0, 1) {
				plan.Ensure = append(plan.Ensure, spec(cab.Ref, resource, d.Format(time.DateOnly), PriorityBackfill, false, now))
			}
			if !today.Before(ordersFrom) {
				// Обычный приоритет: сегодняшние заказы не опережают финансовые
				// отчёты в общей очереди кабинета.
				plan.Ensure = append(plan.Ensure, spec(cab.Ref, resource, today.Format(time.DateOnly), PriorityRoutine, true, now))
			}
			// Вчера перечитывается всегда: последний проход дня не видит
			// отправлений до полуночи.
			for i := 1; i <= max(cfg.OrdersTailDays, 1); i++ {
				d := today.AddDate(0, 0, -i)
				if d.Before(ordersFrom) {
					break
				}
				orders.Specs = append(orders.Specs, spec(cab.Ref, resource, d.Format(time.DateOnly), PriorityRoutine, true, now))
			}
		}
		accrualPostings := Refresh{Kind: KindAccrualPostings, RunDate: runDate, ConnectionRef: cab.Ref}
		if cab.Supports(executor.ResourceAccrualPostings) {
			// Дни отправлений до вчера: у сегодняшних начислений почти нет.
			for d := ordersFrom; d.Before(today); d = d.AddDate(0, 0, 1) {
				plan.Ensure = append(plan.Ensure, spec(cab.Ref, executor.ResourceAccrualPostings, d.Format(time.DateOnly), PriorityBackfill, false, now))
			}
			for i := 1; i <= cfg.AccrualPostingsDays; i++ {
				d := today.AddDate(0, 0, -i)
				if d.Before(ordersFrom) {
					break
				}
				accrualPostings.Specs = append(accrualPostings.Specs, spec(cab.Ref, executor.ResourceAccrualPostings, d.Format(time.DateOnly), PriorityRoutine, true, now))
			}
		}
		// Каталог — снимок дня: только сегодня, без перечитывания (прошлое не
		// загрузить). Подробности — только вместе со списком: id берутся из него.
		if cab.Supports(executor.ResourceProductsList) {
			plan.Ensure = append(plan.Ensure, spec(cab.Ref, executor.ResourceProductsList, today.Format(time.DateOnly), PriorityRoutine, false, now))
			if cab.Supports(executor.ResourceProductsInfo) {
				// Минутой позже списка: иначе подробности могут быть взяты первыми
				// и уйти в not_ready на 10 минут.
				plan.Ensure = append(plan.Ensure, spec(cab.Ref, executor.ResourceProductsInfo, today.Format(time.DateOnly), PriorityRoutine, false, now.Add(time.Minute)))
			}
		}
		for _, r := range []Refresh{tail, types, reconcile, final, orders, accrualPostings} {
			if len(r.Specs) > 0 {
				plan.Refreshes = append(plan.Refreshes, r)
			}
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
