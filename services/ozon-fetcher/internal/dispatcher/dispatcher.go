// Package dispatcher — параллельная загрузка кабинетов: до N задач
// одновременно, по одной на кабинет (гарантия БД, store.ClaimNext).
package dispatcher

import (
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"log/slog"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

// Claimer — часть store.Tasks для диспетчера.
type Claimer interface {
	ClaimNext(ctx context.Context, connectionRefs []string, owner, attemptID, traceID string, now time.Time, lease time.Duration) (store.Task, error)
	ReleaseExpired(ctx context.Context, now time.Time) (int64, error)
}

// Cabinets — снимок кабинетов (connections.Cache).
type Cabinets interface {
	Ready() bool
	List() []connections.Cabinet
	Get(ref string) (connections.Cabinet, bool)
}

// Runner — исполнитель одной задачи (executor.Executor).
type Runner interface {
	Run(ctx context.Context, task store.Task, cabinet connections.Cabinet, traceID string) executor.Result
}

// Config — параметры диспетчера.
type Config struct {
	MaxParallel  int
	Lease        time.Duration // как у исполнителя
	IdlePoll     time.Duration // пауза, когда задач нет
	ReleaseEvery time.Duration
	Owner        string // hostname:pid
}

type Dispatcher struct {
	cfg      Config
	claimer  Claimer
	cabinets Cabinets
	runner   Runner
	logger   *slog.Logger
	now      func() time.Time
	newID    func() string
}

func New(cfg Config, claimer Claimer, cabinets Cabinets, runner Runner, logger *slog.Logger, now func() time.Time, newID func() string) *Dispatcher {
	if cfg.MaxParallel < 1 {
		cfg.MaxParallel = 1
	}
	if newID == nil {
		newID = NewUUID
	}
	return &Dispatcher{cfg: cfg, claimer: claimer, cabinets: cabinets, runner: runner, logger: logger, now: now, newID: newID}
}

// Run — до отмены ctx; возвращается, когда все воркеры завершились.
func (d *Dispatcher) Run(ctx context.Context) {
	var wg sync.WaitGroup
	slots := make(chan struct{}, d.cfg.MaxParallel)
	freed := make(chan struct{}, 1) // пробуждение при освобождении слота

	wg.Add(1)
	go func() {
		defer wg.Done()
		d.releaseLoop(ctx)
	}()

	defer wg.Wait()
	for {
		select {
		case <-ctx.Done():
			return
		case slots <- struct{}{}:
		}

		task, cabinet, traceID, ok := d.claim(ctx)
		if !ok {
			<-slots
			select {
			case <-ctx.Done():
				return
			case <-freed:
			case <-time.After(d.cfg.IdlePoll):
			}
			continue
		}

		wg.Add(1)
		go func() {
			defer wg.Done()
			defer func() {
				<-slots
				select {
				case freed <- struct{}{}:
				default:
				}
			}()
			d.runner.Run(ctx, task, cabinet, traceID)
		}()
	}
}

// claim — следующая задача; false — задач нет, снимка нет или ошибка БД.
func (d *Dispatcher) claim(ctx context.Context) (store.Task, connections.Cabinet, string, bool) {
	if !d.cabinets.Ready() {
		return store.Task{}, connections.Cabinet{}, "", false
	}
	list := d.cabinets.List()
	refs := make([]string, 0, len(list))
	for _, cab := range list {
		refs = append(refs, cab.Ref)
	}

	traceID := d.newID()
	task, err := d.claimer.ClaimNext(ctx, refs, d.cfg.Owner, d.newID(), traceID, d.now(), d.cfg.Lease)
	switch {
	case errors.Is(err, store.ErrNoTask):
		return store.Task{}, connections.Cabinet{}, "", false
	case err != nil:
		if ctx.Err() == nil {
			d.logger.Warn("dispatcher: claim failed", slog.String("stage", "dispatch"), slog.String("error", err.Error()))
		}
		return store.Task{}, connections.Cabinet{}, "", false
	}
	cabinet, _ := d.cabinets.Get(task.ConnectionRef) // исчез — исполнитель отложит задачу
	return task, cabinet, traceID, true
}

func (d *Dispatcher) releaseLoop(ctx context.Context) {
	ticker := time.NewTicker(d.cfg.ReleaseEvery)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			n, err := d.claimer.ReleaseExpired(ctx, d.now())
			switch {
			case err != nil && ctx.Err() == nil:
				d.logger.Warn("dispatcher: release expired failed", slog.String("stage", "dispatch"), slog.String("error", err.Error()))
			case n > 0:
				d.logger.Warn("dispatcher: expired leases released", slog.String("stage", "dispatch"), slog.Int64("released", n))
			}
		}
	}
}

// NewUUID — UUID v4 из crypto/rand.
func NewUUID() string {
	var b [16]byte
	_, _ = rand.Read(b[:])
	b[6] = b[6]&0x0f | 0x40
	b[8] = b[8]&0x3f | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}
