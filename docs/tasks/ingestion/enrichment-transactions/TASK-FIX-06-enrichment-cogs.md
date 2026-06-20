# TASK-FIX-06 — Обогащение транзакций себестоимостью (COGS)

## 0. Сводка

- **Бизнес-цель.** Дополнить канон Ingestion себестоимостью товаров (COGS) для расчёта реальной прибыли в P&L. При нормализации продажи (`SALE`) автоматически создаётся `EnrichmentTransaction(kind=COGS)` через `MarketplaceFacade::getCostPriceForListing`. Если себестоимость недоступна в момент нормализации — cron повторяет попытку позже. При изменении цены — P&L пересчитывается автоматически через `PLDirtyPeriod`.
- **Модуль.** `App\Ingestion` (новая Entity + Action + cron) + `App\Marketplace` (минимальная правка Action при изменении цены).
- **Тип.** feature.
- **Ветка.** `feature/ingestion-enrichment-cogs`.
- **Подзадачи.** B1 `EnrichmentTransaction` Entity + миграция · B2 `enrichmentStatus` на `FinancialTransaction` · B3 `EnrichCogsBatchAction` · B4 Подписчик на `NormalizationCompletedEvent` · B5 Cron-команда · B6 Пересчёт при изменении цены · B7 `rebuildPeriod` обновление · B8 Тесты.
- **Затрагивает другие модули.** `App\Marketplace` (читает `MarketplaceFacade::getCostPriceForListing`, минимальная правка Action изменения цены), `App\Finance` (расширение `rebuildPeriod`).
- **Требует миграции БД.** Да (новая таблица + ADD COLUMN).
- **Меняет публичный API.** Нет.

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- `FinancialTransaction` в `ingest_financial_transactions` — факты от Ozon/WB.
- `MarketplaceFacade::getCostPriceForListing(companyId, listingId, date): ?string` — уже существует, резолвит через `CostPriceResolverInterface`.
- `FinancialTransaction.listingId` — резолвится при нормализации (TASK-FIX-04). Может быть null.
- `PLDirtyPeriod` + `rebuildPeriod` — уже работает (блок 7).
- `NormalizationCompletedEvent` — публикуется после нормализации (блок 5).
- `ProductPurchasePrice` / `MarketplaceInventoryCostPrice` изменяются через Action в `App\Marketplace`.

### 1.2 Желаемое состояние

- Новая таблица `ingest_enrichment_transactions` — COGS рядом с каноном, не в той же таблице.
- `FinancialTransaction` получает поле `enrichmentStatus` для отслеживания состояния обогащения.
- При нормализации `SALE` → `EnrichCogsBatchAction` создаёт `EnrichmentTransaction(kind=COGS)` если `listingId` есть и цена известна.
- Если нет листинга → `enrichmentStatus=PENDING_LISTING`. Если листинг есть, цены нет → `enrichmentStatus=PENDING_COGS`.
- Cron каждые 30 минут подбирает необогащённые транзакции и повторяет попытку.
- При изменении `ProductPurchasePrice` → `PLDirtyPeriod(reason=REMAP)` → `rebuildPeriod` пересчитывает COGS.
- `rebuildPeriod` суммирует `FinancialTransaction` + `EnrichmentTransaction` за период.

### 1.3 In scope

- Entity `EnrichmentTransaction` + enum `EnrichmentKind` + миграция.
- Поле `enrichmentStatus: EnrichmentTransactionStatus` на `FinancialTransaction` + миграция.
- `EnrichmentTransactionRepository`.
- `EnrichCogsBatchAction` — создаёт/обновляет COGS для набора транзакций.
- `NormalizationCompletedSubscriber` — расширение: после нормализации диспатчит `EnrichCogsMessage`.
- `EnrichCogsMessage` + `EnrichCogsHandler`.
- CLI `app:ingestion:enrich-pending-cogs` — cron-страховка.
- Расширение `RebuildPnlPeriodAction` — читает `EnrichmentTransaction` через `IngestionFacade`.
- Правка Action изменения цены в `App\Marketplace` — добавить `PnlFacade::markPeriodDirty`.
- Расширение `IngestionFacade::getEnrichments`.

### 1.4 Out of scope

- `EnrichmentKind` кроме `COGS` — MANUAL, EXTERNAL, TAX — отдельные задачи.
- UI для просмотра COGS — отдельная задача.
- `App\Catalog` — не трогаем.
- WB себестоимость — после Ozon-пилота.
- Автоматическое создание листинга при `listingId=null` — это TASK-FIX-04.

### 1.5 Допущения

