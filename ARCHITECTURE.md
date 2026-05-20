# ARCHITECTURE.md — VashFinDir

> **Живой документ.** Обновляется после каждого нового модуля или изменения публичного контракта.
> Читается: Claude Code (через CLAUDE.md) и Claude.ai Projects (через Knowledge).
> Версия: 1.47 / 2026-05-11

---

## Модули (`src/`)

| Модуль | Назначение | companyId паттерн |
|---|---|---|
| `Cash` | Денежные счета, транзакции, банковский импорт, план платежей | `Company $company` (legacy) |
| `Marketplace` | WB/Ozon: продажи, возвраты, расходы, закрытие месяца | смешанный |
| `Catalog` | Товары, штрихкоды, закупочные цены | `string $companyId` ✅ |
| `Deals` | Сделки | `Company $company` (legacy) |
| `Finance` | PnL-отчёты, кэшфлоу, фасады финансовой аналитики | `Company $company` (legacy) |
| `Company` | Компании, пользователи, приглашения, тарифы | — (владелец) |
| `Balance` | Управленческий баланс, провайдеры значений | legacy |
| `Billing` | Биллинг и подписки | — |
| `Loan` | Кредиты и займы | legacy |
| `Ai` | Интеграция с LLM | — |
| `Telegram` | Telegram-бот, вебхуки | — |
| `MoySklad` | Интеграция с МойСклад | `string $companyId` ✅ |
| `Analytics` | Аналитические запросы и дашборды | — |
| `MarketplaceAnalytics` | Аналитика маркетплейсов (витрина) | — |
| `MarketplaceAds` | Рекламные отчёты WB/Ozon: загрузка raw → распределение затрат | `string $companyId` ✅ |
| `Inventory` | Загрузка raw-остатков маркетплейсов, нормализация в StockSnapshot, UI-отчёт остатков | `string $companyId` ✅ |
| `Notification` | Каналы уведомлений (email и др.) | — |
| `Shared` | Общий код: ActiveCompanyService, аудит, безопасность, storage | — |
| `Admin` | Административная панель (отдельный firewall) | — |

**Legacy-зона** (технический долг, новый код туда НЕ идёт):
`src/Entity/` · `src/Service/` · `src/Repository/` · `src/Controller/`

Текущие legacy-сущности в `src/Entity/`:
`Document` · `DocumentOperation` · `PLCategory` · `PLDailyTotal` · `PLMonthlySnapshot` · `ProjectDirection` · `Counterparty` · `ReportApiKey`

---

## Entity — статус миграции на `string $companyId`

| Entity | Модуль | Паттерн |
|---|---|---|
| `MoySkladConnection` | MoySklad | `string $companyId` ✅ |
| `MarketplaceMonthClose` | Marketplace | `string $companyId` ✅ |
| `MarketplaceOzonRealization` | Marketplace | `string $companyId` ✅ |
| `MarketplaceJobLog` | Marketplace | `string $companyId` ✅ |
| `MarketplaceCostPLMapping` | Marketplace | `string $companyId` ✅ |
| `MarketplaceAdvertisingCost` | Marketplace | `string $companyId` ✅ |
| `MarketplaceOrder` | Marketplace | `string $companyId` ✅ |
| `ReconciliationSession` | Marketplace | `string $companyId` ✅ |
| `OzonTransactionTotalsCheck` | Marketplace | `string $companyId` ✅ |
| `MarketplaceFinancialReportSyncStatus` | Marketplace | `string $companyId` ✅ |
| `MarketplaceFinancialReportSyncError` | Marketplace | `string $companyId` ✅ |
| `UnitEconomyCostMapping` | MarketplaceAnalytics | `string $companyId` ✅ |
| `ListingDailySnapshot` | MarketplaceAnalytics | `string $companyId` ✅ |
| `AdRawDocument` | MarketplaceAds | `string $companyId` ✅ |
| `AdDocument` | MarketplaceAds | `string $companyId` ✅ |
| `AdDocumentLine` | MarketplaceAds | `string $companyId` ✅ |
| `AdLoadJob` | MarketplaceAds | `string $companyId` ✅ |
| `AdChunkProgress` | MarketplaceAds | через `jobId` (IDOR через AdLoadJob) |
| `OzonAdPendingReport` | MarketplaceAds | `string $companyId` ✅ |
| `AdScheduledBatch` | MarketplaceAds | `string $companyId` ✅ |
| `InventorySnapshotSession` | Inventory | `string $companyId` ✅ |
| `InventoryRawSnapshot` | Inventory | `string $companyId` ✅ |
| `Location` | Inventory | `string $companyId` ✅ |
| `StockSnapshot` | Inventory | `string $companyId` ✅ |
| `ProductImport` | Catalog | `string $companyId` ✅ |
| `ProductBarcode` | Catalog | `string $companyId` ✅ |
| `ProductPurchasePrice` | Catalog | `string $companyId` ✅ |
| `AuditLog` | Shared | `string $companyId` ✅ |
| `CashTransaction`, `MoneyAccount` и др. | Cash | `Company $company` (legacy) |
| `Deal`, `ChargeType` | Deals | `Company $company` (legacy) |
| `PLCategory`, `Document` и др. | legacy `src/Entity/` | `Company $company` (legacy) |

### Marketplace: WB financial report sync status (дневной статус)

- Entity: `MarketplaceFinancialReportSyncStatus`.
- `companyId`: `string UUID` (неизменяемое поле, не часть бизнес-ключа).
- `connectionId`: `string UUID`.
- `businessDate`: `date` (`DateTimeImmutable`, `date_immutable` в ORM).
- Бизнес-ключ (idempotency / unique key): `connection_id + report_type + business_date`.
- `empty day` (статус `EMPTY`) **не равен** `missing day`: пустой день считается обработанным, а не пропущенным.
- `apiEndpoint` — техническое мета-поле источника/маршрута API, **не часть бизнес-ключа** статуса.

### Marketplace: append-only история ошибок WB financial sync

- Entity: `MarketplaceFinancialReportSyncError`.
- Назначение: хранит отдельные записи ошибок синхронизации как append-only историю; retry не перезаписывает предыдущую диагностику.
- Поля: `syncStatusId`, `companyId`, `connectionId`, `businessDate`, `errorClass`, `errorMessage`, `statusCode`, `responseExcerpt`, `requestPayload`, `createdAt`.
- `requestPayload` хранится в JSON-формате и **не должен** содержать API token, plaintext secret или полный raw response body.

### `UnitEconomyCostMapping` — поля

| Поле | Тип | Описание |
|---|---|---|
| `id` | `string` (UUID v7) | PK |
| `companyId` | `string` (UUID) | Неизменяем, без setter |
| `marketplace` | `MarketplaceType` | WB, Ozon и др. |
| `unitEconomyCostType` | `UnitEconomyCostType` | Статья юнит-экономики (11 фиксированных) |
| `costCategoryId` | `string` (UUID) | ID категории затрат МП (из `marketplace_cost_categories`) |
| `costCategoryName` | `string` | Название для отображения |
| `createdAt` | `DateTimeImmutable` | — |
| `updatedAt` | `DateTimeImmutable` | — |

**Логика:** одна категория МП → одна статья (UniqueConstraint по `companyId + marketplace + costCategoryId`).
Одна статья ← несколько категорий МП.
Удалено: `isSystem`, `costCategoryCode`.

### `MarketplaceConnection` — поля

| Поле | Тип | Описание |
|---|---|---|
| `id` | `string` (UUID) | PK |
| `company` | `Company` | ManyToOne (legacy паттерн) |
| `marketplace` | `MarketplaceType` | WB, Ozon и др. |
| `connectionType` | `MarketplaceConnectionType` | Тип подключения: `SELLER` (финансы/продажи/остатки) или `PERFORMANCE` (реклама). Дефолт — `SELLER` |
| `apiKey` | `string` | Ключ API (для Ozon Performance — `client_secret`) |
| `clientId` | `?string` | Client-Id для Ozon Seller API / `client_id` для Ozon Performance |
| `isActive` | `bool` | Активно ли подключение |
| `lastSyncAt` | `?DateTimeImmutable` | — |
| `lastSuccessfulSyncAt` | `?DateTimeImmutable` | — |
| `lastSyncError` | `?string` | — |
| `settings` | `?array` | JSON с дополнительными настройками (напр. `project_direction_id`) |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | — |

**Уникальность:** `UniqueConstraint` по `(company_id, marketplace, connection_type)` — одна компания может иметь два подключения к одному маркетплейсу (Seller + Performance), но только по одному каждого типа.

### `InventorySnapshotSession` — поля

- `id` — UUID;
- `companyId` — string UUID;
- `source` — `MarketplaceType`;
- `status` — `SnapshotSessionStatus`;
- `triggerType` — `SnapshotTriggerType`;
- `triggeredBy` — UUID пользователя (для manual-trigger), nullable;
- `expectedPages` — ожидаемое число страниц, nullable;
- `receivedPages` — число сохранённых raw-страниц;
- `errorMessage` — текст ошибки для `partial/failed`;
- `correlationId` — UUID трассировки;
- `startedAt` — время старта загрузки;
- `finishedAt` — фактическое поле `completedAt`;
- `requestParams` на уровне session в текущей реализации отсутствует; технические параметры (включая `connectionId`) фиксируются в `InventoryRawSnapshot.requestParams`;
- `createdAt` / `updatedAt`.

Семантика:
- одна session = одна raw-загрузка;
- только `completed` session становится входом для async-normalization;
- `partial` / `failed` автоматически не нормализуются.

### `InventoryRawSnapshot` — поля

- `id`;
- `companyId`;
- `snapshotSessionId`;
- `source`;
- `sourceEndpoint`;
- `requestParams`;
- `responseStatus`;
- `responseBody`;
- `fetchedAt`;
- `fetchDurationMs`;
- `correlationId`;
- `pageNumber`;
- `isProcessed`;
- `processedAt`;
- `processingError`;
- `createdAt`.

Семантика:
- хранит raw-страницу ответа Ozon;
- raw JSON используется для диагностики и повторной нормализации;
- raw-слой не используется напрямую UI-отчётом остатков.

### `StockSnapshot` — поля

| Поле | Тип | Описание |
|---|---|---|
| `id` | `string` UUID | PK |
| `companyId` | `string` UUID | IDOR-ключ |
| `snapshotSessionId` | `string` UUID | Ссылка на InventorySnapshotSession |
| `snapshotDate` | `DateTimeImmutable/date` | День snapshot |
| `snapshotAt` | `DateTimeImmutable` | Точное время snapshot |
| `locationId` | `string` UUID | Ссылка на Inventory Location |
| `source` | `MarketplaceType` | Источник: сейчас Ozon |
| `sourceSku` | `string` | SKU источника, для Ozon = `stocks[].sku` |
| `sourceOfferId` | `?string` | Для Ozon = `item.offer_id` |
| `fulfillmentType` | `?string` | Для Ozon = `stocks[].type` (`fbo`, `fbs`, `rfbs`) |
| `listingId` | `?string` UUID | ID MarketplaceListing, если найден |
| `productId` | `?string` UUID | Product ID, если listing связан с товаром |
| `status` | `StockStatus` | Для этапа 1 всегда `Available` |
| `mappingStatus` | `StockSnapshotMappingStatus` | `mapped` / `unmapped` / `ambiguous` |
| `quantity` | `numeric(14,3)` | Для Ozon = `stocks[].present` |
| `reservedQuantity` | `numeric(14,3)` | Для Ozon = `stocks[].reserved` |
| `rawSnapshotId` | `string` UUID | Ссылка на raw page |
| `createdAt` | `DateTimeImmutable` | — |

Зафиксировано:
- `availableForSale` не хранится в БД;
- `availableForSale = quantity - reservedQuantity` считается в Query/UI;
- `reserved` не является отдельным `StockStatus`;
- `StockStatus::Reserved` не существует и не добавляется на этапе 1.

