# ARCHITECTURE.md

## Inventory

### Entity
- Location — справочник локаций: склады МП, приёмки, транзитные виртуальные локации
- InventorySnapshotSession — логическая группа загрузки snapshot
- InventoryRawSnapshot — сырой ответ API, одна страница ответа
- StockSnapshot — нормализованное состояние остатков на дату

### Enum
- MarketplaceType: используется существующий `App\Marketplace\Enum\MarketplaceType`
- LocationType: mp_warehouse, mp_acceptance, mp_in_transit_to_customer, mp_in_transit_from_customer
- StockStatus: available, in_transit_to_customer, in_transit_from_customer, on_acceptance, defect, blocked
- SnapshotSessionStatus: pending, in_progress, completed, partial, failed
- SnapshotTriggerType: scheduled_night, scheduled_day, manual, retry

### Facade
Пока не реализован. Будет отдельной задачей.

### CompanyId pattern
Все Entity Inventory используют `string $companyId`.

## Inventory — design decisions

### Enum для источника данных
Inventory использует `App\Marketplace\Enum\MarketplaceType` для идентификации
источника snapshot'а (поле `source` в `InventorySnapshotSession`,
`InventoryRawSnapshot`, `StockSnapshot` и поле `externalSystem` в `Location`).
Локальный `ExternalSystemType` удалён (YAGNI). Когда появятся МойСклад/1С/другие
не-маркетплейс источники — будет принято отдельное архитектурное решение.

### UPSERT стратегия для StockSnapshot
При повторной загрузке snapshot'а за ту же дату (например, ночной 04:00 +
дневной 14:00) — записи обновляются через `INSERT ... ON CONFLICT DO UPDATE`.
UNIQUE constraint в БД:
```
(company_id, snapshot_date, listing_id, product_id, location_id, status)
NULLS NOT DISTINCT
```
Важно: `NULLS NOT DISTINCT` не поддерживается атрибутом
`#[ORM\UniqueConstraint]` нативно — индекс создаётся вручную raw SQL в
миграции (`migrations/Version20260428102000.php`). При schema diff/sync
Doctrine может рапортовать расхождение с маппингом — это ожидаемо,
синхронизировать схему по миграции, а не по `doctrine:schema:update`.
Требует PostgreSQL ≥ 15.

При обновлении меняются: `quantity`, `snapshot_at`, `snapshot_session_id`.
Поле `snapshot_session_id` всегда указывает на последнюю сессию, обновившую
запись. История загрузок прослеживается через `InventorySnapshotSession` и
`InventoryRawSnapshot`.

### Идемпотентность cron-загрузки snapshot'ов
Будущая Command для cron snapshot'ов будет использовать advisory lock через
`LockFactory` (паттерн взят из `src/Finance/Command/RecalcPlRegisterCommand.php`).
Ключ блокировки: `inventory_snapshot_{companyId}_{source}`. Это исключит
параллельные запуски для одной компании и одного источника. Реализация —
в задаче Фазы 2.
