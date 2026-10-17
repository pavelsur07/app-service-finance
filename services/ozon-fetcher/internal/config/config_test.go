package config

import (
	"strings"
	"testing"
	"time"
)

func TestLoadDefaults(t *testing.T) {
	t.Setenv("LISTEN_ADDR", "")
	t.Setenv("DB_DSN", "")

	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ListenAddr != ":8080" {
		t.Errorf("ListenAddr = %q, want :8080", cfg.ListenAddr)
	}
	if cfg.DBDSN != "" {
		t.Errorf("DBDSN = %q, want empty", cfg.DBDSN)
	}
}

func TestLoadFromEnv(t *testing.T) {
	t.Setenv("LISTEN_ADDR", ":9999")
	t.Setenv("DB_DSN", "postgres://svc:secret@db:5432/ozon_fetcher")

	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ListenAddr != ":9999" {
		t.Errorf("ListenAddr = %q, want :9999", cfg.ListenAddr)
	}
	if cfg.DBDSN == "" {
		t.Error("DBDSN is empty, want value from env")
	}
}

func TestLoadConnectionsSettings(t *testing.T) {
	tests := []struct {
		name     string
		baseURL  string
		token    string
		interval string
		wantErr  bool
	}{
		{name: "disabled without base url", baseURL: "", token: ""},
		{name: "enabled", baseURL: "http://site-nginx", token: "0123456789abcdef0123456789abcdef", interval: "30s"},
		{name: "short token", baseURL: "http://site-nginx", token: "short", wantErr: true},
		{name: "no token", baseURL: "http://site-nginx", wantErr: true},
		{name: "not a url", baseURL: "site-nginx", token: "0123456789abcdef0123456789abcdef", wantErr: true},
		{name: "bad interval", interval: "soon", wantErr: true},
		{name: "zero interval", interval: "0s", wantErr: true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Setenv("SYMFONY_INTERNAL_BASE_URL", tt.baseURL)
			t.Setenv("INTERNAL_AUTH_TOKEN", tt.token)
			t.Setenv("CONNECTIONS_POLL_INTERVAL", tt.interval)

			cfg, err := Load()
			if tt.wantErr {
				if err == nil {
					t.Fatal("want error")
				}
				if tt.token != "" && strings.Contains(err.Error(), tt.token) {
					t.Fatal("token leaked into error")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if tt.interval == "" && cfg.ConnectionsPollInterval != 5*time.Minute {
				t.Errorf("default interval = %v", cfg.ConnectionsPollInterval)
			}
		})
	}
}