- Допущение: один `SALE` → один `EnrichmentTransaction(kind=COGS)`. Количество единиц в заказе учитывается в `amountMinor` через `getCostPriceForListing` (фасад уже возвращает итоговую сумму).
- Допущение: `getCostPriceForListing` возвращает `?string` (decimal). Конвертация в `amountMinor: int` — умножение на 100, округление.
- Допущение: `enrichmentStatus=SKIPPED` если `getCostPriceForListing` вернул null или '0.00'.
- Допущение: Action изменения цены — `UpdateCostPriceAction` или аналог в `App\Marketplace`. Уточнить имя при старте блока.

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

#### `App\Ingestion\Entity\EnrichmentTransaction`

Файл: `src/Ingestion/Entity/EnrichmentTransaction.php`.
Таблица: `#[ORM\Table(name: 'ingest_enrichment_transactions')]`.
Реализует `TenantOwnedInterface`.

| Поле | Тип PHP | Колонка БД | Nullable | Default | Инвариант |
|---|---|---|---|---|---|
| `id` | string UUID v7 | `id` GUID | нет | — | PK |
| `companyId` | string UUID | `company_id` GUID | нет | — | `Assert::uuid`; неизменяем |
| `transactionId` | string UUID | `transaction_id` GUID | нет | — | ссылка на `FinancialTransaction.id`; неизменяем |
| `kind` | `EnrichmentKind` | `kind` VARCHAR(32) | нет | — | enumType; неизменяем |
| `amountMinor` | int | `amount_minor` BIGINT | нет | — | >= 0 |
| `currency` | string | `currency` CHAR(3) | нет | — | ISO 4217 |
| `occurredAt` | DateTimeImmutable | `occurred_at` TIMESTAMP(6) | нет | — | совпадает с `FinancialTransaction.occurredAt` |
| `sourceRef` | ?string | `source_ref` VARCHAR(255) | да | null | откуда данные (`ProductPurchasePrice.id` и т.д.) |
| `createdAt` | DateTimeImmutable | `created_at` TIMESTAMP(6) | нет | — | |
| `updatedAt` | DateTimeImmutable | `updated_at` TIMESTAMP(6) | нет | — | |

Конструктор: `__construct(string $companyId, string $transactionId, EnrichmentKind $kind, int $amountMinor, string $currency, DateTimeImmutable $occurredAt, ?string $sourceRef = null)`.

Инварианты: `Assert::uuid($companyId)`, `Assert::uuid($transactionId)`, `$amountMinor >= 0`, `Assert::length($currency, 3)`.

Методы:
- `updateAmount(int $amountMinor, ?string $sourceRef = null): void` — обновляет сумму и sourceRef при пересчёте. `updatedAt = now`.
- Геттеры всех полей.

#### `App\Ingestion\Entity\FinancialTransaction` (правка)

Добавить одно поле:

| Поле | Тип PHP | Колонка БД | Nullable | Default | Инвариант |
|---|---|---|---|---|---|
| `enrichmentStatus` | `EnrichmentTransactionStatus` | `enrichment_status` VARCHAR(32) | нет | `not_applicable` | enumType |

Метод: `setEnrichmentStatus(EnrichmentTransactionStatus $status): void` — `updatedAt = now`.

### 2.2 Связи

- `EnrichmentTransaction.transactionId` → `FinancialTransaction.id` (строка, не ManyToOne).
- `EnrichmentTransaction.companyId` — изоляция через `TenantOwnedInterface` + `CompanyFilter`.

### 2.3 Enum

#### `App\Ingestion\Enum\EnrichmentKind`

Backed string.

| Case | value | Когда | Метка |
|---|---|---|---|
| `COGS` | `cogs` | Себестоимость из `CostPriceResolver` | «Себестоимость» |

(Остальные — MANUAL, EXTERNAL, TAX — будущие задачи.)

#### `App\Ingestion\Enum\EnrichmentTransactionStatus`

Backed string.

| Case | value | Когда | Терминальный |
|---|---|---|---|
| `NOT_APPLICABLE` | `not_applicable` | Тип транзакции не SALE | да |
| `PENDING_LISTING` | `pending_listing` | `listingId = null` | нет |
| `PENDING_COGS` | `pending_cogs` | Листинг есть, цены нет | нет |
| `DONE` | `done` | COGS создан | да |
| `SKIPPED` | `skipped` | Цена = 0 или null, создавать нечего | да |

Методы: `label(): string`, `isTerminal(): bool`.

### 2.4 Матрица переходов enrichmentStatus

