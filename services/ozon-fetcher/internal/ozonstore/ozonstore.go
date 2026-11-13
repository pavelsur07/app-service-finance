// Package ozonstore — БД ozon-fetcher поверх общей очереди fetchkit/store:
// история миграций сервиса (00001–00008), отдельный слот рекламы и запросы
// к ресурсам Ozon, которых нет в ядре.
package ozonstore

import (
	"context"
	"embed"
	"fmt"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/facts"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

//go:embed migrations/*.sql
var migrationsFS embed.FS

// SeparateSlotPrefix — реклама (ключ Performance, свои лимиты и отчёты до
// 10 мин) идёт своим слотом кабинета: индекс миграции 00006.
const SeparateSlotPrefix = "ozon.ads."

// Migrate накатывает миграции сервиса. Forward-only (см. services/go.md).
func Migrate(ctx context.Context, dsn string) error {
	return store.Migrate(ctx, dsn, migrationsFS, "migrations")
}

// Tasks — очередь ядра плюс запросы Ozon.
type Tasks struct {
	*store.Tasks
}

// NewTasks — очередь с отдельным слотом рекламы; ревизии ресурсов facts.Resources
// публикуются фактами.
func NewTasks(pool *store.Pool) *Tasks {
	return &Tasks{Tasks: store.NewTasks(pool, store.Options{SeparateSlotPrefix: SeparateSlotPrefix, PublishResources: facts.Resources})}
}

// AdsReportInFlight — у кабинета есть другая незавершённая задача отчётов
// рекламы с заказанным отчётом (третье поле курсора «отпечаток:порция:UUID:…»):
// у аккаунта Performance — не больше одного отчёта одновременно.
func (t *Tasks) AdsReportInFlight(ctx context.Context, except store.TaskKey) (bool, error) {
	var found bool
	err := t.Pool().QueryRow(ctx, `
		SELECT EXISTS (SELECT 1 FROM fetch_task
			WHERE connection_ref = $1 AND resource_type LIKE 'ozon.ads.statistics%'
			  AND NOT (resource_type = $2 AND period_key = $3)
			  AND status <> 'done' AND split_part(COALESCE(cursor, ''), ':', 3) <> '')`,
		except.ConnectionRef, except.ResourceType, except.PeriodKey).Scan(&found)
	if err != nil {
		return false, fmt.Errorf("store: ads report in flight: %w", err)
	}
	return found, nil
}
