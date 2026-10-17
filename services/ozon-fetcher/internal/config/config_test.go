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

func TestLoadS3Settings(t *testing.T) {
	full := map[string]string{
		"S3_ENDPOINT": "https://s3.twcstorage.ru", "S3_REGION": "ru-1", "S3_BUCKET": "vashfindir-services-raw",
		"S3_ACCESS_KEY_ID": "key-id", "S3_SECRET_ACCESS_KEY": "secret-value",
	}
	tests := []struct {
		name    string
		drop    string
		empty   bool
		wantErr bool
	}{
		{name: "disabled", empty: true},
		{name: "complete"},
		{name: "no endpoint", drop: "S3_ENDPOINT", wantErr: true},
		{name: "no region", drop: "S3_REGION", wantErr: true},
		{name: "no secret", drop: "S3_SECRET_ACCESS_KEY", wantErr: true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			for k, v := range full {
				if tt.empty || k == tt.drop {
					v = ""
				}
				t.Setenv(k, v)
			}
			t.Setenv("SYMFONY_INTERNAL_BASE_URL", "")
			t.Setenv("CONNECTIONS_POLL_INTERVAL", "")
			cfg, err := Load()
			if tt.wantErr {
				if err == nil || strings.Contains(err.Error(), "secret-value") {
					t.Fatalf("want error without secret, got %v", err)
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if !tt.empty && cfg.S3Bucket != "vashfindir-services-raw" {
				t.Errorf("bucket = %q", cfg.S3Bucket)
			}
		})
	}
}