| из / в | NOT_APPLICABLE | PENDING_LISTING | PENDING_COGS | DONE | SKIPPED |
|---|---|---|---|---|---|
| NOT_APPLICABLE | ❌ | ❌ | ❌ | ❌ | ❌ |
| PENDING_LISTING | ❌ | ❌ | ✅ | ✅ | ✅ |
| PENDING_COGS | ❌ | ❌ | ❌ | ✅ | ✅ |
| DONE | ❌ | ❌ | ❌ | ✅ (пересчёт) | ❌ |
| SKIPPED | ❌ | ❌ | ❌ | ✅ (цена появилась) | ❌ |

---

## 3. Слой доступа к данным

### 3.1 Repository

#### `App\Ingestion\Repository\EnrichmentTransactionRepository`

`final class`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findByTransactionId(string $companyId, string $transactionId, EnrichmentKind $kind): ?EnrichmentTransaction` | Поиск COGS для транзакции | да | `?EnrichmentTransaction` |
| `findPendingCogs(int $limit = 100): list<array{transactionId: string, companyId: string, listingId: string, occurredAt: DateTimeImmutable}>` | Системный: SALE-транзакции с `enrichmentStatus IN (PENDING_COGS)` старше 5 минут | нет* | `list<array>` |
| `findPendingListing(int $limit = 100): list<array{transactionId: string, companyId: string, occurredAt: DateTimeImmutable}>` | Системный: транзакции с `enrichmentStatus=PENDING_LISTING` (листинг мог резолвиться) | нет* | `list<array>` |

*Системные методы — осознанно по всем тенантам.

#### `App\Ingestion\Repository\FinancialTransactionRepository` (расширение)

Добавить метод:

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findSalesByPeriodWithoutDoneEnrichment(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to): list<FinancialTransaction>` | SALE-транзакции за период где enrichmentStatus != DONE | да | `list<FinancialTransaction>` |

### 3.2 Индексы

`ingest_enrichment_transactions`:
- UNIQUE `(company_id, transaction_id, kind)` → `uniq_enrichment_tx_kind`.
- INDEX `(company_id, occurred_at)` → `idx_enrichment_company_occurred`.

`ingest_financial_transactions` (добавить):
- INDEX `(company_id, enrichment_status)` → `idx_ftx_enrichment_status`.

---

## 4. Слой приложения

### 4.1 Action

#### `App\Ingestion\Application\Action\EnrichCogsAction`

Файл: `src/Ingestion/Application/Action/EnrichCogsAction.php`. `final class`.

Вход: `EnrichCogsCommand` (`string $companyId, string $transactionId`).

Шаги:
1. Загрузить `FinancialTransaction` через `findByIdAndCompany`. Нет → `RawRecordNotFoundException`.
2. Если `enrichmentStatus` терминальный (`NOT_APPLICABLE`, `SKIPPED`) и не `DONE` → return (нечего делать).
3. Если `type !== TransactionType::SALE` → `setEnrichmentStatus(NOT_APPLICABLE)` + flush + return.
4. Если `listingId === null` → `setEnrichmentStatus(PENDING_LISTING)` + flush + return.
5. Вызвать `MarketplaceFacade::getCostPriceForListing($companyId, $listingId, $occurredAt)`.
6. Если null или '0.00' → `setEnrichmentStatus(SKIPPED)` + flush + return.
7. Конвертировать в `amountMinor = (int) round((float)$price * 100)`.
8. Найти существующий `EnrichmentTransaction` через Repository. Если есть → `updateAmount`. Если нет → создать новый.
9. persist `EnrichmentTransaction`.
10. `setEnrichmentStatus(DONE)`.
11. flush.
12. Пометить `PLDirtyPeriod` через `PnlFacade::markPeriodDirty($companyId, $period, reason=INGEST)`.

Транзакционность: одна транзакция БД.

### 4.2 Подписчик

#### `App\Ingestion\EventSubscriber\EnrichCogsSubscriber`

Файл: `src/Ingestion/EventSubscriber/EnrichCogsSubscriber.php`. `final class`.

Подписка: `NormalizationCompletedEvent`.

Шаги в `onNormalizationCompleted`:
1. Для каждого `AffectedPeriod` из события — найти SALE-транзакции через `FinancialTransactionRepository::findSalesByPeriodWithoutDoneEnrichment`.
2. Для каждой транзакции dispatch `EnrichCogsMessage($companyId, $transactionId)` в `ingest_normalize`.

### 4.3 Правка App\Marketplace

В Action изменения себестоимости (уточнить имя при старте: `UpdateCostPriceAction` или аналог) добавить после flush:

```
PnlFacade::markPeriodDirty(
    companyId: $companyId,
    year: $effectiveDate->format('Y'),
    month: $effectiveDate->format('n'),
    shopRef: '',
    reason: PLDirtyPeriodReason::REMAP
)
```

Это единственное изменение в `App\Marketplace`. Никаких новых событий, никакой зависимости от Ingestion.

### 4.4 Расширение rebuildPeriod

`RebuildPnlPeriodAction` (блок 7) расширить:

После агрегации `FinancialTransaction` за период — дополнительно итерировать `EnrichmentTransaction` за тот же период через `IngestionFacade::getEnrichments($companyId, $from, $to)`. Добавлять COGS в P&L как расход по соответствующей `PLCategory`.

`PnlCategoryResolver` дополнить маппингом: `EnrichmentKind::COGS` → PLCategory «Себестоимость».

### 4.5 CLI-команда

#### `App\Ingestion\Command\EnrichPendingCogsCommand`

Имя: `app:ingestion:enrich-pending-cogs`.

Опции: `--limit=100` (default 100, max 500).

Шаги:
1. `EnrichmentTransactionRepository::findPendingCogs($limit)` → список транзакций.
2. Для каждой: dispatch `EnrichCogsMessage($companyId, $transactionId)`.
3. Лог: «Dispatched N enrich-cogs messages».

Cron: `*/30 * * * * cd /app && php bin/console app:ingestion:enrich-pending-cogs --no-interaction --quiet`.

### 4.6 Facade

#### `App\Ingestion\Facade\IngestionFacade` (расширение)

Добавить метод:
- `getEnrichments(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to): iterable<EnrichmentTransaction>` — для `rebuildPeriod`.

### 4.7 DTO

#### `App\Ingestion\Application\Command\EnrichCogsCommand`

| Поле | Тип | Обязательно | Валидация |
|---|---|---|---|
| `companyId` | string | да | UUID |
| `transactionId` | string | да | UUID |

---

## 5. Асинхронность (Messenger)

#### `App\Ingestion\Message\EnrichCogsMessage`

`final readonly class`, реализует `CompanyAwareMessage`.
Поля: `companyId: string`, `transactionId: string`. Метод `getCompanyId(): string`.

#### `App\Ingestion\MessageHandler\EnrichCogsHandler`

`final class`, `#[AsMessageHandler]`. `IdempotentHandlerTrait` по key `transactionId`.

Вызывает `EnrichCogsAction`.

Routing: `ingest_normalize` (существующий transport).

| Параметр | Значение |
|---|---|
| Transport | `ingest_normalize` |
| Retry | 3 попытки, delay 5s |
| Идемпотентность | `IdempotentHandlerTrait` по `transactionId` |

`messenger.yaml` — добавить routing:
```yaml
App\Ingestion\Message\EnrichCogsMessage: ingest_normalize
```

---

## 6. Обработка ошибок

| Класс | Когда | HTTP-статус | error.code | message |
|---|---|---|---|---|
| `RawRecordNotFoundException` (существующий) | `FinancialTransaction` не найден | 404 | `transaction_not_found` | «Транзакция не найдена» |
| `EnrichmentFailedException` | `getCostPriceForListing` бросил исключение | 500 | `enrichment_failed` | «Ошибка получения себестоимости» |

`EnrichmentFailedException` — `App\Ingestion\Exception\EnrichmentFailedException`, `final class`.

При `EnrichmentFailedException` в handler: лог WARNING + `enrichmentStatus` остаётся `PENDING_COGS` → cron подберёт.

---

## 7. HTTP API

N/A.

---

## 8. Разбивка на подзадачи

| Этап | Что | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | `EnrichmentTransaction` Entity + `EnrichmentKind` + `EnrichmentTransactionStatus` + миграция (новая таблица) | — | 🔴 | unit инвариантов |
| B2 | ADD COLUMN `enrichment_status` на `FinancialTransaction` + миграция | B1 | 🔴 | `doctrine:schema:validate` |
| B3 | `EnrichmentTransactionRepository` + расширение `FinancialTransactionRepository` | B1, B2 | 🟡 | tenant-leak на каждый read-метод |
| B4 | `EnrichCogsAction` | B3 | 🟡 | integration: все 5 ветвей enrichmentStatus |
| B5 | `EnrichCogsMessage` + `EnrichCogsHandler` + routing | B4 | 🟡 | integration: идемпотентность |
| B6 | `EnrichCogsSubscriber` (NormalizationCompletedEvent → dispatch) | B5 | 🟢 | unit: dispatch при SALE |
| B7 | `EnrichPendingCogsCommand` + cron | B5 | 🟢 | integration: dispatch PENDING_COGS |
| B8 | Расширение `rebuildPeriod` + `IngestionFacade::getEnrichments` + `PnlCategoryResolver` | B3 | 🟡 | integration: P&L включает COGS |
| B9 | Правка Action изменения цены в `App\Marketplace` | блок 7 | 🟡 | integration: изменение цены → PLDirtyPeriod |
| B10 | Тесты + `ARCHITECTURE.md` | все | 🟢 | tenant-leak на EnrichmentTransaction |

