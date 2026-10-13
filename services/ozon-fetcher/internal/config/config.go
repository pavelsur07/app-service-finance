// Package config — env-only конфигурация сервиса.
package config

import "os"

type Config struct {
	ListenAddr string
	DBDSN      string
}

func Load() (Config, error) {
	cfg := Config{
		ListenAddr: os.Getenv("LISTEN_ADDR"),
		DBDSN:      os.Getenv("DB_DSN"),
	}
	if cfg.ListenAddr == "" {
		cfg.ListenAddr = ":8080"
	}
	return cfg, nil
}
