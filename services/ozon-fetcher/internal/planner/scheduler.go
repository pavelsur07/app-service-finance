package planner

import (
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/schedule"
)

type (
	// TaskStore — постановка задач.
	TaskStore = schedule.TaskStore
	// Cabinets — снимок кабинетов.
	Cabinets = schedule.Cabinets[connections.Cabinet]
	// Scheduler — проход планировщика сразу и раз в every.
	Scheduler = schedule.Scheduler[connections.Cabinet]
)

// NotReadyRetry — повтор прохода, пока нет снимка кабинетов.
const NotReadyRetry = schedule.NotReadyRetry

// NewScheduler — планировщик Ozon: план Build по cfg.
func NewScheduler(cfg Config, every time.Duration, tasks TaskStore, cabinets Cabinets, logger *slog.Logger, now func() time.Time) *Scheduler {
	return schedule.NewScheduler(func(now time.Time, cabinets []connections.Cabinet) Plan { return Build(now, cabinets, cfg) },
		every, tasks, cabinets, logger, now)
}
