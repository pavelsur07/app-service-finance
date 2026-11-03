package store

import (
	"context"
	"database/sql"
	"embed"
	"fmt"
	"io/fs"

	"github.com/pressly/goose/v3"

	_ "github.com/jackc/pgx/v5/stdlib"
)

// coreFS — схема ядра одной миграцией для новых сервисов. Сервис со своей
// историей миграций (ozon-fetcher) накатывает свои; совместимость схем
// проверяет его тест.
//
//go:embed migrations/*.sql
var coreFS embed.FS

// CoreMigrations — миграции ядра (каталог "migrations").
func CoreMigrations() fs.FS { return coreFS }

// Migrate накатывает миграции сервисной БД из каталога dir в fsys.
// Forward-only (см. services/go.md).
func Migrate(ctx context.Context, dsn string, fsys fs.FS, dir string) error {
	goose.SetBaseFS(fsys)
	if err := goose.SetDialect("postgres"); err != nil {
		return fmt.Errorf("store: set dialect: %w", err)
	}
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return fmt.Errorf("store: open: %w", err)
	}
	defer func() { _ = db.Close() }()

	if err := goose.UpContext(ctx, db, dir); err != nil {
		return fmt.Errorf("store: migrate: %w", err)
	}
	return nil
}
