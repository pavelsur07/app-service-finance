// Package config — env wb-fetcher (README «Переменные окружения»), fail fast.
package config

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

// MinInternalTokenLength — минимальная длина токенов внутреннего API.
const MinInternalTokenLength = 32

const (
	defaultPollInterval = 5 * time.Minute
	defaultMaxParallel  = 2
	maxMaxParallel      = 64
	// minFinanceInterval — лимит finance-api: 1 запрос в минуту на продавца.
	minFinanceInterval = time.Minute
)

// Config — параметры сервиса.
type Config struct {
	ListenAddr string
	DBDSN      string

	SymfonyBaseURL          string
	InternalAuthToken       string
	ServiceAPIToken         string
	ConnectionsPollInterval time.Duration
	MaxParallelConnections  int
	ConnectionAllowlist     []string // connection_ref или company_id; пусто — все

	WBFinanceBaseURL   string
	DailyTailDays      int           // дней перед вчера в окне дневного отчёта
	FinanceMinInterval time.Duration // не чаще одного запроса отчёта на кабинет
	PlanEvery          time.Duration

	SentryDSN         string
	SentryRelease     string
	SentryEnvironment string

	S3Endpoint        string
	S3Region          string
	S3Bucket          string
	S3AccessKeyID     string
	S3SecretAccessKey string
}

// Load читает и проверяет env.
func Load() (Config, error) {
	cfg := Config{
		ListenAddr:              envOr("LISTEN_ADDR", ":8080"),
		DBDSN:                   os.Getenv("DB_DSN"),
		SymfonyBaseURL:          os.Getenv("SYMFONY_INTERNAL_BASE_URL"),
		InternalAuthToken:       os.Getenv("INTERNAL_AUTH_TOKEN"),
		ServiceAPIToken:         os.Getenv("SERVICE_API_TOKEN"),
		ConnectionsPollInterval: defaultPollInterval,
		MaxParallelConnections:  defaultMaxParallel,
		WBFinanceBaseURL:        envOr("WB_FINANCE_BASE_URL", "https://finance-api.wildberries.ru"),
		DailyTailDays:           14,
		FinanceMinInterval:      70 * time.Second,
		PlanEvery:               time.Hour,
		SentryDSN:               os.Getenv("SENTRY_DSN"),
		SentryRelease:           os.Getenv("SENTRY_RELEASE"),
		SentryEnvironment:       envOr("SENTRY_ENVIRONMENT", "prod"),
		S3Endpoint:              os.Getenv("S3_ENDPOINT"),
		S3Region:                os.Getenv("S3_REGION"),
		S3Bucket:                os.Getenv("S3_BUCKET"),
		S3AccessKeyID:           os.Getenv("S3_ACCESS_KEY_ID"),
		S3SecretAccessKey:       os.Getenv("S3_SECRET_ACCESS_KEY"),
	}

	for _, d := range []struct {
		name string
		min  time.Duration
		dst  *time.Duration
	}{
		{"CONNECTIONS_POLL_INTERVAL", time.Nanosecond, &cfg.ConnectionsPollInterval},
		{"PLAN_EVERY", time.Minute, &cfg.PlanEvery},
		{"WB_FINANCE_MIN_INTERVAL", minFinanceInterval, &cfg.FinanceMinInterval},
	} {
		if raw := os.Getenv(d.name); raw != "" {
			v, err := time.ParseDuration(raw)
			if err != nil || v < d.min {
				return Config{}, fmt.Errorf("config: %s must be a duration ≥ %s, got %q", d.name, d.min, raw)
			}
			*d.dst = v
		}
	}
	for _, n := range []struct {
		name     string
		min, max int
		dst      *int
	}{
		{"MAX_PARALLEL_CONNECTIONS", 1, maxMaxParallel, &cfg.MaxParallelConnections},
		{"WB_DAILY_TAIL_DAYS", 1, 30, &cfg.DailyTailDays},
	} {
		if raw := os.Getenv(n.name); raw != "" {
			v, err := strconv.Atoi(raw)
			if err != nil || v < n.min || v > n.max {
				return Config{}, fmt.Errorf("config: %s must be %d..%d, got %q", n.name, n.min, n.max, raw)
			}
			*n.dst = v
		}
	}
	for _, id := range strings.Split(os.Getenv("CONNECTION_ALLOWLIST"), ",") {
		if id = strings.TrimSpace(id); id != "" {
			cfg.ConnectionAllowlist = append(cfg.ConnectionAllowlist, id)
		}
	}

	if !httpURL(cfg.WBFinanceBaseURL) {
		return Config{}, errors.New("config: WB_FINANCE_BASE_URL must be an http(s) URL")
	}
	if cfg.SymfonyBaseURL != "" {
		if !httpURL(cfg.SymfonyBaseURL) {
			return Config{}, errors.New("config: SYMFONY_INTERNAL_BASE_URL must be an http(s) URL")
		}
		if len(cfg.InternalAuthToken) < MinInternalTokenLength {
			return Config{}, errors.New("config: INTERNAL_AUTH_TOKEN is required (at least 32 characters) with SYMFONY_INTERNAL_BASE_URL")
		}
	}
	if cfg.ServiceAPIToken != "" && len(cfg.ServiceAPIToken) < MinInternalTokenLength {
		return Config{}, errors.New("config: SERVICE_API_TOKEN must be at least 32 characters")
	}
	if cfg.S3Bucket != "" {
		if !httpURL(cfg.S3Endpoint) {
			return Config{}, errors.New("config: S3_ENDPOINT must be an http(s) URL with S3_BUCKET")
		}
		if cfg.S3Region == "" || cfg.S3AccessKeyID == "" || cfg.S3SecretAccessKey == "" {
			return Config{}, errors.New("config: S3_REGION, S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY are required with S3_BUCKET")
		}
	}
	if cfg.IngestionEnabled() && (cfg.DBDSN == "" || cfg.S3Bucket == "") {
		return Config{}, errors.New("config: loading needs DB_DSN and S3_BUCKET together with SYMFONY_INTERNAL_BASE_URL")
	}
	return cfg, nil
}

// IngestionEnabled — загрузка включена (есть откуда брать кабинеты).
func (c Config) IngestionEnabled() bool { return c.SymfonyBaseURL != "" }

func httpURL(raw string) bool {
	u, err := url.Parse(raw)
	return err == nil && (u.Scheme == "http" || u.Scheme == "https") && u.Host != ""
}

func envOr(name, def string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return def
}