**Уникальность StockSnapshot (этап 1):**
- `company_id`
- `snapshot_date`
- `source`
- `source_sku`
- `fulfillment_type`
- `location_id`
- `status`

Почему так:
- `source_sku` обязателен в ключе, чтобы unmapped SKU за один день не конфликтовали;
- `listing_id` и `product_id` могут быть `null`, это ненадёжный ключ идемпотентности;
- upsert выполняется по day-level snapshot key.

### `Location` — Inventory

- используется как универсальная локация остатка;
- на этапе 1 для Ozon создаются агрегированные технические локации по fulfillment bucket:
  - `fbo`;
  - `fbs`;
  - `rfbs`;
  - `unknown` (если `fulfillmentType` отсутствует);
- это summary-location первого этапа, не детализация по складам Ozon;
- складская детализация — отдельный этап.

### `AdLoadJob` — поля

| Поле | Тип | Описание |
|---|---|---|
| `id` | `string` (UUID v7) | PK |
| `companyId` | `string` (UUID) | Неизменяем, без setter |
| `marketplace` | `MarketplaceType` | WB, Ozon |
| `dateFrom` / `dateTo` | `DateTimeImmutable` | Диапазон загрузки (нормализован до 00:00, включительно) |
| `totalDays` | `int` | Автосчёт из diff + 1 в конструкторе |
| `loadedDays` | `int` | Атомарный счётчик фактически загруженных дней (raw SQL `UPDATE ... SET loaded_days = loaded_days + :delta`, минуя UoW) |
| `chunksTotal` | `int` | Кол-во чанков по 62 дня, проставляется один раз в `LoadOzonAdStatisticsRangeHandler` |
| `status` | `AdLoadJobStatus` | `pending` / `running` / `completed` / `failed` |
| `failureReason` | `?string` | Причина FAILED |
| `startedAt` / `finishedAt` | `?DateTimeImmutable` | — |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | — |

**Финализация job'а** выполняется через COUNT по `marketplace_ad_raw_documents`: per-document FAILED-статус `AdRawDocument` — источник правды. Отдельные диагностические счётчики `processed_days` / `failed_days` удалены как мёртвые.

### `AdChunkProgress` — поля

| Поле | Тип | Описание |
|---|---|---|
| `id` | `string` (UUID v7) | PK |
| `jobId` | `string` (UUID) | Ссылка на `AdLoadJob` |
| `dateFrom` / `dateTo` | `DateTimeImmutable` | Диапазон чанка (нормализован до 00:00) |
| `completedAt` | `DateTimeImmutable` | Время фиксации успеха |

**Уникальность:** `UniqueConstraint` по `(job_id, date_from, date_to)` — делает фиксацию чанка идемпотентной на уровне БД. При Messenger-retry `FetchOzonAdStatisticsHandler` тот же чанк упрётся в uq-нарушение и не приведёт к двойному инкременту `loadedDays`.

### `OzonAdPendingReport` — поля

Таблица `marketplace_ad_pending_reports`. Фиксирует каждый запрошенный у Ozon
Performance отчёт: UUID сохраняется ДО polling'а, что делает любой сбой
pipeline'а (timeout, рестарт worker'а, exception) видимым для диагностики и
даёт точку отталкивания для будущей resume-логики (задача 3).

| Поле | Тип | Описание |
|---|---|---|
| `id` | `string` (UUID v7) | PK |
| `companyId` | `string` (UUID) | Неизменяем, без setter |
| `ozonUuid` | `string` | UUID отчёта в Ozon Performance (`POST /api/client/statistics.UUID`). Уникален |
| `jobId` | `?string` (UUID) | Ссылка на `AdLoadJob`, если отчёт запрошен range-пайплайном; `null` для legacy `fetchAdStatistics()` |
| `dateFrom` / `dateTo` | `DateTimeImmutable` | Диапазон отчёта |
| `campaignIds` | `list<string>` | campaign IDs, отправленные в `POST /statistics` (jsonb) |
| `state` | `string` | Canonical state из {@see OzonAdPendingReportState} |
| `pollAttempts` | `int` | Счётчик итераций polling'а (обновляется raw DBAL) |
| `lastCheckedAt` | `?DateTimeImmutable` | Время последней итерации |
| `firstNonPendingAt` | `?DateTimeImmutable` | Первая итерация, на которой state сошёл с `NOT_STARTED`; фиксируется один раз (COALESCE-guard в Repository) |
| `finalizedAt` | `?DateTimeImmutable` | Выставляется один раз при `markFinalized`; guard против повторной терминализации |
| `nextPollAt` | `?DateTimeImmutable` | Плановое время следующего polling'а. `NULL` = «опросить немедленно на ближайшем тике cron-а». Используется poll-cron'ом с partial-индексом `idx_ad_pending_report_next_poll` (`WHERE finalized_at IS NULL`). Введено в step 2/5 async-poll redesign |
| `errorMessage` | `?string` | Диагностика для state=ERROR / ABANDONED |
| `requestedAt` | `DateTimeImmutable` | Время создания записи |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | — |

**Уникальность:** `UniqueConstraint` по `ozon_uuid`. Индексы: `company_id`, `job_id`, `state`, partial `idx_ad_pending_report_next_poll` на `next_poll_at WHERE finalized_at IS NULL`.

### `AdScheduledBatch` — поля

Таблица `marketplace_ad_scheduled_batches` (см. миграция Task-11.1). План
последовательной обработки одного батча Ozon Performance (подмножество
кампаний ≤ 10, поддиапазон дат ≤ 62 дня) cron-командами Task-11.3+.

| Поле | Тип | Описание |
|---|---|---|
| `id` | `string` (UUID) | PK. Передаётся извне в конструктор (bulk-scheduler может генерировать серию ID заранее). |
| `jobId` | `string` (UUID) | Ссылка на `AdLoadJob` |
| `companyId` | `string` (UUID) | Неизменяем, без setter |
| `marketplace` | `string` | Дефолт `'ozon'`, на Task-11.2 других значений не ожидается |
| `campaignIds` | `list<string>` | JSONB с ID кампаний в батче |
| `dateFrom` / `dateTo` | `DateTimeImmutable` | Диапазон батча (нормализован до 00:00, включительно) |
| `batchIndex` | `int` | Порядковый номер батча в рамках job'а; входит в UNIQUE `(job_id, batch_index)` |
| `state` | `AdScheduledBatchState` | `PLANNED` / `IN_FLIGHT` / `OK` / `FAILED` / `ABANDONED` |
| `scheduledAt` | `DateTimeImmutable` | Когда батч готов к обработке (scheduler picks oldest) |
| `startedAt` / `finishedAt` | `?DateTimeImmutable` | Устанавливаются cron-командами при переходе `PLANNED → IN_FLIGHT → terminal` |
| `ozonUuid` | `?string` | UUID отчёта от POST `/api/client/statistics` |
| `storagePath` / `fileHash` / `fileSize` | `?string` / `?string` / `?int` | Итоговый CSV/ZIP на диске (аналогично `AdRawDocument.storagePath`) |
| `retryCount` | `int` | Счётчик попыток, по умолчанию 0 |
| `lastError` | `?string` | Диагностика последней неуспешной попытки |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | — |

**Индексы:** partial `idx_asb_scheduler (scheduled_at) WHERE state='PLANNED'`, partial `idx_asb_poller (id) WHERE state='IN_FLIGHT'`, `idx_asb_job (job_id, state)`, UNIQUE `idx_asb_job_batch (job_id, batch_index)` — последний обеспечивает идемпотентность планирования.

**Repository (`AdScheduledBatchRepository`):**
- `findNextPlanned(): ?AdScheduledBatch` — native SQL `FOR UPDATE SKIP LOCKED`, порядок `scheduled_at ASC, batch_index ASC`, предикат `scheduled_at <= NOW()` (retry/backoff через `setScheduledAt()` в будущее не выбирается)
- `findAllInFlight(): list<AdScheduledBatch>` — порядок `started_at ASC`
- `findByJobId(string $jobId, string $companyId): list<AdScheduledBatch>` — IDOR-guard по `companyId`
- `findDownloadableByJobId(string $jobId, string $companyId): list<AdScheduledBatch>` — `storage_path IS NOT NULL` + IDOR-guard (вызывается из UI Task-11.8)
- `countStatesForJob(string $jobId, string $companyId): array<string,int>` — raw DBAL `GROUP BY state` + IDOR-guard
- `save(AdScheduledBatch $batch): void` — persist без flush (консистентно с `AdLoadJobRepository::save()`), вызывающий сам flush'ит в конце транзакции

Dead code на Task-11.2: Repository ещё никем не вызывается, будет использован в Task-11.3+ (planner / poster / poller / finalizer).

---

## Facade — публичные методы

> Используй **только** эти методы. Не выдумывай новые без обновления этого файла.
> Нет нужного метода — спроси, не создавай самостоятельно.

### `CounterpartyFacade` (`src/Company/Facade/CounterpartyFacade.php`)
```php
// Список контрагентов для ChoiceType в формах
// @return list<array{id: string, name: string}>
getChoicesForCompany(string $companyId): array

// Имена по списку ID — для отображения в таблицах
// @return array<string, string>  uuid => 'ООО Ромашка'
getNamesByIds(array $ids): array
```

### `PLCategoryFacade` (`src/Finance/Facade/PLCategoryFacade.php`)
```php
// Дерево категорий в виде DTO (для ChoiceType в формах)
// @return PLCategoryDTO[]
getTreeByCompanyId(string $companyId): array

// Дерево категорий в виде Entity (legacy: для EntityType пока Loan не мигрирован на string $plCategoryId)
// @return PLCategory[]
findTreeEntitiesByCompanyId(string $companyId): array

// Найти одну категорию по ID с проверкой принадлежности компании
findByIdAndCompany(string $categoryId, string $companyId): ?PLCategoryDTO
```

### `FinanceFacade` (`src/Finance/Facade/FinanceFacade.php`)
```php
// Создать PL-документ из внешнего источника
//
// ВАЖНО — семантика amount у DocumentOperation:
// - Для документов с type=marketplace_pl используется ЗНАКОВАЯ семантика:
//     отрицательная сумма = расход (charge),
//     положительная сумма = доход или сторно (storno) расхода.
//   PLRegisterUpdater::aggregateDocuments() уважает эту семантику:
//     nature == INCOME  → income  += signedAmount
//     nature == EXPENSE → expense += -signedAmount
// - Для остальных типов документов (CASHFLOW_*, TAXES, LOANS, PAYROLL и др.)
//   сохраняется legacy-семантика: amount всегда >= 0, направление
//   (income/expense) определяется по category.flow через nature, и в
//   pl_daily_totals идёт abs(amount).
createPLDocument(
    string $companyId,
    PLDocumentSource $source,
    PLDocumentStream $stream,
    string $periodFrom,
    string $periodTo,
    array $entries,
): string  // ID созданного документа

// Удалить PL-документ
deletePLDocument(string $companyId, string $documentId): void

// Создать Document + DocumentOperation из транзакции ДДС (без flush)
// Бросает DomainException если tx не найдена / IDOR по любой из сущностей
createDocumentFromCashTransaction(
    string $companyId,
    CreateDocumentCommand $command,  // App\Cash\Application\DTO\CreateDocumentCommand
): string  // ID созданного Document

// Обновить PL-регистр за день документа (вызывать после flush в Action)
updatePLRegisterForDocument(string $documentId): void
```

> **Остальные Facade** добавлять сюда по мере реализации модулей.

### `CashFacade` (`src/Cash/Facade/CashFacade.php`)
```php
// Создать ДДС-транзакцию из внешнего модуля (идемпотентно для внешних источников)
createTransaction(CreateCashTransactionCommand $command): CreateCashTransactionResult
```

