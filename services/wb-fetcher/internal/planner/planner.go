// Package planner — план загрузки WB: какие задачи поставить на момент now
// (docs/tasks/wb-fetcher-finance/stages/stage-2.md). Только постановка, без
// загрузки; цикл — fetchkit/schedule.
package planner

import (
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/schedule"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/executor"
)

// Приоритеты: вчерашний день раньше хвоста.
const (
	PriorityYesterday = 10
	PriorityTail      = 5
)

// KindDailyTail — суточное перечитывание вчера и хвоста: WB дописывает строки
// отчёта задним числом (Symfony перечитывает 14 дней каждый час).
const KindDailyTail = "wb_daily_tail"

// DefaultTailDays — дней перед вчера в окне загрузки и перечитывания.
const DefaultTailDays = 14

// DefaultDailyStart — с этого часа МСК грузится отчёт за вчера: ночью WB его
// ещё не опубликовал (Symfony — 03:10), пустой ответ закрыл бы день до завтра.
const DefaultDailyStart = 4 * time.Hour

// Config — параметры окна.
type Config struct {
	Location   *time.Location // Europe/Moscow
	TailDays   int            // дней перед вчера
	DailyStart time.Duration  // смещение от полуночи МСК для вчера и перечитывания
}

func DefaultConfig(loc *time.Location) Config {
	return Config{Location: loc, TailDays: DefaultTailDays, DailyStart: DefaultDailyStart}
}

// Build — задачи для кабинетов на момент now.
func Build(now time.Time, cabinets []connections.Cabinet, cfg Config) schedule.Plan {
	local := now.In(cfg.Location)
	today := time.Date(local.Year(), local.Month(), local.Day(), 0, 0, 0, 0, cfg.Location)
	yesterday := today.AddDate(0, 0, -1)
	runDate := today.Format(time.DateOnly)
	start := today.Add(cfg.DailyStart)
	if now.After(start) {
		start = now
	}

	var plan schedule.Plan
	for _, cab := range cabinets {
		if !cab.Supports(executor.ResourceDaily) {
			continue
		}
		tail := schedule.Refresh{Kind: KindDailyTail, RunDate: runDate, ConnectionRef: cab.Ref}
		for i := 0; i <= cfg.TailDays; i++ {
			day := yesterday.AddDate(0, 0, -i).Format(time.DateOnly)
			ensure := store.TaskSpec{TaskKey: key(cab.Ref, day), Priority: PriorityTail, RunAt: now}
			if i == 0 {
				ensure.Priority, ensure.RunAt = PriorityYesterday, start
			}
			plan.Ensure = append(plan.Ensure, ensure)
			tail.Specs = append(tail.Specs, store.TaskSpec{TaskKey: key(cab.Ref, day), Priority: ensure.Priority, Refresh: true, RunAt: start})
		}
		plan.Refreshes = append(plan.Refreshes, tail)
	}
	return plan
}

func key(ref, day string) store.TaskKey {
	return store.TaskKey{ConnectionRef: ref, ResourceType: executor.ResourceDaily, PeriodKey: day}
}

// NewScheduler — цикл планировщика ядра с планом WB.
func NewScheduler(cfg Config, every time.Duration, tasks schedule.TaskStore, cabinets schedule.Cabinets[connections.Cabinet],
	logger *slog.Logger, now func() time.Time) *schedule.Scheduler[connections.Cabinet] {
	return schedule.NewScheduler(func(at time.Time, list []connections.Cabinet) schedule.Plan { return Build(at, list, cfg) },
		every, tasks, cabinets, logger, now)
}
