// Package config — env-only конфигурация сервиса, проверка при старте (fail fast).
package config

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"strconv"
	"time"
)

// MinInternalTokenLength — как InternalAccessPolicy::MIN_TOKEN_LENGTH в Symfony.
const MinInternalTokenLength = 32

const (
	defaultPollInterval = 5 * time.Minute
	defaultMaxParallel  = 4
	maxMaxParallel      = 64
)

type Config struct {
	ListenAddr string
	DBDSN      string

	// SymfonyBaseURL пуст — получение кабинетов выключено (режим scaffold).
	SymfonyBaseURL          string
	InternalAuthToken       string
	ConnectionsPollInterval time.Duration
	// MaxParallelConnections — сколько кабинетов загружается одновременно.
	MaxParallelConnections int

	// S3Bucket пуст — запись raw выключена (режим scaffold).
	S3Endpoint        string
	S3Region          string
	S3Bucket          string
	S3AccessKeyID     string
	S3SecretAccessKey string
}

func Load() (Config, error) {
	cfg := Config{
		ListenAddr:              os.Getenv("LISTEN_ADDR"),
		DBDSN:                   os.Getenv("DB_DSN"),
		SymfonyBaseURL:          os.Getenv("SYMFONY_INTERNAL_BASE_URL"),
		InternalAuthToken:       os.Getenv("INTERNAL_AUTH_TOKEN"),
		ConnectionsPollInterval: defaultPollInterval,
		MaxParallelConnections:  defaultMaxParallel,
		S3Endpoint:              os.Getenv("S3_ENDPOINT"),
		S3Region:                os.Getenv("S3_REGION"),
		S3Bucket:                os.Getenv("S3_BUCKET"),
		S3AccessKeyID:           os.Getenv("S3_ACCESS_KEY_ID"),
		S3SecretAccessKey:       os.Getenv("S3_SECRET_ACCESS_KEY"),
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

	if raw := os.Getenv("MAX_PARALLEL_CONNECTIONS"); raw != "" {
		n, err := strconv.Atoi(raw)
		if err != nil || n < 1 || n > maxMaxParallel {
			return Config{}, fmt.Errorf("config: MAX_PARALLEL_CONNECTIONS must be 1..%d, got %q", maxMaxParallel, raw)
		}
		cfg.MaxParallelConnections = n
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
	if cfg.S3Bucket != "" {
		u, err := url.Parse(cfg.S3Endpoint)
		if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
			return Config{}, errors.New("config: S3_ENDPOINT must be an http(s) URL with S3_BUCKET")
		}
		if cfg.S3Region == "" || cfg.S3AccessKeyID == "" || cfg.S3SecretAccessKey == "" {
			return Config{}, errors.New("config: S3_REGION, S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY are required with S3_BUCKET")
		}
	}
	return cfg, nil
}
