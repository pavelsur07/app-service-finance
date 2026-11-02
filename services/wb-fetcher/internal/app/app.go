// Package app собирает цепочку загрузки wb-fetcher: кабинеты → планировщик →
// диспетчер → исполнитель (ядро fetchkit, адаптер WB).
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

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/dispatcher"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/ratelimit"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/config"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/planner"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

// Migrate — история миграций wb-fetcher = миграции ядра fetchkit/store.
func Migrate(ctx context.Context, dsn string) error {
	return store.Migrate(ctx, dsn, store.CoreMigrations(), "migrations")
}

// NewTasks — очередь ядра: одна задача кабинета одновременно, без отдельных слотов.
func NewTasks(pool *store.Pool) *store.Tasks { return store.NewTasks(pool, store.Options{}) }

// Limits — отчёт finance-api: не чаще запроса в interval на кабинет (лимит WB —
// 1 в минуту на продавца); после 429 темп падает вплоть до вчетверо, серия
// успехов возвращает его — токен на время перехода делят Symfony и сервис.
func Limits(interval time.Duration) ratelimit.Config {
	ceiling := 1 / interval.Seconds()
	limit := ratelimit.EndpointLimit{Start: ceiling, Floor: ceiling / 4, Ceiling: ceiling, DefaultPause: wb.DefaultRateLimitPause}
	return ratelimit.Config{
		Endpoints: map[string]ratelimit.EndpointLimit{wb.EndpointSalesReport: limit},
		Fallback:  limit,
		GrowAfter: 5, IncreaseStep: ceiling,
	}
}

// Ingestion — запущенная цепочка; Wait возвращается после остановки всех частей.
type Ingestion struct {
	Cabinets *connections.Cache
	Tasks    *store.Tasks
	Raw      *rawstore.Store
	Location *time.Location
	wg       sync.WaitGroup
}

func (i *Ingestion) Wait() { i.wg.Wait() }

// Start поднимает загрузку; остановка — отменой ctx.
func Start(ctx context.Context, cfg config.Config, pool *store.Pool, logger *slog.Logger) (*Ingestion, error) {
	loc, err := time.LoadLocation("Europe/Moscow")
	if err != nil {
		return nil, fmt.Errorf("app: load Europe/Moscow: %w", err)
	}
	tasks := NewTasks(pool)
	now := time.Now

	cabinets := connections.NewCache(
		connections.NewClient(cfg.SymfonyBaseURL, cfg.InternalAuthToken, &http.Client{Timeout: 30 * time.Second}),
		logger, now).WithAllowlist(cfg.ConnectionAllowlist)

	limits := ratelimit.New(Limits(cfg.FinanceMinInterval), now, sleepContext, nil)
	// Шторм 429: кабинет на минимальном темпе, а WB продолжает отказывать.
	limits.WithStormAlert(ratelimit.DefaultStormAlert(func(s ratelimit.Storm) {
		logger.Error("wb rate limit storm: cabinet at minimum rate and still throttled",
			slog.String("stage", "ratelimit"), slog.String("error_class", "rate_limit_storm"),
			slog.String("connection_ref", s.ConnectionRef), slog.String("endpoint", s.Endpoint),
			slog.Int("rate_limits_at_floor", s.Hits), slog.Int("window_minutes", int(s.Window.Minutes())),
			slog.Float64("rps", s.Floor))
	}))
	// Ручное rps_override кабинета (connection_limit) — как у ozon-fetcher.
	saved, err := tasks.LoadLimits(ctx)
	if err != nil {
		return nil, err
	}
	for _, l := range saved {
		limits.Seed(l.ConnectionRef, l.Endpoint, l.Override, l.Current)
	}

	report := wb.NewClient(cfg.WBFinanceBaseURL, &http.Client{Timeout: wb.DefaultTimeout}, now).
		WithLimiter(executor.NewWindowLimiter(limits, executor.DefaultBlockedMinutes, now))
	s3Client := rawstore.NewS3Client(cfg.S3Endpoint, cfg.S3Region, cfg.S3AccessKeyID, cfg.S3SecretAccessKey)
	raw := rawstore.New(s3Client, cfg.S3Bucket, cfg.MaxParallelConnections*2)
	execCfg := executor.DefaultConfig()
	exec := executor.New(execCfg, tasks, raw, report, cabinets, logger, now, jitter)

	plannerCfg := planner.DefaultConfig(loc)
	plannerCfg.TailDays = cfg.DailyTailDays
	scheduler := planner.NewScheduler(plannerCfg, cfg.PlanEvery, tasks, cabinets, logger, now)

	hostname, _ := os.Hostname()
	disp := dispatcher.New[connections.Cabinet](dispatcher.Config{
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

func sleepContext(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}