**Назначение:** `CashFacade` — единственный публичный контракт Cash-модуля для создания ДДС-транзакций из других модулей.

Другие модули не должны:
- создавать `CashTransaction` напрямую;
- вызывать `CashTransactionService` напрямую;
- делать `persist/flush` `CashTransaction` самостоятельно.

**DTO команды:** `CreateCashTransactionCommand` (`src/Cash/Application/DTO/CreateCashTransactionCommand.php`)
- `companyId`
- `moneyAccountId`
- `direction`
- `amount`
- `currency`
- `occurredAt`
- `description` (`nullable`)
- `counterpartyId` (`nullable`)
- `cashflowCategoryId` (`nullable`)
- `projectDirectionId` (`nullable`)
- `importSource` (`nullable`)
- `externalId` (`nullable`)
- `dedupeHash` (`nullable`)
- `rawData` (`nullable`)

**DTO результата:** `CreateCashTransactionResult` (`src/Cash/Application/DTO/CreateCashTransactionResult.php`)
- `transactionId: string`
- `created: bool`
- `duplicate: bool`

**Side effects:** создание через `CashFacade::createTransaction()` сохраняет все side effects `CashTransactionService::add()`:
- VAT logic;
- `PaymentPlanMatcher`;
- `ApplyAutoRulesForTransaction`;
- `DailyBalanceRecalculator`;
- `SnapshotCacheInvalidator`.

**Идемпотентность внешних источников:**
- Для внешних источников нужно заполнять `importSource` и `externalId`.
- Идемпотентность обеспечивается unique constraint `uniq_cashflow_import(company_id, import_source, external_id)`.
- Если запись с тем же `companyId + importSource + externalId` уже существует, `CashFacade` возвращает:
  - `created=false`
  - `duplicate=true`

Важно: одинаковые `amount`/`description`/`occurredAt` **не** являются жёстким ключом дедубликации. Две реальные одинаковые операции разрешены, если `externalId` разный.

**Race-safe поведение:**
- При `UniqueConstraintViolationException` `CashFacade` делает DBAL lookup по:
  - `company_id`
  - `import_source`
  - `external_id`
- Если запись найдена, возвращается duplicate-result (без HTTP 500).

### `MarketplaceAdsFacade` (`src/MarketplaceAds/Facade/MarketplaceAdsFacade.php`)
```php
// Рекламные затраты, распределённые на листинг за одну дату.
// Каждый элемент = одна кампания, атрибутированная листингу по доле продаж.
// @return AdCostForListingDTO[]
getAdCostsForListingAndDate(
    string $companyId,
    string $listingId,
    \DateTimeImmutable $date,
): array

// Суммарные рекламные затраты компании за период.
// $marketplace = MarketplaceType::value ('wildberries', 'ozon') или null (все).
// Возвращает decimal-строку, например "4567.89"; "0" если данных нет.
getTotalAdCostForPeriod(
    string $companyId,
    \DateTimeImmutable $dateFrom,
    \DateTimeImmutable $dateTo,
    ?string $marketplace = null,
): string

// РР с разрезом по листингам за период.
// Для построения строк отчётов с колонкой РР по листингу.
// Возвращает только attributed listingId (те, что есть в marketplace_ad_document_lines).
// Включает «висячие» listing_id без живого листинга — для согласованности с totals.
// Для totals (полная сумма за период) использовать getTotalAdCostForPeriod().
// @return array<string, string>  listingId => decimal-string adSpend
getAdSpendByListingForPeriod(
    string $companyId,
    \DateTimeImmutable $from,
    \DateTimeImmutable $to,
    ?string $marketplace = null,
): array
```

### `MarketplaceAnalyticsFacade` (`src/MarketplaceAnalytics/Facade/MarketplaceAnalyticsFacade.php`)
```php
// Юнит-экономика по листингам за период
// @return ListingUnitEconomics[]
getUnitEconomics(string $companyId, AnalysisPeriod $period, ?string $marketplace): array

// Сводка по портфелю за период
getPortfolioSummary(string $companyId, AnalysisPeriod $period, ?string $marketplace): PortfolioSummary

// Запросить async пересчёт снапшотов за период, возвращает jobId
requestRecalc(string $companyId, AnalysisPeriod $period): string

// Создать маппинг категории затрат МП → статья юнит-экономики
// Выбрасывает DomainException если маппинг для данной категории уже существует
addCostMapping(string $companyId, string $marketplace, UnitEconomyCostType $unitEconomyCostType, string $costCategoryId, string $costCategoryName): UnitEconomyCostMapping

// Удалить маппинг
// Выбрасывает DomainException если маппинг не найден
deleteCostMapping(string $companyId, string $mappingId): void

// Переназначить статью юнит-экономики для маппинга (API только, UI кнопки нет)
// Выбрасывает DomainException если маппинг не найден
remapCostMapping(string $companyId, string $mappingId, UnitEconomyCostType $newType): UnitEconomyCostMapping
```

### `MarketplaceFacade` (`src/Marketplace/Facade/MarketplaceFacade.php`)
```php
// Рекламные расходы по листингу и дате
// @return AdvertisingCostDTO[]
getAdvertisingCostsForListingAndDate(string $companyId, string $listingId, \DateTimeImmutable $date): array

// Заказы по листингу и дате
// @return OrderDTO[]
getOrdersForListingAndDate(string $companyId, string $listingId, \DateTimeImmutable $date): array

// Продажи по листингу и дате
// @return SaleData[]
getSalesForListingAndDate(string $companyId, string $listingId, \DateTimeImmutable $date): array

// Возвраты по листингу и дате
// @return ReturnData[]
getReturnsForListingAndDate(string $companyId, string $listingId, \DateTimeImmutable $date): array

// Затраты по листингу и дате
// @return CostData[]
getCostsForListingAndDate(string $companyId, string $listingId, \DateTimeImmutable $date): array

// Активные листинги компании (опционально — фильтр по маркетплейсу)
// @return ActiveListingDTO[]
getActiveListings(string $companyId, ?string $marketplace): array

// Найти листинг по ID и компании
findListingById(string $companyId, string $listingId): ?ActiveListingDTO

// Все листинги (включая неактивные) по marketplace SKU (родительский артикул / nm_id в WB)
// Нужен для исторических рекламных отчётов, где листинг мог быть деактивирован позже
// @return list<array{id: string, parentSku: string}>
findListingsByMarketplaceSku(string $companyId, string $marketplace, string $marketplaceSku): array

// Bulk-вариант findListingsByMarketplaceSku: один запрос на набор SKU, сгруппирован по parentSku.
// SKU без листингов в результате отсутствуют.
// @param  string[] $marketplaceSkus
// @return array<string, list<array{id: string, parentSku: string}>> parentSku => listings
findListingsByMarketplaceSkus(string $companyId, string $marketplace, array $marketplaceSkus): array

// Inventory использует этот метод как первый шаг маппинга:
// sourceSku → listings (0 => unmapped, 1 => mapped, >1 => ambiguous)

// Bulk-запрос продаж для набора листингов за одну дату (GROUP BY listing_id)
// Листинги без продаж отсутствуют в результате (caller сам подставляет 0)
// @param  string[]           $listingIds
// @return array<string, int> listingId => суммарное количество
getSalesQuantitiesForListings(string $companyId, array $listingIds, \DateTimeImmutable $date): array

// Себестоимость по листингу и дате (null если не задана)
getCostPriceForListing(string $companyId, string $listingId, \DateTimeImmutable $date): ?string

// Список категорий затрат для формы маппинга юнит-экономики
// @return array<array{id: string, code: string, name: string}>
getCostCategoriesForCompany(string $companyId, string $marketplace): array

// Получить учётные данные подключения к API маркетплейса (для кросс-модульного доступа,
// например из MarketplaceAds к Ozon Performance API). connectionType обязателен — caller
// должен явно указать SELLER или PERFORMANCE.
// @return array{api_key: string, client_id: ?string}|null
getConnectionCredentials(string $companyId, MarketplaceType $marketplace, MarketplaceConnectionType $connectionType): ?array

// Безопасный публичный контракт активных Ozon SELLER-подключений
// (без apiKey / clientSecret / settings / credentials)
// @return list<array{connectionId: string, companyId: string, marketplace: string, connectionType: string, clientId: ?string}>
getActiveOzonSellerConnections(?string $companyId = null): array

// Пакетный резолв listingId → productId|null. Используется Inventory модулем
// для маппинга raw API ответов в StockSnapshot записи. IDOR-защита через
// WHERE company_id, чужие листинги отсутствуют в результате. Для orphan-
// листингов (product = null) возвращается null. Limit 5000 listingIds за вызов.
// @param  array<string>             $listingIds
// @return array<string, string|null> map listingId → productId|null
resolveListingsToProducts(string $companyId, array $listingIds): array
```

**Inventory mapping-контракт через Facade:**
- `sourceSku` → `MarketplaceFacade::findListingsByMarketplaceSkus(companyId, marketplace, sourceSkus)`;
- найденные `listingId` → `MarketplaceFacade::resolveListingsToProducts(companyId, listingIds)`;
- Inventory не импортирует напрямую Marketplace repository/service;
- связь с MarketplaceListing в `StockSnapshot` хранится как `listingId: ?string` (без ManyToOne).

---

## Repository — ключевые методы MarketplaceAds

> Контракты репозиториев, используемых handler'ами Ozon Ads pipeline.
> Все методы — IDOR-safe: `company_id` в WHERE там, где применимо.

### `AdLoadJobRepository` (`src/MarketplaceAds/Repository/AdLoadJobRepository.php`)
```php
// Загрузка с IDOR-проверкой по companyId
findByIdAndCompany(string $id, string $companyId): ?AdLoadJob

// Trusted-контекст (Messenger-хендлеры): ID сгенерирован внутри системы
find($id, $lockMode = null, $lockVersion = null): ?AdLoadJob

// Последние задания компании по маркетплейсу (DESC по createdAt)
// @return list<AdLoadJob>
findRecentByCompanyAndMarketplace(string $companyId, MarketplaceType $marketplace, int $limit = 20): array

// Последний активный (PENDING/RUNNING) job — гейт, чтобы не запускать второй параллельно
findLatestActiveJobByCompanyAndMarketplace(string $companyId, MarketplaceType $marketplace): ?AdLoadJob

// Активный job, чей диапазон включает дату — маппинг raw-документа → job
findActiveJobCoveringDate(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $date): ?AdLoadJob

// Атомарный UPDATE loaded_days = loaded_days + :delta (parallel-safe, минуя UoW).
// @return int число обновлённых строк (0 если jobId/companyId не совпал)
incrementLoadedDays(string $jobId, string $companyId, int $delta = 1): int

// Идемпотентные terminal-переходы (raw DBAL UPDATE с guard status IN pending/running)
markCompleted(string $jobId, string $companyId): int
markFailed(string $jobId, string $companyId, string $reason): int
```

### `AdChunkProgressRepository` (`src/MarketplaceAds/Repository/AdChunkProgressRepository.php`)
```php
// Идемпотентная фиксация успеха чанка. true — фиксация прошла;
// false — чанк уже был помечен (Messenger retry) → caller должен пропустить
// инкремент счётчиков, иначе получим double-counting.
markChunkCompleted(
    string $jobId,
    string $companyId,
    \DateTimeImmutable $dateFrom,
    \DateTimeImmutable $dateTo,
): bool

// Кол-во зафиксированных чанков job'а — для сравнения с chunksTotal при финализации.
// IDOR-guard: проверяет принадлежность jobId компании через SELECT к marketplace_ad_load_jobs
// и кидает \DomainException при несоответствии.
countCompletedChunks(string $jobId, string $companyId): int
```

