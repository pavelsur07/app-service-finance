// Package app собирает цепочку загрузки: кабинеты → планировщик →
// диспетчер → исполнитель (docs/tasks/ozon-fetcher-ingestion/stages/stage-11.md).
package app

import (
	"context"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"os"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/config"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/dispatcher"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/planner"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ratelimit"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

// Ingestion — запущенная цепочка; Wait возвращается после остановки всех частей.
type Ingestion struct {
	Cabinets *connections.Cache
	Tasks    *store.Tasks
	Raw      *rawstore.Store
	Location *time.Location
	wg       sync.WaitGroup
}

func (i *Ingestion) Wait() { i.wg.Wait() }

// Start поднимает загрузку; остановка — отменой ctx (задачи в работе
// возвращаются в очередь исполнителем).
func Start(ctx context.Context, cfg config.Config, pool *store.Pool, logger *slog.Logger) (*Ingestion, error) {
	loc, err := time.LoadLocation("Europe/Moscow")
	if err != nil {
		return nil, fmt.Errorf("app: load Europe/Moscow: %w", err)
	}
	tasks := store.NewTasks(pool)
	now := time.Now

	cabinets := connections.NewCache(
		connections.NewClient(cfg.SymfonyBaseURL, cfg.InternalAuthToken, &http.Client{Timeout: 30 * time.Second}),
		logger, now).WithAllowlist(cfg.ConnectionAllowlist)

	limits := ratelimit.New(ratelimit.DefaultConfig(), now, ozon.SleepContext, func(ref, endpoint string, rps float64) {
		go func() {
			saveCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
			defer cancel()
			if err := tasks.SaveCurrentRate(saveCtx, ref, endpoint, rps); err != nil {
				logger.Warn("rate limit save failed", slog.String("stage", "ratelimit"), slog.String("connection_ref", ref), slog.String("error", err.Error()))
			}
		}()
	})
	saved, err := tasks.LoadLimits(ctx)
	if err != nil {
		return nil, err
	}
	for _, l := range saved {
		limits.Seed(l.ConnectionRef, l.Endpoint, l.Override, l.Current)
	}

	client := ozon.NewClient(cfg.OzonBaseURL, &http.Client{Timeout: ozon.DefaultTimeout}, logger, now).WithLimiter(limits)
	raw := rawstore.New(rawstore.NewS3Client(cfg.S3Endpoint, cfg.S3Region, cfg.S3AccessKeyID, cfg.S3SecretAccessKey), cfg.S3Bucket, cfg.MaxParallelConnections*2)
	execCfg := executor.DefaultConfig()
	exec := executor.New(execCfg, tasks, raw, client, cabinets, logger, now, jitter, ozon.SleepContext)

	plannerCfg := planner.Config{Location: loc, TailDays: cfg.TailDays, ReconcileWeekday: cfg.ReconcileWeekday, FinalDay: cfg.FinalDay}
	scheduler := planner.NewScheduler(plannerCfg, cfg.PlanEvery, tasks, cabinets, logger, now)

	hostname, _ := os.Hostname()
	disp := dispatcher.New(dispatcher.Config{
		MaxParallel:  cfg.MaxParallelConnections,
		Lease:        execCfg.Lease,
		IdlePoll:     5 * time.Second,
		ReleaseEvery: 30 * time.Second,
		Owner:        fmt.Sprintf("%s:%d", hostname, os.Getpid()),
	}, tasks, cabinets, exec, logger, now, nil)

	ing := &Ingestion{Cabinets: cabinets, Tasks: tasks, Raw: raw, Location: loc}
	for _, run := range []func(context.Context){
		func(c context.Context) { cabinets.Run(c, cfg.ConnectionsPollInterval) },
		scheduler.Run,
		disp.Run,
	} {
		ing.wg.Add(1)
		go func() {
			defer ing.wg.Done()
			run(ctx)
		}()
	}
	logger.Info("ingestion started", slog.String("stage", "start"), slog.Int("max_parallel", cfg.MaxParallelConnections),
		slog.Int("allowlist", len(cfg.ConnectionAllowlist)))
	return ing, nil
}

func jitter(d time.Duration) time.Duration {
	if d <= 0 {
		return 0
	}
	return rand.N(d) //nolint:gosec // G404: разброс повторов, не криптография
}