---

## 9. Ограничения и запреты

- `App\Catalog` — не трогать.
- `FinancialTransaction` — только ADD COLUMN `enrichment_status`, без изменения существующих полей.
- `EnrichmentTransaction` — append-only кроме `updateAmount`. Никаких DELETE (аудит).
- `rebuildPeriod` не удаляет `EnrichmentTransaction` — только `PLDailyTotal`/`PLMonthlySnapshot`. COGS остаётся даже при повторном rebuild, только пересчитывается через `updateAmount`.
- `App\Marketplace` — только добавление `PnlFacade::markPeriodDirty` в Action изменения цены. Ничего больше.
- Не создавать COGS при `listingId = null` — только `PENDING_LISTING`.
- Performance: `findPendingCogs` — лимит 100 за тик, INDEX по `(company_id, enrichment_status)`.
- Миграции: zero-downtime (ADD COLUMN nullable с default, CREATE TABLE).

---

## 10. Критерии приёмки

Функциональные:
- [ ] Нормализация SALE с известным листингом и ценой → `EnrichmentTransaction(kind=COGS)` создан, `enrichmentStatus=DONE`.
- [ ] Нормализация SALE с `listingId=null` → `enrichmentStatus=PENDING_LISTING`, COGS не создан.
- [ ] Нормализация SALE с листингом без цены → `enrichmentStatus=PENDING_COGS`, COGS не создан.
- [ ] Нормализация не-SALE транзакции → `enrichmentStatus=NOT_APPLICABLE`.
- [ ] Повторный `EnrichCogsHandler` для той же транзакции → idempotent, COGS не дублируется.
- [ ] Cron подбирает PENDING_COGS → dispatch → DONE после появления цены.
- [ ] Изменение цены в Marketplace → `PLDirtyPeriod(reason=REMAP)` → `rebuildPeriod` → P&L с новым COGS.
- [ ] `rebuildPeriod` суммирует `FinancialTransaction` + `EnrichmentTransaction` за период.
- [ ] `PnlCategoryResolver` маппит `COGS` в PLCategory «Себестоимость».

Технические:
- [ ] `doctrine:schema:validate --skip-sync --env=test` — зелёный.
- [ ] `lint:container --env=test` — зелёный.
- [ ] `make site-test-unit` + `make site-test-integration` — зелёные.
- [ ] Tenant-leak тест на `EnrichmentTransaction`.
- [ ] `php-cs-fixer` — зелёный.
- [ ] `ARCHITECTURE.md` обновлён: `EnrichmentTransaction`, `EnrichmentKind`, `IngestionFacade::getEnrichments`.

---

## 11. План отката

- DROP `enrichment_status` из `ingest_financial_transactions` — отдельная миграция. Данные не теряются.
- DROP TABLE `ingest_enrichment_transactions` — отдельная миграция.
- Убрать `EnrichCogsSubscriber` — нормализация продолжит работать без обогащения.
- Убрать cron-строку.
- `rebuildPeriod` без COGS вернётся к поведению до задачи.
- Правка Marketplace Action (markPeriodDirty) — убрать одну строку.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь для каждого класса.
- [x] Таблица полей `EnrichmentTransaction` с инвариантами.
- [x] `enrichmentStatus` на `FinancialTransaction` с матрицей переходов.
- [x] Каждый enum case описан.
- [x] Repository-методы системные явно помечены (без companyId).
- [x] `EnrichCogsAction` — все 5 ветвей (NOT_APPLICABLE, PENDING_LISTING, PENDING_COGS, SKIPPED, DONE).
- [x] Идемпотентность handler'а через `IdempotentHandlerTrait`.
- [x] `App\Marketplace` — минимальная правка, одна строка.
- [x] `App\Catalog` — не трогаем, явно в out of scope.
- [x] HTTP — N/A.
- [x] Индексы с именами.
- [x] Миграции zero-downtime.
- [x] Plan отката без потери данных.
- [x] `rebuildPeriod` не удаляет EnrichmentTransaction.
- [x] Out of scope: MANUAL/EXTERNAL/TAX, UI, WB.