### `AdRawDocumentRepository` (`src/MarketplaceAds/Repository/AdRawDocumentRepository.php`)
```php
// Загрузка с IDOR-проверкой
findByIdAndCompany(string $id, string $companyId): ?AdRawDocument

// Идемпотентный переход DRAFT → FAILED через raw DBAL (минуя UoW).
// @return int 1 — успех, 0 — уже FAILED / не наш
markFailedWithReason(string $documentId, string $companyId, string $reason): int

// COUNT документов компании за период (опц. фильтр по статусу).
// Используется в финализации job'а: (total == processed + failed) → markCompleted.
countByCompanyMarketplaceAndDateRange(
    string $companyId,
    string $marketplace,
    \DateTimeImmutable $from,
    \DateTimeImmutable $to,
    ?AdRawDocumentStatus $statusFilter = null,
): int

// Документы компании за период (DESC по report_date).
// @return list<AdRawDocument>
findByCompanyMarketplaceAndDateRange(
    string $companyId,
    string $marketplace,
    \DateTimeImmutable $from,
    \DateTimeImmutable $to,
): array
```

### `OzonAdPendingReportRepository` (`src/MarketplaceAds/Repository/OzonAdPendingReportRepository.php`)
```php
// Сохраняет запись о запрошенном отчёте (state = REQUESTED) + flush.
// flush() намеренно внутри — caller (OzonAdClient::requestStatistics()) не должен
// откладывать UoW: последующие шаги polling могут упасть, и без немедленного
// сохранения UUID потеряется.
// ВНИМАНИЕ: flush сбрасывает весь UoW — не держите грязные сущности в момент вызова.
create(
    string $companyId,
    string $ozonUuid,
    \DateTimeImmutable $dateFrom,
    \DateTimeImmutable $dateTo,
    array $campaignIds,
    ?string $jobId,
): OzonAdPendingReport

// Инкрементально обновляет state/lastCheckedAt/pollAttempts (raw DBAL, минуя UoW).
// firstNonPendingAt фиксируется через COALESCE — повторная передача не перезаписывает.
// companyId в WHERE — defense-in-depth против IDOR (ozon_uuid сам по себе уникален,
// но проверка company обязательна на каждой операции записи).
// @return int число обновлённых строк (0 — ozonUuid не найден в company)
updateState(
    string $companyId,
    string $ozonUuid,
    string $state,
    \DateTimeImmutable $lastCheckedAt,
    int $pollAttempts,
    ?\DateTimeImmutable $firstNonPendingAt = null,
): int

// Идемпотентный terminal-переход (state ∈ {OK, ERROR, ABANDONED}).
// Guard `finalized_at IS NULL` не даёт перезаписать уже финализированную запись
// (параллельный worker, пришедший позже к другому state, не стирает исходный).
// @return int число обновлённых строк (0 — uuid не найден / уже финализирован)
markFinalized(
    string $companyId,
    string $ozonUuid,
    string $state,
    ?string $errorMessage = null,
): int

// Загрузка с IDOR-проверкой
findByOzonUuid(string $companyId, string $ozonUuid): ?OzonAdPendingReport

// IDOR-safe lookup по PK + companyId. Используется async-download handler'ом
// (step 4 redesign): Messenger-payload несёт pending report ID + companyId.
findByIdAndCompany(string $id, string $companyId): ?OzonAdPendingReport

// Все in-flight (REQUESTED / NOT_STARTED / IN_PROGRESS) записи конкретного job'а.
// Для resume-логики (задача 3): Messenger-retry handler получает список UUID,
// по которым нужно продолжать polling вместо нового POST /statistics.
// @return list<OzonAdPendingReport>
findInFlightByJob(string $companyId, string $jobId): array

// Все in-flight (не финализированные) записи для company, ORDER BY requested_at ASC.
// "In-flight" = finalized_at IS NULL (единственный источник правды; фильтр по state
// намеренно не добавлен, чтобы не дублировать логику терминализации).
// Используется будущей poll-cron (шаг 3 redesign-плана) для bulk-запроса
// GET /api/client/statistics/list по всем активным UUID одной компании.
// companyId обязателен и валидируется Assert::uuid().
// @return list<OzonAdPendingReport>
findInFlightByCompany(string $companyId): array

// Счётчик in-flight pending reports (finalized_at IS NULL) конкретной company.
// Используется backpressure-гейтом в RequestOzonAdBatchHandler: если >= 3
// (Ozon жёсткий лимит «активных отчётов» на аккаунт) — POST не делается,
// сообщение откладывается на 60с. Raw DBAL COUNT, без hydration — вызывается
// на каждом сообщении и должен быть быстрым.
// companyId обязателен и валидируется Assert::uuid().
countInFlightByCompany(string $companyId): int

// Distinct companyIds с хотя бы одной in-flight записью, готовой к опросу:
// finalized_at IS NULL AND (next_poll_at IS NULL OR next_poll_at <= :now).
// next_poll_at IS NULL = «опросить на ближайшем тике» (legacy + fresh REQUESTED).
// Используется poll-cron'ом (OzonPollReportsCommand). Raw DBAL, без ORM-гидратации.
// @return list<string>
findCompanyIdsWithDueReports(\DateTimeImmutable $now): array

// Scheduling-only update: last_checked_at, next_poll_at, poll_attempts, updated_at.
// state / error_message / finalized_at не трогает. Guard `finalized_at IS NULL`
// + companyId в WHERE. Используется poll-cron'ом, когда Ozon ещё не отдал
// нового state, но тик надо зафиксировать и перепланировать.
// @return int число обновлённых строк (0 — uuid не найден / уже финализирован)
updateSchedule(
    string $companyId,
    string $ozonUuid,
    \DateTimeImmutable $lastCheckedAt,
    \DateTimeImmutable $nextPollAt,
    int $pollAttempts,
): int

// Обновляет state + scheduling одним UPDATE'ом (атомарно, без гонки с markFinalized).
// $nextPollAt=null — записывает NULL в БД (дальше по scheduling не опрашиваем).
// Отдельный метод от updateState(), чтобы не ломать старых вызывающих.
// @return int число обновлённых строк (0 — uuid не найден / уже финализирован)
updateStateWithSchedule(
    string $companyId,
    string $ozonUuid,
    string $state,
    \DateTimeImmutable $lastCheckedAt,
    ?\DateTimeImmutable $nextPollAt,
    int $pollAttempts,
    ?\DateTimeImmutable $firstNonPendingAt = null,
): int
```

### `OzonAdClient::requestStatisticsOnly` (`src/MarketplaceAds/Infrastructure/Api/Ozon/OzonAdClient.php`)
```php
// Async-poll flow (step 5): только POST /statistics, без polling'а и без download'а.
// Выполняет resolveCredentials → token → listSkuCampaigns → filterCampaignsForDateRange →
// array_chunk(STATISTICS_BATCH_SIZE=10) → per-batch POST /statistics с персистом
// OzonAdPendingReport(state=REQUESTED, jobId=this job). matchResumableReport защищает
// от duplicate POST при Messenger-retry (окно RESUME_MAX_AGE_SECONDS=900s).
// nextPollAt остаётся NULL — poll-cron обрабатывает его как "polled on next tick".
// Downstream: poll-cron переводит state REQUESTED → OK; DownloadOzonAdReportHandler
// завершает ингест и диспатчит ProcessAdRawDocumentMessage.
// Используется FetchOzonAdStatisticsHandler вместо старого fetchAdStatisticsRange.
// @return list<string> UUID'ы, созданные или переиспользованные для текущего чанка
// @throws OzonPermanentApiException 403 / missing credentials
// @throws \InvalidArgumentException диапазон > 62 дней / from > to
// @throws \RuntimeException         прочие non-2xx / network / JSON-ошибки
requestStatisticsOnly(
    string $companyId,
    \DateTimeImmutable $dateFrom,
    \DateTimeImmutable $dateTo,
    ?string $jobId,
): array
```

### `OzonAdClient::pollOneReport` (`src/MarketplaceAds/Infrastructure/Api/Ozon/OzonAdClient.php`)
```php
// Один GET /api/client/statistics/{uuid} — надёжный per-UUID poll. Возвращает
// uppercase state и сырой ответ (для диагностики / логирования). Ретраев нет —
// транспорт/5xx/timeouts бросаются наружу, caller ловит их per-UUID без
// прерывания итерации.
// v1.17: основной механизм polling'а вместо сломанного /statistics/list
// (инцидент 23.04.2026 — листинг возвращал total=0 при реальных OK отчётах).
// @return array{state: string, raw: array<string, mixed>}
// @throws OzonPermanentApiException 403 — нет Performance scope
// @throws \RuntimeException         прочие non-2xx / транспорт / JSON
pollOneReport(string $companyId, string $uuid): array
```

### `OzonAdClient::listReportsForCompany` (`src/MarketplaceAds/Infrastructure/Api/Ozon/OzonAdClient.php`) — @deprecated v1.17
```php
// @deprecated Since v1.17: /statistics/list ненадёжен (инцидент 23.04.2026).
//             Используйте pollOneReport($companyId, $uuid).
//             Оставлен для возможного диагностического использования.
// Один HTTP-снимок Ozon Performance GET /api/client/statistics/list.
// Не спит, не ретраится по state — возвращает текущий map "UUID => raw state".
// @return array<string, string> UUID => state (raw Ozon)
// @throws OzonPermanentApiException 403 — нет Performance scope
// @throws \RuntimeException         прочие non-2xx / транспорт / JSON
listReportsForCompany(string $companyId): array
```

### `OzonAdReportPoller` (`src/MarketplaceAds/Application/Service/OzonAdReportPoller.php`)

Per-company state machine для per-UUID polling'а (v1.17). `__invoke(companyId): PollResult`.

Вход: `companyId` (UUID). Выход: `PollResult { seen, updated, finalized, errors }`
(readonly DTO).

Контракт:
1. `findInFlightByCompany($companyId)` — если пусто, zero-result, Ozon не дёргается.
2. Для каждого in-flight row: `OzonAdClient::pollOneReport($companyId, $uuid)`.
    - любой `\Throwable` на одном UUID → `errors++`, остальные обрабатываются.
3. Per-row reconcile по state:
    - state ∈ {OK, READY} → `updateStateWithSchedule(OK, nextPollAt=null)`,
      ТОЛЬКО ЕСЛИ updatedRows > 0 — dispatch `DownloadOzonAdReportMessage` в
      `async_pipeline` (v1.16: защита от гонки с параллельной финализацией);
    - state ∈ {ERROR, CANCELLED, NOT_FOUND} → `markFinalized(ERROR, state в message)`;
    - non-terminal (NOT_STARTED / IN_PROGRESS / прочее) → `updateStateWithSchedule(mappedState, next backoff)`;
      неизвестные значения маппятся в IN_PROGRESS (продолжаем polling);
      затем overlay-check: если age ≥ MAX_AGE_BEFORE_ABANDON → `markFinalized(ABANDONED)`.

Backoff: `30 / 60 / 120 / 300 / 600 сек` по `poll_attempts` (1-based), clamp на 600.
MAX_AGE_BEFORE_ABANDON_SECONDS = 10 800 (3 часа, v1.15).

### `OzonPollReportsCommand` (`app:marketplace-ads:ozon-poll-reports`)

```
app:marketplace-ads:ozon-poll-reports [--company-id=UUID] [--dry-run]
```

Оркестратор polling'а: `findCompanyIdsWithDueReports(now)` → для каждой
компании вызов `OzonAdReportPoller`. Per-company isolation: исключение одной
компании не валит остальных.

- `--dry-run` — не делает HTTP и не пишет в БД, только печатает "DRY company=… in_flight=…".
- `--company-id=UUID` — опрос одной компании (диагностика).
- Exit code: `FAILURE` если хоть у одной компании `errors > 0`, иначе `SUCCESS`.

