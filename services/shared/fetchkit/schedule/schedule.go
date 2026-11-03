// Package schedule — проходы планировщика: план задач сервиса (Builder) →
// идемпотентная постановка, перезагрузки — раз за дату на кабинет.
package schedule

import (
	"context"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

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

// Builder — план сервиса на момент now для кабинетов снимка.
type Builder[C any] func(now time.Time, cabinets []C) Plan

// TaskStore — постановка задач.
type TaskStore interface {
	Upsert(ctx context.Context, specs []store.TaskSpec) error
	PlanOnce(ctx context.Context, kind, runDate, connectionRef string, specs []store.TaskSpec) (bool, error)
}

// Cabinets — снимок кабинетов.
type Cabinets[C any] interface {
	Ready() bool
	List() []C
}

// Scheduler — проход планировщика сразу и раз в every.
type Scheduler[C any] struct {
	build    Builder[C]
	every    time.Duration
	tasks    TaskStore
	cabinets Cabinets[C]
	logger   *slog.Logger
	now      func() time.Time
}

func NewScheduler[C any](build Builder[C], every time.Duration, tasks TaskStore, cabinets Cabinets[C], logger *slog.Logger, now func() time.Time) *Scheduler[C] {
	return &Scheduler[C]{build: build, every: every, tasks: tasks, cabinets: cabinets, logger: logger, now: now}
}

// NotReadyRetry — повтор прохода, пока нет снимка кабинетов: на старте
// снимок приходит через секунды, ждать полный интервал нельзя.
const NotReadyRetry = 5 * time.Second

func (s *Scheduler[C]) Run(ctx context.Context) {
	for {
		wait := s.every
		if !s.Tick(ctx) {
			wait = min(NotReadyRetry, s.every)
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
	}
}

// Tick — один проход; false — снимка кабинетов ещё нет, ничего не поставлено.
func (s *Scheduler[C]) Tick(ctx context.Context) bool {
	if !s.cabinets.Ready() {
		return false
	}
	started := s.now()
	plan := s.build(started, s.cabinets.List())

	if err := s.tasks.Upsert(ctx, plan.Ensure); err != nil {
		if ctx.Err() == nil {
			s.logger.Warn("planner: ensure failed", slog.String("stage", "plan"), slog.String("error", err.Error()))
		}
		return true
	}
	var refreshed []string
	for _, r := range plan.Refreshes {
		done, err := s.tasks.PlanOnce(ctx, r.Kind, r.RunDate, r.ConnectionRef, r.Specs)
		if err != nil {
			if ctx.Err() == nil {
				s.logger.Warn("planner: refresh failed", slog.String("stage", "plan"), slog.String("kind", r.Kind), slog.String("error", err.Error()))
			}
			continue
		}
		if done {
			refreshed = append(refreshed, r.Kind+"/"+r.ConnectionRef)
		}
	}
	s.logger.Info("planner pass",
		slog.String("stage", "plan"), slog.String("outcome", "ok"),
		slog.Int("ensure_tasks", len(plan.Ensure)), slog.Any("refreshed", refreshed),
		slog.Int64("duration_ms", s.now().Sub(started).Milliseconds()))
	return true
}
