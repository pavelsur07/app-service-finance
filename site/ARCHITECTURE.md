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

При обновлении меняются: `quantity`, `snapshot_at`, `snapshot_session_id`,
`raw_snapshot_id`. Все четыре поля синхронизированы — `raw_snapshot_id` всегда
указывает на `InventoryRawSnapshot`, из которого получены текущие значения.
Это устраняет рассинхрон «`quantity` обновился, FK на raw указывает на
устаревший payload». Для трассировки полной истории загрузок позиции —
`InventoryRawSnapshot` ORDER BY `created_at` с фильтром по `company_id` +
`listing_id` + `product_id` + `location_id` + `status`.

### Идемпотентность cron-загрузки snapshot'ов
Будущая Command для cron snapshot'ов будет использовать advisory lock через
`LockFactory` (паттерн взят из `src/Finance/Command/RecalcPlRegisterCommand.php`).
Ключ блокировки: `inventory_snapshot_{companyId}_{source}`. Это исключит
параллельные запуски для одной компании и одного источника. Реализация —
в задаче Фазы 2.

### Timezone-нормализация snapshotDate
`StockSnapshot.snapshotDate` приводится к UTC midnight в конструкторе. Берётся
календарная дата из таймзоны входного `\DateTimeImmutable` (через
`format('Y-m-d')`) и из неё строится UTC midnight через
`\DateTimeImmutable::createFromFormat('!Y-m-d', ...)`. Это исключает сдвиг
даты при конвертации non-UTC `\DateTimeImmutable` в PostgreSQL `DATE`.
Семантика: «snapshot за такой-то календарный день в TZ вызывающего кода».
Вызывающий может передавать дату в любой таймзоне — нормализация происходит
внутри Entity.

### Терминальные статусы InventorySnapshotSession
Статусы `Completed`, `Partial`, `Failed` считаются терминальными и
неизменяемыми. Методы `markCompleted` / `markPartial` / `markFailed`,
а также `setExpectedPages` / `setReceivedPages` / `incrementReceivedPages`
выбрасывают `\LogicException` при попытке вызова на сессии в терминальном
статусе. Это защищает аудит-лог от перезаписи. `markInProgress` остаётся
с прежним guard (`\DomainException`, разрешён только переход
`Pending → InProgress`).

### Инвариант quantity для StockSnapshot
`StockSnapshot.quantity` не может быть отрицательным (snapshot — это снимок
физических остатков). Валидация в конструкторе через `bccomp` со scale=3
(соответствует `NUMERIC(14, 3)`). Если в будущем появятся корректировки или
движения с отрицательными дельтами — это будет отдельная сущность
(`StockMovement`), а не `StockSnapshot`.