**Cron** (с step 5): `*/2 * * * *` в `docker/cron/app.cron`. Тикает каждые 2 минуты,
за тик до N `GET /statistics/{uuid}` на активную компанию, где N = число in-flight
(ограничено backpressure v1.13 сверху 3). Итого ≤ 3×companies HTTP-calls за тик.
Median time REQUEST → OK detection ~60-180s.

### `OzonAdClient::downloadAndConvertReport` (`src/MarketplaceAds/Infrastructure/Api/Ozon/OzonAdClient.php`)
```php
// Скачивает готовый (state=OK/READY) Ozon-отчёт по UUID и конвертирует CSV
// в структуру date => ['campaigns' => [...]] (совместимо с shape'ом
// fetchAdStatisticsRange()). НЕ опрашивает state и НЕ спит: предполагается,
// что caller (poll-cron → DownloadOzonAdReportHandler) уже знает, что отчёт
// готов. Один GET /statistics/{uuid} за свежей ссылкой + GET report + парсинг.
// 401 → один refresh-токен retry (withAuthRetry).
// namesById намеренно пустой: campaign_name приходит отдельной колонкой CSV;
// листинг кампаний стоит лишнего HTTP и не улучшает качество fallback'а.
// @param list<string> $campaignIds — только для логирования контекста
// @return array{downloads: list<OzonReportDownload>, resultByDate: array<string, array{campaigns: list<array{...}>}>}
// @throws OzonPermanentApiException 403 / missing credentials
// @throws \RuntimeException         не-готовый state / прочие non-2xx / network / JSON
downloadAndConvertReport(
    string $companyId,
    string $reportUuid,
    array $campaignIds = [],
): array
```

### `DownloadOzonAdReportMessage` (`src/MarketplaceAds/Message/DownloadOzonAdReportMessage.php`)

Scalar-only Messenger-сообщение: `(companyId, pendingReportId)`. Диспатчится
`OzonAdReportPoller` при переходе pending-отчёта в state=OK/READY.
Routing: `async_pipeline` (retry 3× 5s/10s/20s).

### `DownloadOzonAdReportHandler` (`src/MarketplaceAds/MessageHandler/DownloadOzonAdReportHandler.php`)

Async-обработчик `DownloadOzonAdReportMessage`. Завершает ингест готового
отчёта:
1. `pendingRepo->findByIdAndCompany(pendingReportId, companyId)` — IDOR-safe
   load; если null или `finalizedAt !== null` → идемпотентный no-op ACK.
2. `OzonAdClient::downloadAndConvertReport()` — скачивает CSV, конвертирует
   в date-keyed результат.
3. За каждый день результата — upsert `AdRawDocument` (новый → `save()`,
   существующий → `updatePayload()`).
4. Bronze: `StorageService::storeBytes()` один раз (один UUID = один физический
   файл), `setFileStorage()` на каждом документе.
5. `em->flush()` — персист + bronze metadata одним запросом.
6. `pendingRepo->markFinalized(OK)` — guard `finalized_at IS NULL` делает
   операцию идемпотентной.
7. `dispatch(ProcessAdRawDocumentMessage)` за каждый документ — строго ПОСЛЕ
   `flush()`, иначе per-document handler может не найти документ в БД.

Политика ошибок:
- `OzonPermanentApiException` (403, missing creds) → `markFinalized(ERROR)`
    + `UnrecoverableMessageHandlingException` (не ретраит).
- Прочие `\Throwable` (5xx, сеть) → rethrow, Messenger ретраит по
  `async_pipeline`-schedule.
- Not-found / already-finalized — не ошибки, ACK.

Zero-docs edge case (step 5): если отчёт приехал пустым (`resultByDate == []`)
и у pending есть `jobId`, handler напрямую вызывает `AdLoadJobFinalizer::tryFinalize`.
Без этого job с нулём документов навечно залип бы в RUNNING: per-document
ProcessAdRawDocumentHandler, который обычно триггерит финализацию, не запустится.
`tryFinalize` идемпотентен (считает processed vs total AdRawDocument), повторные
вызовы безопасны. Для не-пустых отчётов handler НЕ вызывает finalizer — это
ответственность `ProcessAdRawDocumentHandler` (единственный источник правды по
счётчикам).

Поток:
```
OzonAdReportPoller (state=OK)
  └─ dispatch DownloadOzonAdReportMessage ─→ async_pipeline
       └─ DownloadOzonAdReportHandler
            ├─ OzonAdClient::downloadAndConvertReport
            ├─ upsert AdRawDocument per day
            ├─ StorageService::storeBytes (bronze)
            ├─ em->flush
            ├─ pendingRepo->markFinalized(OK)
            ├─ dispatch ProcessAdRawDocumentMessage ─→ async_pipeline
            │    └─ ProcessAdRawDocumentHandler (fan-out per day)
            └─ [if zero docs AND pending.jobId !== null] AdLoadJobFinalizer::tryFinalize
```

### Async-poll pipeline (step 5 redesign, 22.04.2026)

```
Cron OzonAdDailySyncCommand (04:30 daily) → DispatchOzonAdLoadAction
  ↓
LoadOzonAdStatisticsRangeMessage → LoadOzonAdStatisticsRangeHandler
  ↓ (split into ≤62-day chunks)
FetchOzonAdStatisticsMessage (async_ads) → FetchOzonAdStatisticsHandler
  ↓ prepareStatisticsBatches (credentials, campaigns, chunk into ≤10)
  ↓ dispatch one RequestOzonAdBatchMessage per batch
  ↓ markChunkCompleted, incrementLoadedDays
  ↓ [if no batches: AdLoadJobFinalizer::tryFinalize directly]
RequestOzonAdBatchMessage (async_ads) → RequestOzonAdBatchHandler
  ↓ OzonAdClient::requestOneBatch = matchResumableReport OR requestStatistics (POST /statistics)
  ↓ OzonAdPendingReport persisted (state=REQUESTED, nextPollAt=NULL)
[each worker exits in <1s; no intra-handler 429 storm; no 10-min sync block]

### Why one POST per message

Ozon Performance API: max 1 active /api/client/statistics per account.
Any 2nd concurrent request returns HTTP 429 «Превышен лимит активных
запросов (максимум 1)». With async_ads having a single worker and
FIFO Redis transport, dispatching one RequestOzonAdBatchMessage per
batch naturally serializes POSTs with zero intra-handler orchestration.
Previously FetchOzonAdStatisticsHandler called requestStatisticsOnly,
which looped N POSTs back-to-back and reliably hit 429 on the 2nd
batch for companies with >10 active SKU campaigns.

### Ozon rate limit — «max 1 active /statistics request per account»

Ozon measures rate limit by backend UUID-creation slot occupancy
(30–60s per POST), not by concurrent HTTP connections. A single
async_ads worker + FIFO Redis transport serializes our POSTs in
SEQUENCE but not in TIME — worker processes N messages in ~Ns total,
hitting 429 on batches 2..N.

`FetchOzonAdStatisticsHandler` spaces batches at dispatch:

    batch #i → DelayStamp(i × 90_000 ms)

So the worker picks up batch #0 immediately, sits idle, picks up
batch #1 at t=90s, etc. Ozon's slot is free by the time each POST
lands. For N batches, wall-time sync duration ≈ N × 90 seconds.

The `OzonRateLimitException` → reschedule path in
`RequestOzonAdBatchHandler` remains as a safety net: if external
activity on the same Ozon account coincides with our slot, or if
Ozon's slot occupancy exceeds 90s, a 429 is caught and the batch
reschedules with `DelayStamp(60_000)`. `OzonAdClient::authorizedRequest`
distinguishes HTTP 429 from other non-2xx responses and throws
`OzonRateLimitException` (extends `\RuntimeException`).
`RequestOzonAdBatchHandler` catches it and reschedules the same message
via `MessageBusInterface::dispatch(new Envelope($msg), [new DelayStamp(60_000)])`.
The current message is ACK'd (no Messenger retry consumed, no failure
transport).

`RequestOzonAdBatchMessage::$rateLimitAttempts` counts reschedules and
caps them at 10 per batch (10 minutes total per-batch wait). Exceeding
this marks the job failed via `AdLoadJobRepository::markFailed()` and
raises `UnrecoverableMessageHandlingException` — functionally equivalent
to the `OzonPermanentApiException` branch but with a different reason
string.

Параллельно cron */2 * * * *:
app:marketplace-ads:ozon-poll-reports → OzonPollReportsCommand → OzonAdReportPoller::__invoke($companyId)
  ↓ findInFlightByCompany [БД]
  ↓ for each pending_report:
  ↓   GET /statistics/{uuid}  (v1.17 per-UUID polling)
  ↓   if state=OK:    updateStateWithSchedule(OK) + dispatch DownloadOzonAdReportMessage
  ↓   if state=ERROR: markFinalized(ERROR)
  ↓   else:           updateStateWithSchedule(mappedState, nextPollAt)
  ↓   if age>=3h:     markFinalized(ABANDONED)
on state=OK:
  DownloadOzonAdReportMessage (async_pipeline) → DownloadOzonAdReportHandler
    ↓ OzonAdClient::downloadAndConvertReport → CSV → OzonAdRawDataParser (nested-format)
    ↓ upsert AdRawDocument per day + bronze + markFinalized(OK)
    ├─ ProcessAdRawDocumentMessage (async_pipeline) → ProcessAdRawDocumentHandler
    │    ↓ creates AdDocument + AdDocumentLine → AdLoadJobFinalizer.tryFinalize → COMPLETED
    └─ [zero-docs] AdLoadJobFinalizer.tryFinalize → COMPLETED (no fan-out)
```

`OzonAdClient::pollReport()`, `matchResumableReport()`, `POLL_MAX_ATTEMPTS`,
`POLL_INTERVAL_SECONDS`, `POLL_NOT_STARTED_MAX_SECONDS`, `RESUME_MAX_AGE_SECONDS`,
`OzonStatisticsQueueFullException`, `OzonAdClient::fetchAdStatisticsRange()`,
`OzonAdClient::requestStatisticsOnly()` остаются в коде как dead-but-preserved
до отдельного cleanup-PR в ~2 недели после стабилизации step 5 / rate-limit fix.

### `AdRawDocument.raw_payload` — две поддерживаемые формы

`OzonAdRawDataParser` принимает обе формы и возвращает одинаковый список `AdRawEntry`:

- **flat** (legacy — `LoadAdDataCommand`, `ReprocessAdDataCommand`,
  pre-step-4 writers, raw-документы, уже сохранённые в БД до шага 4):
  ```json
  {"rows":[{"campaign_id":"…","campaign_name":"…","sku":"…","spend":"…","views":0,"clicks":0}]}
  ```
- **nested** (current — `DownloadOzonAdReportHandler` после шага 4 async-poll редизайна):
  ```json
  {"campaigns":[{"campaign_id":"…","campaign_name":"…","rows":[{"sku":"…","spend":"…","views":0,"clicks":0}]}]}
  ```

Парсер диспатчится по наличию ключа `campaigns`; для nested-формы поля
`campaign_id` / `campaign_name` пробрасываются из родительского объекта
в каждую row перед общей агрегацией — downstream-код (`ProcessAdRawDocumentAction`)
не знает, какая форма была на входе.

---

## Query — MarketplaceAds

> Read-model агрегаты на DBAL (минуя ORM hydration). Используются напрямую из
> Controllers и не через Facade — это внутренний read-слой модуля.

