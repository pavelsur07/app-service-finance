package config

import "testing"

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
