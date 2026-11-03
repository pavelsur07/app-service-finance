// wb-fetcher — загрузка финансовых отчётов Wildberries в raw S3
// (docs/tasks/wb-fetcher-finance/plan.md). Ядро — services/shared/fetchkit.
package main

import (
	"context"
	"errors"
	"flag"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
	_ "time/tzdata" // Europe/Moscow без zoneinfo в рантайм-образе

	fkhttp "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/httpapi"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/observability"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/app"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/config"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/httpapi"
)

const service = httpapi.Service

func main() {
	flush := func() {}
	err := run(func(f func()) { flush = f })
	if err != nil {
		slog.Error("wb-fetcher stopped with error", "error", err)
	}
	flush() // события GlitchTip — до выхода процесса
	if err != nil {
		os.Exit(1)
	}
}

func run(setFlush func(func())) error {
	slog.SetDefault(slog.New(slog.NewJSONHandler(os.Stdout, nil)).With(slog.String("service", service)))
	migrate := flag.Bool("migrate", false, "apply database migrations and exit")
	flag.Parse()

	cfg, err := config.Load()
	if err != nil {
		return err
	}
	logger, flush, sentryErr := observability.Setup(os.Stdout, service, cfg.SentryDSN, cfg.SentryRelease, cfg.SentryEnvironment)
	slog.SetDefault(logger)
	setFlush(flush)
	if sentryErr != nil {
		slog.Error("glitchtip disabled: bad SENTRY_DSN", "error", sentryErr.Error())
	}

	if *migrate {
		if cfg.DBDSN == "" {
			return errors.New("migrate: DB_DSN is required")
		}
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		return app.Migrate(ctx, cfg.DBDSN)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	var pool *store.Pool
	if cfg.DBDSN != "" {
		p, err := store.Open(ctx, cfg.DBDSN, store.PoolSize(cfg.MaxParallelConnections))
		if err != nil {
			return err
		}
		defer p.Close()
		pool = p
		slog.Info("database connected")
	} else {
		slog.Info("database disabled: DB_DSN is empty")
	}

	// Загрузка останавливается первой (задачи возвращаются в очередь), HTTP — после.
	ingestCtx, stopIngest := context.WithCancel(context.Background())
	defer stopIngest()
	var ingestion *app.Ingestion
	if cfg.IngestionEnabled() {
		ingestion, err = app.Start(ingestCtx, cfg, pool, slog.Default())
		if err != nil {
			return err
		}
	} else {
		slog.Info("ingestion disabled: SYMFONY_INTERNAL_BASE_URL is empty")
	}

	deps := fkhttp.Deps{Service: service, Token: cfg.ServiceAPIToken, Now: time.Now, Logger: slog.Default(), Location: time.UTC}
	if pool != nil {
		deps.DBPing = pool.Ping
	}
	if ingestion != nil {
		deps.CabinetsReady = ingestion.Cabinets.Ready
		deps.S3Ping = ingestion.Raw.Ping
		deps.Status = ingestion.Tasks.Status
		deps.Expected = func() fkhttp.Expectation { return httpapi.Expectation(ingestion.Cabinets.List()) }
		deps.Coverage = ingestion.Tasks.Coverage
		deps.Location = ingestion.Location
	}

	server := &http.Server{Addr: cfg.ListenAddr, Handler: fkhttp.New(deps), ReadHeaderTimeout: 5 * time.Second}
	errCh := make(chan error, 1)
	go func() {
		slog.Info("wb-fetcher listening", "addr", cfg.ListenAddr)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
		}
	}()

	select {
	case <-ctx.Done():
		stopIngest()
		if ingestion != nil {
			ingestion.Wait()
		}
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		return server.Shutdown(shutdownCtx)
	case err := <-errCh:
		return err
	}
}