### `AdEfficiencyQuery` (`src/MarketplaceAds/Infrastructure/Query/AdEfficiencyQuery.php`)
```php
// Страница отчёта «Эффективность рекламы»: SKU × выручка × рекламные затраты × ДРР %.
// Читает marketplace_sales + marketplace_ad_document_lines/marketplace_ad_documents +
// marketplace_listings. Валидация входа (page/pageSize/sortBy/sortDir) внутри метода.
// $sortBy whitelist: 'sku' | 'title' | 'revenue' | 'adSpend' | 'drrPercent' (fallback 'revenue').
// $sortDir: 'asc' | 'desc' (fallback 'desc').
// Денежные значения наружу — decimal-строки (bcmath-compatible).
getPage(
    string $companyId,
    \DateTimeImmutable $from,
    \DateTimeImmutable $to,
    ?string $marketplace,
    int $page,
    int $pageSize,
    string $sortBy = 'revenue',
    string $sortDir = 'desc',
): AdEfficiencyPageDTO
```

**DTO:**
- `AdEfficiencyItemDTO` (`src/MarketplaceAds/Application/DTO/AdEfficiencyItemDTO.php`) —
  строка таблицы: `listingId`, `sku`, `?title`, `marketplace`, `revenue`, `adSpend`, `?drrPercent`.
  `drrPercent = null`, когда `revenue = 0`.
- `AdEfficiencyPageDTO` (`src/MarketplaceAds/Application/DTO/AdEfficiencyPageDTO.php`) —
  `items: list<AdEfficiencyItemDTO>`, `total`, `page`, `pageSize`, `totalRevenue`, `totalAdSpend`,
  `?totalDrrPercent`. Totals считаются по ВСЕМУ набору листингов, не только по странице.
  `totalAdSpend` включает non-attributed РР (висячие `listing_id` в `ad_document_lines` без
  живого листинга в `marketplace_listings`) — для согласованности с
  `/marketplace-analytics/unit-extended` totals (тот считает через
  `MarketplaceAdsFacade::getTotalAdCostForPeriod()`). Сами «висячие» listing_id в `items` не
  появляются — для них нет видимой строки.

### `AdSpendByListingQuery` (`src/MarketplaceAds/Infrastructure/Query/AdSpendByListingQuery.php`)
```php
// РР с разрезом по листингам за период. Используется через MarketplaceAdsFacade
// (getAdSpendByListingForPeriod) для построения строк отчётов другими модулями.
// Семантически = CTE ads_agg из AdEfficiencyQuery, вынесенный в отдельный read-only query.
// В отличие от AdEfficiencyQuery НЕ фильтрует по существованию листинга в marketplace_listings —
// «висячие» listing_id попадают в выдачу (критично для согласованности totals на потребителях).
// Денежные значения наружу — decimal-строки (bcmath-compatible).
// @return array<string, string>  listingId => decimal-string adSpend
getByListingForPeriod(
    string $companyId,
    \DateTimeImmutable $from,
    \DateTimeImmutable $to,
    ?string $marketplace = null,
): array
```

---

## Query — Marketplace

> Read-model на DBAL для страницы продаж и JSON-экспорта. Возвращает `QueryBuilder`
> вместо массива — индексная страница оборачивает его в Pagerfanta-адаптер,
> экспорт делает `executeQuery()->fetchAllAssociative()` напрямую.

### `SalesListQuery` (`src/Marketplace/Infrastructure/Query/SalesListQuery.php`)
```php
// Читает marketplace_sales + marketplace_listings (INNER JOIN по listing_id).
// SELECT покрывает 10 колонок, ORDER BY sale_date DESC.
// Все фильтры применяются через DBAL parameter binding:
//   - companyId    — обязательный (IDOR);
//   - marketplace  — опциональный (значение enum MarketplaceType);
//   - from / to    — опциональный диапазон sale_date, границы ВКЛЮЧИТЕЛЬНЫЕ
//                    (`>=` и `<=`), формат Y-m-d (sale_date в БД — тип DATE).
buildQueryBuilder(
    string $companyId,
    ?string $marketplace,
    ?\DateTimeImmutable $from = null,
    ?\DateTimeImmutable $to = null,
): \Doctrine\DBAL\Query\QueryBuilder
```

## Query — Inventory

### `InventoryStockReportQuery`

- DBAL read-model для `GET /inventory/stocks`;
- обязательный IDOR-фильтр `company_id = :companyId`;
- `source` фильтруется через `MarketplaceType`;
- `mappingStatus` фильтруется через `StockSnapshotMappingStatus`;
- поддерживает фильтры:
  - `snapshotSessionId`;
  - `snapshotAt`;
  - `source`;
  - `search` по `sourceSku` / `sourceOfferId`;
  - `mappingStatus`;
- pagination через Pagerfanta;
- `available_for_sale = quantity - reserved_quantity`;
- `SELECT *` не используется.

**Потребители:**
- `MarketplaceSalesController` (`GET /marketplace/sales`, route `marketplace_sales_index`) —
  оборачивает `QueryBuilder` в `Pagerfanta\Doctrine\DBAL\QueryAdapter`, per_page=50.
  Query-параметры: `marketplace`, `date_from`, `date_to`, `page`. Невалидные/массивные
  значения (`?marketplace[]=foo`, `?date_from=2026-04-31`) читаются через
  `query->all()` + локальные guard-helper'ы и трактуются как «фильтр не задан» —
  graceful-fallback вместо 400/500. Pagerfanta-навигация сохраняет активные
  фильтры через `routeParams` в include `partials/_pagerfanta.html.twig`.
- `SalesJsonExportController` (`GET /marketplace/sales/export.json`, route
  `marketplace_sales_export_json`) — `executeQuery()->fetchAllAssociative()` →
  `JsonResponse` с `Content-Disposition: attachment; filename="marketplace-sales-<Ymd-His>.json"`
  и encoding `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
  Payload: `{exported_at: ATOM, filters: {marketplace, date_from, date_to}, count, sales[]}`.
  Те же три фильтра, та же graceful-fallback-семантика. Endpoint **не** входит в
  публичный OpenAPI — потребляется из Twig обычным `<a href>`, `schema.d.ts` не
  затронут.

---

## Telegram → Cash: контракт интеграции

Telegram создаёт ДДС-транзакции только через цепочку:

`TelegramWebhookController` → `CreateTelegramCashTransactionAction` → `CashFacade::createTransaction()`.

**Telegram externalId:**
- `telegram:{sha256(botId|chatId|messageId)}`
- Технически: `externalId = 'telegram:' . hash('sha256', botId . '|' . chatId . '|' . messageId)`

**Telegram rawData:**
- `source = telegram`
- `update_id`
- `message_id`
- `chat_id`
- `from_id`
- `message_date`
- `text`
- `bot_id_fallback`

Если `chatId`/`messageId` отсутствуют:
- `CashTransaction` не создаётся;
- webhook отвечает `ok`;
- пишется warning.


## Enum — актуальные значения

> Используй **только** эти значения. Не придумывай новые без обновления файла.

### `src/Shared/Enum/AuditLogAction.php`
```php
enum AuditLogAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
```

### `src/Marketplace/Enum/AdvertisingType.php`
```php
enum AdvertisingType: string
{
    case CPC = 'cpc';
    case OTHER = 'other';
    case EXTERNAL = 'external';
}
```

### `src/Inventory/Enum/SnapshotSessionStatus.php`
```php
enum SnapshotSessionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
```

### `src/Inventory/Enum/SnapshotTriggerType.php`
```php
enum SnapshotTriggerType: string
{
    case ScheduledNight = 'scheduled_night';
    case ScheduledDay = 'scheduled_day';
    case Manual = 'manual';
    case Retry = 'retry';
}
```

### `src/Inventory/Enum/StockStatus.php`
```php
enum StockStatus: string
{
    case Available = 'available';
    case InTransitToCustomer = 'in_transit_to_customer';
    case InTransitFromCustomer = 'in_transit_from_customer';
    case OnAcceptance = 'on_acceptance';
    case Defect = 'defect';
    case Blocked = 'blocked';
}
```

Важно: `reserved` не является значением `StockStatus`.

### `src/Inventory/Enum/StockSnapshotMappingStatus.php`
```php
enum StockSnapshotMappingStatus: string
{
    case Unmapped = 'unmapped';
    case Mapped = 'mapped';
    case Ambiguous = 'ambiguous';
}
```

Семантика:
- `unmapped` — по `sourceSku` не найден `MarketplaceListing`;
- `mapped` — найден ровно один `MarketplaceListing`;
- `ambiguous` — найдено больше одного `MarketplaceListing`, автоматически не выбираем.

### `src/Inventory/Enum/LocationType.php`
```php
enum LocationType: string
{
    case MpWarehouse = 'mp_warehouse';
    case MpAcceptance = 'mp_acceptance';
    case MpInTransitToCustomer = 'mp_in_transit_to_customer';
    case MpInTransitFromCustomer = 'mp_in_transit_from_customer';
}
```


### `src/Marketplace/Enum/FinancialReportSyncStatus.php`
```php
enum FinancialReportSyncStatus: string
{
    case QUEUED = 'queued';
    case LOADING = 'loading';
    case RAW_LOADED = 'raw_loaded';
    case PROCESSING = 'processing';
    case SUCCESS = 'success';
    case EMPTY = 'empty';
    case FAILED = 'failed';
    case FAILED_FINAL = 'failed_final';
    case AUTH_FAILED = 'auth_failed';
    case CONFLICT = 'conflict';
}
```

### `src/Marketplace/Enum/FinancialReportSyncMode.php`
```php
enum FinancialReportSyncMode: string
{
    case INITIAL = 'initial';
    case DAILY = 'daily';
    case REFRESH_14D = 'refresh_14d';
    case MISSING = 'missing';
    case MANUAL = 'manual';
}
```

### `src/Marketplace/Enum/OrderStatus.php`
```php
enum OrderStatus: string
{
    case ORDERED = 'ordered';
    case DELIVERED = 'delivered';
    case RETURNED = 'returned';
    case CANCELLED = 'cancelled';
}
```

### `src/MarketplaceAnalytics/Enum/UnitEconomyCostType.php`
```php
enum UnitEconomyCostType: string
{
    case LOGISTICS_TO         = 'logistics_to';
    case LOGISTICS_BACK       = 'logistics_back';
    case STORAGE              = 'storage';
    case ADVERTISING_CPC      = 'advertising_cpc';
    case ADVERTISING_OTHER    = 'advertising_other';
    case ADVERTISING_EXTERNAL = 'advertising_external';
    case COMMISSION           = 'commission';
    case ACQUIRING            = 'acquiring';   // Эквайринг
    case PENALTIES            = 'penalties';   // Штрафы
    case ACCEPTANCE           = 'acceptance';  // Приемка
    case OTHER                = 'other';

    public function getLabel(): string; // человекочитаемое название
    public function isAdvertising(): bool; // true для ADVERTISING_CPC, ADVERTISING_OTHER, ADVERTISING_EXTERNAL
}
```

### `src/MarketplaceAnalytics/Enum/DataQualityFlag.php`
```php
enum DataQualityFlag: string
{
    case COST_PRICE_MISSING = 'cost_price_missing';
    case API_ADVERTISING_MISSING = 'api_advertising_missing';
    case API_STORAGE_MISSING = 'api_storage_missing';
    case API_ORDERS_MISSING = 'api_orders_missing';
    case DATA_DELAYED = 'data_delayed';
}
```

### `src/MarketplaceAnalytics/Enum/SnapshotRecalcScope.php`
```php
enum SnapshotRecalcScope: string
{
    case SINGLE_DAY = 'single_day';
    case DATE_RANGE = 'date_range';
}
```

### `src/Marketplace/Enum/ProcessingKind.php`
```php
enum ProcessingKind: string
{
    case SALES   = 'sales';
    case RETURNS = 'returns';
    case COSTS   = 'costs';

    public function getLabel(): string; // Продажи / Возвраты / Затраты
}
```

### `src/Marketplace/Enum/PipelineStatus.php`
```php
enum PipelineStatus: string
{
    case PENDING   = 'pending';
    case RUNNING   = 'running';
    case COMPLETED = 'completed';
    case FAILED    = 'failed';

