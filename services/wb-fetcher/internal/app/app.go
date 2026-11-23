// Package app собирает цепочку загрузки wb-fetcher: кабинеты → планировщик →
// диспетчер → исполнитель (ядро fetchkit, адаптер WB).
package app

import (
	"context"
	"embed"
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
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/facts"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/planner"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/quota"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

//go:embed migrations/*.sql
var migrationsFS embed.FS

// SeparateSlotPrefix — реклама идёт своим слотом кабинета (индекс миграции 00002).
const SeparateSlotPrefix = "wb.ads."

// Migrate — миграция ядра fetchkit/store (00001), затем свои миграции сервиса (00002…):
// у goose одна таблица версий, каталог сервиса начинается после ядра.
func Migrate(ctx context.Context, dsn string) error {
	if err := store.Migrate(ctx, dsn, store.CoreMigrations(), "migrations"); err != nil {
		return err
	}
	return store.Migrate(ctx, dsn, migrationsFS, "migrations")
}

// NewTasks — очередь ядра: одна задача отчёта и одна задача рекламы кабинета одновременно;
// ревизии ресурсов facts.Resources публикуются фактами.
func NewTasks(pool *store.Pool) *store.Tasks {
	return store.NewTasks(pool, store.Options{SeparateSlotPrefix: SeparateSlotPrefix, PublishResources: facts.Resources})
}

// Limits — отчёт finance-api: не чаще запроса в interval на кабинет (лимит WB —
// 1 в минуту на продавца); после 429 темп падает вплоть до вчетверо, серия
// успехов возвращает его — токен на время перехода делят Symfony и сервис.
func Limits(interval time.Duration) ratelimit.Config {
	ceiling := 1 / interval.Seconds()
	limit := ratelimit.EndpointLimit{Start: ceiling, Floor: ceiling / 4, Ceiling: ceiling, DefaultPause: wb.DefaultRateLimitPause}
	// Продвижение: токен общий с легаси-синком рекламы Symfony — темп заведомо ниже лимитов WB.
	every := func(d time.Duration) ratelimit.EndpointLimit {
		c := 1 / d.Seconds()
		return ratelimit.EndpointLimit{Start: c, Floor: c / 4, Ceiling: c, DefaultPause: wb.DefaultRateLimitPause}
	}
	return ratelimit.Config{
		Endpoints: map[string]ratelimit.EndpointLimit{
			wb.EndpointSalesReport:  limit,
			wb.EndpointAdsExpenses:  every(10 * time.Second),
			wb.EndpointAdsFullStats: every(20 * time.Second),
			// Воронка: лимит WB — 3 запроса в минуту на продавца (персональный токен).
			wb.EndpointFunnel: every(20 * time.Second),
			wb.EndpointSearch: every(20 * time.Second),
		},
		Fallback:  limit,
		GrowAfter: 5, IncreaseStep: ceiling,
	}
}

// Ingestion — запущенная цепочка; Wait возвращается после остановки всех частей.
type Ingestion struct {
	Cabinets *connections.Cache
	Tasks    *store.Tasks
	Raw      *rawstore.Store
	RawRead  *rawstore.Reader
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
		WithLimiter(executor.NewSharedLimiter(limits,
			quota.New(cfg.SymfonyBaseURL, cfg.InternalAuthToken, &http.Client{Timeout: quota.DefaultTimeout}), now, logger))
	s3Client := rawstore.NewS3Client(cfg.S3Endpoint, cfg.S3Region, cfg.S3AccessKeyID, cfg.S3SecretAccessKey)
	raw := rawstore.New(s3Client, cfg.S3Bucket, cfg.MaxParallelConnections*2)
	execCfg := executor.DefaultConfig()
	execCfg.Location = loc
	ads := wb.NewAds(cfg.WBAdvertBaseURL, &http.Client{Timeout: wb.DefaultTimeout}, now).WithLimiter(limits)
	analytics := wb.NewAnalytics(cfg.WBAnalyticsBaseURL, &http.Client{Timeout: wb.DefaultTimeout}, now).WithLimiter(limits)
	exec := executor.New(execCfg, tasks, raw, report, cabinets, logger, now, jitter).WithAds(ads).WithFunnel(analytics)

	plannerCfg := planner.DefaultConfig(loc)
	plannerCfg.TailDays = cfg.DailyTailDays
	plannerCfg.HistoryDays = planner.DailyHistoryDays
	scheduler := planner.NewScheduler(plannerCfg, cfg.PlanEvery, tasks, cabinets, logger, now)

	hostname, _ := os.Hostname()
	disp := dispatcher.New[connections.Cabinet](dispatcher.Config{
		MaxParallel:  cfg.MaxParallelConnections,
		Lease:        execCfg.Lease,
		IdlePoll:     5 * time.Second,
		ReleaseEvery: 30 * time.Second,
		Owner:        fmt.Sprintf("%s:%d", hostname, os.Getpid()),
	}, tasks, cabinets, exec, logger, now, nil)

	// Дни, загруженные до публикации фактов, — в outbox; повтор при каждом старте безвреден.
	backfilled, err := tasks.EnqueueLoadedFactBatches(ctx, now())
	if err != nil {
		return nil, err
	}
	logger.Info("fact batches enqueued for loaded revisions", slog.String("stage", "start"), slog.Int64("batches", backfilled))

	ing := &Ingestion{Cabinets: cabinets, Tasks: tasks, Raw: raw, RawRead: rawstore.NewReader(s3Client, cfg.S3Bucket), Location: loc}
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
