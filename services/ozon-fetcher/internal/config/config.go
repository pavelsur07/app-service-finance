// Package config — env-only конфигурация сервиса, проверка при старте (fail fast).
package config

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"time"
)

// MinInternalTokenLength — как InternalAccessPolicy::MIN_TOKEN_LENGTH в Symfony.
const MinInternalTokenLength = 32

const defaultPollInterval = 5 * time.Minute

type Config struct {
	ListenAddr string
	DBDSN      string

	// SymfonyBaseURL пуст — получение кабинетов выключено (режим scaffold).
	SymfonyBaseURL          string
	InternalAuthToken       string
	ConnectionsPollInterval time.Duration
}

func Load() (Config, error) {
	cfg := Config{
		ListenAddr:              os.Getenv("LISTEN_ADDR"),
		DBDSN:                   os.Getenv("DB_DSN"),
		SymfonyBaseURL:          os.Getenv("SYMFONY_INTERNAL_BASE_URL"),
		InternalAuthToken:       os.Getenv("INTERNAL_AUTH_TOKEN"),
		ConnectionsPollInterval: defaultPollInterval,
	}
	if cfg.ListenAddr == "" {
		cfg.ListenAddr = ":8080"
	}

	if raw := os.Getenv("CONNECTIONS_POLL_INTERVAL"); raw != "" {
		d, err := time.ParseDuration(raw)
		if err != nil || d <= 0 {
			return Config{}, fmt.Errorf("config: CONNECTIONS_POLL_INTERVAL must be a positive duration, got %q", raw)
		}
		cfg.ConnectionsPollInterval = d
	}

	if cfg.SymfonyBaseURL != "" {
		u, err := url.Parse(cfg.SymfonyBaseURL)
		if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
			return Config{}, fmt.Errorf("config: SYMFONY_INTERNAL_BASE_URL must be an http(s) URL")
		}
		if len(cfg.InternalAuthToken) < MinInternalTokenLength {
			return Config{}, errors.New("config: INTERNAL_AUTH_TOKEN is required (at least 32 characters) with SYMFONY_INTERNAL_BASE_URL")
		}
	}
	return cfg, nil
}