    public function getLabel(): string;     // Ожидает / Выполняется / Завершён / Ошибка
    public function isTerminal(): bool;     // true для COMPLETED, FAILED
    public function isRunning(): bool;      // true для RUNNING
}
```

### `src/Marketplace/Enum/ReconciliationSessionStatus.php`
```php
enum ReconciliationSessionStatus: string
{
    case PENDING   = 'pending';
    case COMPLETED = 'completed';
    case FAILED    = 'failed';

    public function getLabel(): string;    // Ожидает / Завершена / Ошибка
    public function isPending(): bool;     // true для PENDING
    public function isTerminal(): bool;    // true для COMPLETED, FAILED
}
```

### `src/Marketplace/Enum/OzonTransactionTotalsCheckStatus.php`
```php
enum OzonTransactionTotalsCheckStatus: string
{
    case OK      = 'ok';
    case WARNING = 'warning';
    case FAILED  = 'failed';
    case SKIPPED = 'skipped';

    public function getLabel(): string;     // Успешно / Предупреждение / Ошибка / Пропущено
    public function isSuccessful(): bool;   // true только для OK
    public function isBlocking(): bool;     // true только для FAILED
}
```

### `src/Marketplace/Enum/PipelineStep.php`
```php
enum PipelineStep: string
{
    case SALES   = 'sales';
    case RETURNS = 'returns';
    case COSTS   = 'costs';

    public function getLabel(): string; // Продажи / Возвраты / Затраты
}
```

### `src/Marketplace/Enum/PipelineTrigger.php`
```php
enum PipelineTrigger: string
{
    case AUTO   = 'auto';
    case MANUAL = 'manual';

    public function getLabel(): string; // Автоматически / Вручную
}
```

### `src/Marketplace/Enum/MarketplaceCostOperationType.php`
```php
enum MarketplaceCostOperationType: string
{
    case CHARGE = 'charge';   // Начисление
    case STORNO = 'storno';   // Сторно

    public function getDisplayName(): string; // Начисление / Сторно
}
```
> Явная классификация операции затраты. Заменяет определение типа по знаку `amount`.

### `src/Marketplace/Enum/MarketplaceConnectionType.php`
```php
enum MarketplaceConnectionType: string
{
    case SELLER      = 'seller';      // Основное (Seller API: финансы, продажи, остатки)
    case PERFORMANCE = 'performance'; // Реклама (Performance API: OAuth2 Bearer)

    public function getDisplayName(): string; // Основное / Реклама (Performance)
}
```
> Тип подключения к маркетплейсу. У Ozon два независимых API: `api-seller.ozon.ru` (статический Client-Id + Api-Key) и `api-performance.ozon.ru` (OAuth2 client_id + client_secret). Позволяет одной компании иметь два подключения к одному маркетплейсу.

### `src/MarketplaceAds/Enum/AdRawDocumentStatus.php`
```php
enum AdRawDocumentStatus: string
{
    case DRAFT     = 'draft';
    case PROCESSED = 'processed';
    case FAILED    = 'failed';

    public function getLabel(): string;   // Черновик / Обработан / Ошибка
    public function isDraft(): bool;      // true для DRAFT
    public function isTerminal(): bool;   // true для PROCESSED, FAILED
}
```

### `src/MarketplaceAds/Enum/AdLoadJobStatus.php`
```php
enum AdLoadJobStatus: string
{
    case PENDING   = 'pending';
    case RUNNING   = 'running';
    case COMPLETED = 'completed';
    case FAILED    = 'failed';

    public function isTerminal(): bool; // true для COMPLETED, FAILED
}
```

### `src/MarketplaceAds/Enum/OzonAdPendingReportState.php`
```php
// Canonical state для записей marketplace_ad_pending_reports.
// Реализовано как final class с константами, а не PHP enum: исходные raw-значения
// Ozon API (NOT_STARTED / IN_PROGRESS / OK / READY / ERROR / CANCELLED / NOT_FOUND)
// приходят в state как есть, и clean-mapping «raw → canonical» выполняется в
// OzonAdClient::pollReport(). Canonical набор:
final class OzonAdPendingReportState
{
    public const REQUESTED   = 'REQUESTED';
    public const NOT_STARTED = 'NOT_STARTED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const OK          = 'OK';
    public const ERROR       = 'ERROR';
    public const ABANDONED   = 'ABANDONED';

    // Состояния, в которых запись ещё не финализирована (finalized_at IS NULL).
    public const IN_FLIGHT_STATES = ['REQUESTED', 'NOT_STARTED', 'IN_PROGRESS'];

    // Терминальные: markFinalized() принимает только эти.
    public const TERMINAL_STATES = ['OK', 'ERROR', 'ABANDONED'];

    public static function isTerminal(string $state): bool;
}
```

> **Остальные Enum** (ProductStatus, TransactionType, MarketplaceType и др.) добавлять сюда по мере реализации.
> Не угадывать значения — спрашивать или смотреть в исходниках.

---

## Exceptions — MarketplaceAds

### `src/MarketplaceAds/Infrastructure/Api/Ozon/OzonPermanentApiException.php`
Permanent-ошибка Ozon API (403, missing credentials, отсутствие scope «Продвижение»).
Бросается из `OzonAdClient`, ловится в `FetchOzonAdStatisticsHandler` →
`markFailed` + `UnrecoverableMessageHandlingException`. Messenger не ретраит.

### `src/MarketplaceAds/Exception/OzonStatisticsQueueFullException.php`
```php
final class OzonStatisticsQueueFullException extends \RuntimeException
{
    public function __construct(string $reportUuid, int $waitedSeconds);
    public function getReportUuid(): string;
    public function getWaitedSeconds(): int;
}
```
> Ozon Performance API перегружен: отчёт застрял в `NOT_STARTED` дольше 5 минут
> (`OzonAdClient::POLL_NOT_STARTED_MAX_SECONDS`). Отдельный тип от
> `OzonPermanentApiException`, потому что причина временная — стоит повторить
> загрузку вручную позже (как правило, на следующий день). `FetchOzonAdStatisticsHandler`
> ловит, маркирует job failed с понятным пользовательским сообщением и оборачивает
> в `UnrecoverableMessageHandlingException`, чтобы Messenger не ретраил в пределах
> минут — ретрай имеет смысл только после нормализации очереди Ozon.

---

## Shared-сервисы (доступны во всех модулях)

```php
// Текущая компания из сессии — обязателен в каждом контроллере
App\Shared\Service\ActiveCompanyService::getActiveCompany(): Company

// Структурированное логирование (каналы: import.bank1c, recalc, deprecation)
App\Shared\Service\AppLogger

// Шифрование sensitive-полей (токены, ключи API)
App\Shared\Service\SodiumFieldEncryptionService

// Ротация ключей шифрования
App\Shared\Service\SecretRotationService
```

---

## Tagged Services — текущие группы

| Тег | Назначение |
|---|---|
| `app.marketplace.cost_calculator` | Калькуляторы WB-затрат (priority в services.yaml) |
| `app.marketplace.adapter` | Адаптеры маркетплейсов (WB, Ozon) |
| `app.balance.value_provider` | Провайдеры значений баланса |
| `marketplace.data_source` | Источники данных для закрытия месяца |
| `app.notification.sender` | Каналы отправки уведомлений |
| `marketplace_ads.raw_data_parser` | Парсеры raw-данных рекламных отчётов (Ozon, WB) |
| `marketplace_ads.platform_client` | API-клиенты рекламных площадок. `OzonAdClient` реализован для работы с Ozon Performance API (OAuth2, async-репорты, CSV). `WildberriesAdClient` — TODO-stub |

---

## Firewall и роли безопасности

```
main        → form_login для пользователей
admin       → отдельный firewall для /admin
public_api  → stateless, анонимный /api/public/
```

Иерархия ролей: `ROLE_USER → ROLE_COMPANY_USER → ROLE_COMPANY_OWNER`
Admin-роли: `ROLE_ADMIN → ROLE_SUPER_ADMIN`

Публичное API: токен через `?token=...` (ReportApiKey)
Rate limiting: `reports_api` — 60 req/мин · `registration` — 5 req/10 мин

---

## API Documentation

**URL:** `https://app.vashfindir.ru/api/doc` (требует авторизации `ROLE_USER`)
**Spec JSON:** `https://app.vashfindir.ru/api/doc.json`

**Инструмент:** `nelmio/api-doc-bundle` (OpenAPI 3.x)

### Coverage

| Статус | Эндпоинтов | Примечание |
|---|---|---|
| Документировано | 5 | Health (live, ready) + MarketplaceAnalytics (create, snapshots list, snapshot show) |
| Ожидает документации | ~47 | См. план по модулям |
| Исключено (debug/admin) | ~22 | Не публикуются в OpenAPI |

### Задокументированные эндпоинты

| Модуль | Эндпоинт | PR |
|---|---|---|
| Analytics | GET /api/health/live | PR-1 |
| Analytics | GET /api/health/ready | PR-1 |
| MarketplaceAnalytics | POST /api/marketplaceanalytics | PR-2 |
| MarketplaceAnalytics | GET /api/marketplace-analytics/snapshots | PR-2 |
| MarketplaceAnalytics | GET /api/marketplace-analytics/snapshots/{id} | PR-2 |

### Правила документирования

- Атрибуты `#[OA\*]` ставятся над методом контроллера, логика метода не меняется
- Debug- и admin-эндпоинты в OpenAPI не публикуются (см. `path_patterns` в `config/packages/nelmio_api_doc.yaml`)
- Формат ошибок: целевой — `Problem` (RFC 7807), существующие legacy-форматы документируются как есть
- Request/Response DTO описываются `#[OA\Schema]` рядом с классом DTO
- Паттерн документирования — см. `PATTERNS.md` раздел 19

### Типы для фронтенда

**Генератор:** `openapi-typescript` (devDep в `site/package.json`)
**Клиент:** `openapi-fetch` (runtime-dep)
**Путь:** `site/assets/api/`

- `schema.d.ts` — автогенерируется, лежит в git
- `client.ts` — типизированный клиент `openapi-fetch`
- `README.md` — инструкции для разработчиков

**Как регенерировать:** `make api-types` (экспортирует спеку через `bin/console nelmio:apidoc:dump` и запускает `openapi-typescript`)

**CI:** job `api-types-check` в `.github/workflows/deploy.yml` проверяет синхронизацию `schema.d.ts` на каждом PR.

**Демо-компонент:** `site/assets/react/marketplace-analytics/SnapshotListDemo.tsx` — референс использования типизированного клиента.

---

## Маршруты — конвенция

```
GET  /{module}/{resource}              — список
GET  /{module}/{resource}/new          — форма создания
GET  /{module}/{resource}/{id}         — просмотр
GET  /{module}/{resource}/{id}/edit    — редактирование

GET  /api/{module}/{resource}          — API список (авторизованный)
POST /api/{module}/{resource}          — API создание
GET  /api/public/{resource}?token=...  — публичный API
```

### Inventory routes

- `GET /inventory/snapshots` — список raw-загрузок;
- `POST /inventory/snapshots/request` — ручной запуск raw-загрузки;
- `GET /inventory/snapshots/{id}/json` — raw JSON по session;
- `GET /inventory/stocks` — UI-отчёт по нормализованным остаткам.

## Inventory — Ozon stock normalization

Первый этап Inventory для Ozon:

```text
SyncOzonInventorySnapshotMessage (async_sync)
↓
SyncOzonInventorySnapshotHandler
↓ Ozon Seller API POST /v4/product/info/stocks
InventoryRawSnapshot
↓ completed session
NormalizeInventorySnapshotMessage (async_pipeline)
↓
NormalizeInventorySnapshotHandler
↓
NormalizeInventorySnapshotAction
↓
OzonProductStocksRawNormalizer
↓
StockSnapshot
↓
GET /inventory/stocks
```

