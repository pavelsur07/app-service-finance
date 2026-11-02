package config

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

const tok = "0123456789abcdef0123456789abcdef"

func clean(t *testing.T) {
	for _, k := range []string{"LISTEN_ADDR", "DB_DSN", "SYMFONY_INTERNAL_BASE_URL", "INTERNAL_AUTH_TOKEN", "SERVICE_API_TOKEN",
		"CONNECTIONS_POLL_INTERVAL", "MAX_PARALLEL_CONNECTIONS", "CONNECTION_ALLOWLIST", "WB_FINANCE_BASE_URL",
		"WB_DAILY_TAIL_DAYS", "WB_FINANCE_MIN_INTERVAL", "PLAN_EVERY", "SENTRY_DSN", "SENTRY_RELEASE", "SENTRY_ENVIRONMENT",
		"S3_ENDPOINT", "S3_REGION", "S3_BUCKET", "S3_ACCESS_KEY_ID", "S3_SECRET_ACCESS_KEY"} {
		t.Setenv(k, "")
	}
}

func TestDefaults(t *testing.T) {
	clean(t)
	cfg, err := Load()

	require.NoError(t, err)
	assert.Equal(t, ":8080", cfg.ListenAddr)
	assert.Equal(t, "https://finance-api.wildberries.ru", cfg.WBFinanceBaseURL)
	assert.Equal(t, 14, cfg.DailyTailDays)
	assert.Equal(t, 70*time.Second, cfg.FinanceMinInterval)
	assert.Equal(t, time.Hour, cfg.PlanEvery)
	assert.Equal(t, 5*time.Minute, cfg.ConnectionsPollInterval)
	assert.Equal(t, 2, cfg.MaxParallelConnections)
	assert.False(t, cfg.IngestionEnabled())
}

func TestLoadingConfig(t *testing.T) {
	clean(t)
	t.Setenv("SYMFONY_INTERNAL_BASE_URL", "http://site-nginx")
	t.Setenv("INTERNAL_AUTH_TOKEN", tok)
	t.Setenv("DB_DSN", "postgres://x")
	t.Setenv("S3_ENDPOINT", "https://s3.example")
	t.Setenv("S3_REGION", "ru-1")
	t.Setenv("S3_BUCKET", "vashfindir-services-raw")
	t.Setenv("S3_ACCESS_KEY_ID", "id")
	t.Setenv("S3_SECRET_ACCESS_KEY", "secret")
	t.Setenv("CONNECTION_ALLOWLIST", " a, ,b ")
	t.Setenv("WB_DAILY_TAIL_DAYS", "30")
	t.Setenv("WB_FINANCE_MIN_INTERVAL", "61s")

	cfg, err := Load()

	require.NoError(t, err)
	assert.True(t, cfg.IngestionEnabled())
	assert.Equal(t, []string{"a", "b"}, cfg.ConnectionAllowlist)
	assert.Equal(t, 30, cfg.DailyTailDays)
	assert.Equal(t, 61*time.Second, cfg.FinanceMinInterval)
}

func TestInvalidValuesFailFast(t *testing.T) {
	for name, env := range map[string][2]string{
		"tail 0":             {"WB_DAILY_TAIL_DAYS", "0"},
		"tail 31":            {"WB_DAILY_TAIL_DAYS", "31"},
		"interval too short": {"WB_FINANCE_MIN_INTERVAL", "59s"},
		"interval garbage":   {"WB_FINANCE_MIN_INTERVAL", "soon"},
		"plan every":         {"PLAN_EVERY", "0s"},
		"base url":           {"WB_FINANCE_BASE_URL", "ftp://x"},
		"parallel":           {"MAX_PARALLEL_CONNECTIONS", "0"},
		"service token":      {"SERVICE_API_TOKEN", "short"},
		"symfony w/o token":  {"SYMFONY_INTERNAL_BASE_URL", "http://site-nginx"},
	} {
		t.Run(name, func(t *testing.T) {
			clean(t)
			t.Setenv(env[0], env[1])
			_, err := Load()
			assert.Error(t, err)
		})
	}
}

func TestLoadingNeedsDBAndS3(t *testing.T) {
	clean(t)
	t.Setenv("SYMFONY_INTERNAL_BASE_URL", "http://site-nginx")
	t.Setenv("INTERNAL_AUTH_TOKEN", tok)

	_, err := Load()

	assert.ErrorContains(t, err, "DB_DSN and S3_BUCKET")
}
