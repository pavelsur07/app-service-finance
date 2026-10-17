package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/config"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

func main() {
	if err := run(); err != nil {
		slog.Error("ozon-fetcher stopped with error", "error", err)
		os.Exit(1)
	}
}

func run() error {
	migrate := flag.Bool("migrate", false, "apply database migrations and exit")
	flag.Parse()

	cfg, err := config.Load()
	if err != nil {
		return err
	}

	if *migrate {
		if cfg.DBDSN == "" {
			return errors.New("migrate: DB_DSN is required")
		}
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		return store.Migrate(ctx, cfg.DBDSN)
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

	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("GET /ready", func(w http.ResponseWriter, r *http.Request) {
		if pool == nil {
			writeJSON(w, http.StatusOK, map[string]string{"status": "ready", "db": "disabled"})
			return
		}
		pingCtx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
		defer cancel()
		if err := pool.Ping(pingCtx); err != nil {
			writeJSON(w, http.StatusServiceUnavailable, map[string]string{"status": "not ready", "db": "down"})
			return
		}
		writeJSON(w, http.StatusOK, map[string]string{"status": "ready", "db": "up"})
	})

	server := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}

	errCh := make(chan error, 1)
	go func() {
		slog.Info("ozon-fetcher listening", "addr", cfg.ListenAddr)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
		}
	}()

	select {
	case <-ctx.Done():
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		return server.Shutdown(shutdownCtx)
	case err := <-errCh:
		return err
	}
}

func writeJSON(w http.ResponseWriter, code int, payload map[string]string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(payload)
}