Семантика Ozon:
- `stocks[].sku` → `StockSnapshot.sourceSku`;
- `item.offer_id` → `StockSnapshot.sourceOfferId`;
- `stocks[].type` → `StockSnapshot.fulfillmentType`;
- `stocks[].present` → `StockSnapshot.quantity`;
- `stocks[].reserved` → `StockSnapshot.reservedQuantity`;
- `status = StockStatus::Available`;
- `source = MarketplaceType::OZON`;
- `availableForSale = quantity - reservedQuantity` считается в Query/UI.

Маппинг:
- `sourceSku` ищется в MarketplaceListing через MarketplaceFacade;
- 0 листингов → `StockSnapshotMappingStatus::Unmapped`;
- 1 листинг → `StockSnapshotMappingStatus::Mapped`;
- >1 листинга → `StockSnapshotMappingStatus::Ambiguous`;
- при orphan listing `productId = null`, но `mappingStatus = mapped`.

Ограничение этапа:
- не покрывает остатки по каждому складу Ozon;
- не покрывает товары в пути к клиенту;
- не покрывает возвраты от клиента;
- эти потоки добавляются отдельными загрузками/normalizer-ами.

## Messenger routing — Inventory

- `App\Inventory\Message\SyncOzonInventorySnapshotMessage` → `async_sync`;
- `App\Inventory\Message\NormalizeInventorySnapshotMessage` → `async_pipeline`.

Объяснение:
- `SyncOzonInventorySnapshotMessage` выполняет внешний HTTP-запрос к Ozon;
- `NormalizeInventorySnapshotMessage` выполняет локальную DB-heavy обработку raw JSON.

## Messenger routing — Marketplace (WB financial report day)

- `App\Marketplace\Message\SyncWbFinancialReportDayMessage` → `async_sync`.

Назначение:
- загрузка WB financial report за один `businessDate` (date-based sync для initial / refresh_14d / missing сценариев).

Payload (только scalar):
- `companyId` — `string` UUID;
- `connectionId` — `string` UUID;
- `businessDate` — `string` в формате `YYYY-MM-DD`;
- `mode` — `string`, значение `FinancialReportSyncMode`;
- `forceRefresh` — `bool`.

Ограничения payload:
- message не содержит `apiKey`, `token`, `connection` entity или любые другие ORM-объекты.

---

## Redis — три назначения

| Назначение | DSN |
|---|---|
| Сессии | `redis://site-redis:6379` (prefix `sess_`, TTL 14 дней) |
| Messenger | `redis://site-redis:6379/messages` (transport `async`) |
| Lock | `redis://site-redis:6379?prefix=symfony-locks` |

---

## Конфигурация — где что лежит

```
config/
├── packages/
│   ├── doctrine.yaml      ← маппинг Entity по модулям
│   ├── messenger.yaml     ← routing Messages по транспортам
│   ├── security.yaml      ← firewall, роли, rate limiting
│   ├── monolog.yaml       ← каналы логирования
│   └── test/              ← переопределения для тестов
├── routes.yaml            ← маршруты по модулям
├── services.yaml          ← tagged services, interface bindings
└── pnl_template.yaml      ← бизнес-конфигурация шаблона PnL
```

### При добавлении нового модуля — обязательно прописать:

```yaml
# routes.yaml
newmodule_controllers:
    resource:
        path: ../src/NewModule/Controller/
        namespace: App\NewModule\Controller
    type: attribute
```

```yaml
# doctrine.yaml
NewModule:
    type: attribute
    is_bundle: false
    dir: '%kernel.project_dir%/src/NewModule/Entity'
    prefix: 'App\NewModule\Entity'
    alias: NewModule
```

```yaml
# messenger.yaml (если есть async Messages)
App\NewModule\Message\SomeMessage: async
```

```yaml
# twig.yaml (если есть шаблоны)
paths:
    '%kernel.project_dir%/templates/newmodule': NewModule
```

---

## Рефакторинг legacy — приоритеты

**Приоритет 1 (высокий):**
- `src/Entity/PLCategory` → `Finance/Entity/`
- `src/Entity/ProjectDirection` → нужный модуль
- `src/Entity/Counterparty` → `Company/Entity/`
- `src/Entity/Document`, `DocumentOperation` → `Cash/Entity/`
- `src/Repository/` → в соответствующие `{Module}/Repository/`
- `src/Service/` → в `{Module}/Application/` или `{Module}/Domain/Service/`

**Приоритет 2 (средний):**
- Устранить прямые импорты `App\Entity\PLCategory` из `Marketplace/Controller/` → заменить на Facade
- Устранить `App\Repository\ProjectDirectionRepository` из `Marketplace/` → заменить на Facade

---

## Решения принятых в Projects-чатах

> Перенесено в раздел [ADR](#adr--architecture-decision-records) ниже.
> Формат: дата · модуль · что решили · почему.

---


---

## Cron-задачи

> Все cron-команды: `docker/cron/app.cron` (supercronic), флаги `--no-interaction --quiet`.

| Команда | Расписание | Назначение |
|---|---|---|
| `app:marketplace-ads:scheduler` | `* * * * *` | Берёт один PLANNED batch → POST `/statistics` → IN_FLIGHT |
| `app:marketplace-ads:poller` | `* * * * *` + offset 30s | Обрабатывает все IN_FLIGHT: poll + download + финализация |
| `app:marketplace-ads:finalizer` | `* * * * *` | RUNNING jobs → COMPLETED / FAILED / PARTIAL_SUCCESS |
| `app:marketplace-ads:ozon-poll-reports` | `*/2 * * * *` | Legacy Messenger-pipeline: per-UUID polling (оставлен до Task-11.9b) |
| `app:marketplace:daily-sync` | `04:30 daily` | Диспатч загрузки данных по активным подключениям |
| `app:inventory:ozon-daily-sync` | `04:05 daily` | Диспатч загрузки Ozon Inventory snapshot по активным Ozon SELLER подключениям |

**Правила для новых cron-команд:**
- Класс в `src/{Module}/Command/`, `final class`, `#[AsCommand]`
- `LockableTrait` обязателен если команда может идти дольше интервала запуска
- Нет `Request`/`Session`/`Security` — CLI-контекст, companyId из аргумента/итерации по БД
- Per-item try/catch: сбой одной компании / одной записи не прерывает весь запуск
- Exit code: `Command::SUCCESS` / `Command::FAILURE`

---

## Чувствительные данные — шифрование

Сервис: `App\Shared\Service\SodiumFieldEncryptionService`

**Что шифруется обязательно:** API-ключи маркетплейсов, OAuth client_secret, токены банков.

```php
// Шифрование перед сохранением (в Action или Entity-сеттере)
$encrypted = $this->encryption->encrypt($plaintext);
$connection->setApiKey($encrypted);

// Расшифровка при использовании (в Infrastructure/Api-клиенте)
$apiKey = $this->encryption->decrypt($connection->getApiKey());
```

**Правила:**
- Шифровать в Action до `flush()`, не в Controller
- Расшифровывать только в Infrastructure-слое (API-клиент), не в Controller / Facade
- Не логировать plaintext — ни в DEBUG, ни в Sentry контексте
- Ротация ключей — через `SecretRotationService`, не вручную

**Как добавить зашифрованное поле в новую Entity:**
1. Хранить как `string` в БД (не отдельный тип)
2. Getter возвращает зашифрованную строку — расшифровка на стороне вызывающего
3. Добавить в `ARCHITECTURE.md` в список чувствительных полей

---

## Changelog

| Версия | Дата | Что изменилось |
|---|---|---|
| 1.47 | 2026-05-11 | Inventory: задокументирован первый этап Ozon stock normalization — raw `/v4/product/info/stocks` → `StockSnapshot`, `reservedQuantity`, `StockSnapshotMappingStatus`, async normalization и UI `/inventory/stocks` |
| 1.46 | 2026-05-11 | Cash/Telegram: добавлен публичный контракт `CashFacade::createTransaction()` и зафиксировано идемпотентное создание Telegram-транзакций через `importSource`/`externalId` |
| 1.45 | 2026-05-10 | `MarketplaceFacade::getActiveOzonSellerConnections()` — безопасный публичный контракт без секретов |
| 1.44 | 2026-04-28 | `MarketplaceFacade::resolveListingsToProducts()` — batch резолв listingId→productId для Inventory |
| 1.43 | 2026-04-27 | revert: откат soft-mode в `CloseMonthStageAction`, preflight снова строгий |
| 1.27 | 2026-04-23 | MarketplaceAds Task-11.9a: cron-driven pipeline включён, guard период > 62 дней → `DomainException` |
| 1.26 | 2026-04-23 | MarketplaceAds Task-11.8: `AdScheduledBatchDownloadController` + `batchStats` в list API |
| 1.25 | 2026-04-23 | MarketplaceAds Task-11.7: `AdJobFinalizerCommand`; `AdLoadJobStatus::PARTIAL_SUCCESS` |
| 1.24 | 2026-04-23 | MarketplaceAds Task-11.6: `AdBatchPollerCommand`; `OzonReportExtensionDetector` |
| 1.23 | 2026-04-23 | MarketplaceAds Task-11.5: `AdBatchSchedulerCommand`; FOR UPDATE SKIP LOCKED; 429 backoff |
| 1.22 | 2026-04-23 | MarketplaceAds Task-11.3: `AdBatchPlanner`; BATCH_SIZE=10, SPACING=120s |
| 1.21 | 2026-04-23 | MarketplaceAds Task-11.2 fix: IDOR-guard в `AdScheduledBatchRepository` |
| 1.20 | 2026-04-23 | MarketplaceAds Task-11.2: Entity `AdScheduledBatch` + Repository |
| 1.11 | 2026-04-19 | MarketplaceAds: `AdLoadJob`, `AdChunkProgress`, `LoadOzonAdStatisticsRangeMessage` |

---

## ADR — Architecture Decision Records

> Ключевые решения принятые в Projects-чатах. Дата · модуль · что решили · почему.

| Дата | Область | Решение | Причина |
|---|---|---|---|
| 2026-03-28 | Infrastructure | Redis: БД0=сессии, `/messages`=Messenger, `prefix=symfony-locks`=Lock | Изоляция назначений, один инстанс |
| 2026-03-28 | Infrastructure | Messenger worker: `--time-limit=3600`, `restart: always` | Утечки памяти при долгих воркерах |
| 2026-04-23 | MarketplaceAds | Ozon batch: один POST на один `RequestOzonAdBatchMessage` | Ozon лимит «1 активная выгрузка на аккаунт», FIFO Redis сериализует |
| 2026-04-23 | MarketplaceAds | `FOR UPDATE SKIP LOCKED` в `findNextPlanned()` | Защита от race condition при параллельных cron-тиках |
| 2026-04-23 | MarketplaceAds | `pollOneReport()` вместо `/statistics/list` | Инцидент 23.04.2026: list возвращал total=0 при реальных OK отчётах |
| 2026-04-27 | Marketplace | Откат soft-mode в `CloseMonthStageAction` | Preflight должен быть строгим; soft-режим создавал непредсказуемые результаты |
| 2026-05-11 | Inventory | `present` хранится как `quantity`, `reserved` как `reservedQuantity`, без `StockStatus::Reserved` | `reserved` — количественная компонента текущего остатка, а не отдельное физическое состояние товара |
| 2026-05-11 | Inventory | Нормализация raw snapshot запускается через `async_pipeline` после completed raw-загрузки | Raw-загрузка = внешний HTTP, нормализация = локальная DB-heavy обработка |
| 2026-05-11 | Inventory | Маппинг Inventory → Marketplace идёт через MarketplaceFacade по `sourceSku` | Соблюдение границ модулей и запрет прямого импорта Marketplace repository/service |
