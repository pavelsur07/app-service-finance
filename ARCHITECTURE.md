# ARCHITECTURE.md — VashFinDir

> **Живой документ.** Обновляется после каждого нового модуля или изменения публичного контракта.
> Читается: Claude Code (через CLAUDE.md) и Claude.ai Projects (через Knowledge).
> Версия: 1.47 / 2026-05-10

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
| `UnitEconomyCostMapping` | MarketplaceAnalytics | `string $companyId` ✅ |
| `ListingDailySnapshot` | MarketplaceAnalytics | `string $companyId` ✅ |
| `AdRawDocument` | MarketplaceAds | `string $companyId` ✅ |
| `AdDocument` | MarketplaceAds | `string $companyId` ✅ |
| `AdDocumentLine` | MarketplaceAds | `string $companyId` ✅ |
| `AdLoadJob` | MarketplaceAds | `string $companyId` ✅ |
| `AdChunkProgress` | MarketplaceAds | через `jobId` (IDOR через AdLoadJob) |
| `OzonAdPendingReport` | MarketplaceAds | `string $companyId` ✅ |
| `AdScheduledBatch` | MarketplaceAds | `string $companyId` ✅ |
| `ProductImport` | Catalog | `string $companyId` ✅ |
| `ProductBarcode` | Catalog | `string $companyId` ✅ |
| `ProductPurchasePrice` | Catalog | `string $companyId` ✅ |
| `AuditLog` | Shared | `string $companyId` ✅ |
| `CashTransaction`, `MoneyAccount` и др. | Cash | `Company $company` (legacy) |
| `Deal`, `ChargeType` | Deals | `Company $company` (legacy) |
| `PLCategory`, `Document` и др. | legacy `src/Entity/` | `Company $company` (legacy) |

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

// Пакетный резолв listingId → productId|null. Доступен как публичный
// контракт для Inventory-модуля и других кросс-модульных сценариев.
// IDOR-защита через WHERE company_id, чужие листинги отсутствуют в результате. Для orphan-
// листингов (product = null) возвращается null. Limit 5000 listingIds за вызов.
// @param  array<string>             $listingIds
// @return array<string, string|null> map listingId → productId|null
resolveListingsToProducts(string $companyId, array $listingIds): array
```

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

## Inventory: Ozon Inventory Snapshot Pipeline

### Границы модулей Inventory ↔ Marketplace

- Inventory получает список активных Ozon SELLER-подключений только через публичный контракт `MarketplaceFacade::getActiveOzonSellerConnections(?string $companyId = null): array`.
- Метод валидирует `companyId` как UUID (если фильтр передан) и фильтрует подключения по трём условиям: `is_active=true`, `marketplace=ozon`, `connection_type=seller`.
- Возвращаются только безопасные поля: `connectionId`, `companyId`, `marketplace`, `connectionType`, `clientId` (если присутствует).
- Метод **не** возвращает `apiKey`, `clientSecret`, `credentials` и `settings` с секретами.
- Credentials конкретного подключения handler получает через публичный `MarketplaceFacade`-контракт; Inventory не импортирует Repository/Query/Infrastructure из Marketplace напрямую.

### Snapshot sessions (`InventorySnapshotSession`)

- `InventorySnapshotSession` — история запусков загрузки остатков по компании.
- Сессия создаётся при ручном запуске из Inventory UI или cron-запуске.
- Active-guard: `companyId` + `source=Ozon` + `status in (pending, in_progress)`.
- Terminal statuses: `completed`, `partial`, `failed`.
- Ключевые методы `InventorySnapshotSessionRepository`:
  - `findLatestActiveByCompanyAndSource(...)`
  - `findByIdAndCompany(...)`
- `findByIdAndCompany(snapshotSessionId, companyId)` используется в handler для IDOR-safe загрузки сессии.
- Отдельная колонка `connection_id` в `InventorySnapshotSession` не добавлялась.

### Raw snapshots (`InventoryRawSnapshot`)

- `InventoryRawSnapshot` хранит raw-response Ozon Seller API без нормализации.
- `connectionId` сохраняется в JSON `requestParams` рядом с прочими metadata; отдельной колонки `connection_id` нет.
- `requestParams` содержит: `connectionId`, `marketplace`, `page`, `last_id` (если был), `limit`, `requestedAt`, `correlationId`.
- `responseBody` хранит raw payload ответа.
- `pageNumber` используется для постраничного сохранения страниц.

### Inventory API client (`site/src/Inventory/Infrastructure/Api/Ozon/OzonInventoryClient.php`)

- Клиент реализован внутри модуля Inventory.
- Клиент не использует `Marketplace\Infrastructure\Api\Ozon\OzonFetcher`.
- Используется endpoint Ozon Seller API: `POST /v4/product/info/stocks`.
- Credentials передаются параметрами метода; клиент их не хранит.
- HTTP request:
  - Header `Client-Id`
  - Header `Api-Key`
  - `filter.visibility = ALL`
  - `limit`
  - `last_id` передаётся только для последующих страниц, когда курсор уже получен из предыдущего ответа.
- На первой странице `last_id=null` не отправляется (поле отсутствует в JSON body).
- Ответ возвращается как raw DTO `OzonInventoryResponse`.
- Клиент не работает с `EntityManager`, не делает retry/sleep.
- Ошибки:
  - `400` → `OzonInventoryApiException`
  - `401/403` → `OzonInventoryApiException`
  - `429` → `OzonInventoryRateLimitException`
  - `5xx`/network → `RuntimeException`
  - invalid JSON → `OzonInventoryApiException`

### Messenger: message + handler

- Message: `site/src/Inventory/Message/SyncOzonInventorySnapshotMessage.php`.
- Payload только scalar: `companyId`, `connectionId`, `snapshotSessionId`, `triggerType`.
- Routing: `async_sync` (в handler есть внешний HTTP-вызов к Ozon Seller API).
- `async_pipeline` для этого сообщения не используется.

Handler `site/src/Inventory/MessageHandler/SyncOzonInventorySnapshotHandler.php`:
- работает в CLI/Messenger context;
- не использует `Request`/`Session`/`Security`;
- загружает session через `findByIdAndCompany(snapshotSessionId, companyId)`;
- terminal-session обрабатывает как no-op;
- получает credentials через `MarketplaceFacade`;
- делает `markInProgress` перед HTTP-загрузкой;
- сохраняет raw-страницы постранично;
- делает `flush` после каждой страницы;
- success → `markCompleted`;
- ошибка до первой страницы → `markFailed`;
- ошибка после сохранённых страниц → `markPartial`;
- rate-limit до первой страницы → `markFailed`;
- rate-limit после сохранённых страниц → `markPartial`;
- после выставления terminal-статуса не rethrow-ит ошибку (без ложных retry).

### Application action + result DTO

`site/src/Inventory/Application/RequestOzonInventorySnapshotAction.php`:
- используется и UI, и cron-командой;
- получает active Ozon SELLER connections через `MarketplaceFacade`;
- фильтрует/валидирует `connectionId`;
- если нет active connections — возвращает result без создания session;
- если уже есть active session — не создаёт дубль;
- создаёт `InventorySnapshotSession`;
- делает `flush` session до dispatch;
- dispatch `SyncOzonInventorySnapshotMessage` на каждое подключение;
- если все dispatch завершились ошибкой — переводит session в `failed`;
- Action не делает HTTP-запросов к Ozon.

DTO `site/src/Inventory/Application/DTO/OzonInventorySnapshotRequestResult.php` содержит:
- `queuedCount`
- `skippedCount`
- `hasConnections`
- `hasActiveSession`
- `messages`

### Inventory UI: routes/controllers/template

GET `/inventory/snapshots` (`inventory_snapshots_index`):
- `site/src/Inventory/Controller/SnapshotIndexController.php`
- `site/templates/inventory/snapshots/index.html.twig`
- UI принадлежит модулю Inventory (не `Marketplace\Controller\Inventory`, не `templates/marketplace/inventory`).
- Показывает список загрузок active company.
- Данные списка: `InventorySnapshotSessionListQuery`.
- Пагинация: Pagerfanta, `perPage = 30`.
- Колонки: `Дата`, `Маркетплейс`, `Статус`.
- Есть empty state.
- Есть форма «Получить остатки» с CSRF.

POST `/inventory/snapshots/request` (`inventory_snapshots_request`):
- `site/src/Inventory/Controller/SnapshotRequestController.php`
- требует `ROLE_COMPANY_OWNER`;
- проверяет CSRF;
- вызывает `RequestOzonInventorySnapshotAction` с `triggerType=Manual`;
- не делает HTTP-запрос к Ozon;
- redirect на `inventory_snapshots_index`;
- flash:
  - `success`: задача запущена;
  - `warning`: нет active Ozon SELLER connection;
  - `warning`: синхронизация уже выполняется;
  - `danger`: ошибка запуска / неверный CSRF.

### List query

`site/src/Inventory/Infrastructure/Query/InventorySnapshotSessionListQuery.php`:
- read-model для списка загрузок;
- фильтр строго по `companyId`;
- сортировка: `created_at DESC`, `id DESC`;
- явный `SELECT` без raw payload;
- `getPage()` возвращает `Pagerfanta`;
- `perPage` default = `30`;
- `page` нормализуется минимум до `1`;
- `perPage` ограничен.

### Cron orchestration

`site/src/Inventory/Command/OzonInventoryDailySyncCommand.php`
- command: `app:inventory:ozon-daily-sync`
- использует `LockableTrait`;
- получает active Ozon SELLER connections через `MarketplaceFacade`;
- группирует подключения по `companyId`;
- вызывает `RequestOzonInventorySnapshotAction`;
- `triggerType = ScheduledNight`;
- не делает HTTP-запрос к Ozon;
- не зависит от `OzonInventoryClient`;
- пишет в output: start, active connections count, queued count, skipped count, errors count, finish;
- ошибка одной company не валит весь запуск;
- orchestration-failure возвращает `FAILURE`.

Cron entry (`docker/cron/app.cron`):

`5 4 * * * cd /app && php bin/console app:inventory:ozon-daily-sync --no-interaction >> /proc/1/fd/1 2>> /proc/1/fd/2`

- Запуск в `04:05 MSK`.
- Время `04:05` выбрано, чтобы не стартовать одновременно с Ozon marketplace sync в `04:00`.
- Cron только инициирует snapshot-pipeline; HTTP-загрузка выполняется асинхронным worker-ом.

### Config

Используются следующие конфигурации:

- `config/routes.yaml`
  - `inventory_controllers`
  - `path: ../src/Inventory/Controller/`
  - `namespace: App\Inventory\Controller`
- `config/packages/twig.yaml`
  - `'%kernel.project_dir%/templates/inventory': Inventory`
- `config/packages/messenger.yaml`
  - `App\Inventory\Message\SyncOzonInventorySnapshotMessage: async_sync`

### Tests / coverage

- Краткий аудит покрытия и пробелов зафиксирован в `site/tests/InventoryPipelineCoverageReport.md`.
- Task 12 выполнен как аудит покрытия.
- Task 12.1 закрыл обязательные пробелы:
  - `InventorySnapshotSessionRepository::findByIdAndCompany()`
  - `OzonInventoryClient` HTTP 401
  - invariant invalid `connectionId`
  - отсутствие `connection_id`-колонки в `inventory_raw_snapshots`
- Anti-HTTP/архитектурные guard-тесты отмечены как дополнительные, не обязательные для текущего этапа.

## Решения принятых в Projects-чатах

> Сюда переносить итоги проектирования из Claude.ai Projects.
> Формат: дата · модуль · кратко что решили.

### 2026-03-28 — Инфраструктура
- Redis: сессии БД 0, Messenger на `/messages`, Lock с prefix `symfony-locks`
- Messenger worker: `--time-limit=3600`, `restart: always`
- Prod: `app.vashfindir.ru`, legacy `app.2bstock.ru` → редирект

---

## Changelog

| Версия | Дата | Что изменилось |
|---|---|---|
| 1.47 | 2026-05-10 | fix(inventory): для Ozon `POST /v4/product/info/stocks` в request body добавлен `filter.visibility=ALL`; это устраняет `HTTP 400` и `failed`-сессии загрузки остатков при корректных credentials. |
| 1.46 | 2026-05-10 | docs(inventory): в `ARCHITECTURE.md` задокументирован фактически реализованный Ozon Inventory Snapshot Pipeline: выделенный Inventory UI (GET/POST routes), `RequestOzonInventorySnapshotAction`, async message `SyncOzonInventorySnapshotMessage` (`async_sync`) и handler, `OzonInventoryClient` в модуле Inventory, daily cron `app:inventory:ozon-daily-sync` в 04:05 MSK, хранение `connectionId` в `requestParams` JSON без отдельной колонки, сохранение raw Ozon response в `InventoryRawSnapshot` без нормализации. Зафиксированы границы модулей: Inventory работает с Marketplace только через публичный `MarketplaceFacade`-контракт (без прямых импортов Marketplace Infrastructure/Repository/Query). |
| 1.45 | 2026-05-10 | feat(marketplace): добавлен публичный контракт `MarketplaceFacade::getActiveOzonSellerConnections(?string $companyId = null): array` для кросс-модульного доступа (в т.ч. Inventory) к активным Ozon SELLER-подключениям **без утечки секретов**. Метод строится на `ActiveOzonConnectionsQuery` и возвращает только безопасные поля: `connectionId`, `companyId`, `marketplace`, `connectionType`, `clientId`. Query минимально расширен полем `client_id` (сохранён `finance_lock_before` для существующих потребителей). Добавлены интеграционные тесты `MarketplaceFacadeTest`: фильтры `is_active=true`, `marketplace=ozon`, `connection_type=seller`, фильтрация по `companyId`, и проверка отсутствия `apiKey/clientSecret/credentials/settings` в результате. |
| 1.44 | 2026-04-28 | feat(marketplace): новый публичный метод `MarketplaceFacade::resolveListingsToProducts(string $companyId, array $listingIds): array` — пакетный резолв `listingId → productId|null` для будущего парсинга raw snapshot'ов в Inventory модуле. Один DQL-запрос с `IDENTITY(l.product)` и `getArrayResult()` — без N+1 и без загрузки Entity. IDOR через `IDENTITY(l.company) = :companyId`: листинги чужих компаний не появляются в результате (отсутствует ключ, не `null`). `productId = null` только для orphan-листингов (легитимный кейс). Валидация: `Assert::uuid($companyId)`, `Assert::allUuid($listingIds)`, `Assert::maxCount(5000)` — защита от случайного огромного массива; пустой массив возвращается без запроса в БД. Реализация — новый метод `MarketplaceListingRepository::findListingToProductMap(string $companyId, array $listingIds): array` (стиль уже существующего `findMarketplaceNamesByProductIds`). Дедупликация входа через `array_unique` перед `IN (:listingIds)`. **Не тронуто:** Entity `MarketplaceListing`, схема БД, существующие методы фасада. Тесты — `tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php` (8 кейсов: empty / invalid companyId / invalid listingId / 5001 limit / mapped listing / orphan listing / другая компания / non-existent listing / batch 100 листингов 60 mapped + 40 orphan). |
| 1.43 | 2026-04-27 | revert(marketplace): откат soft-режима в `CloseMonthStageAction` — preliminary close проходит только при зелёном preflight. Удалена константа `SOFT_IGNORABLE_IN_PRELIMINARY` и ветка фильтрации hard или soft ошибок; восстановлена простая проверка `if (!$preflightResult->canClose()) throw DomainException`. Параметр `$preliminary` в вызовах `DataSource::getUnprocessedEntries()` и `UnprocessedCostsQuery::getControlSum()` **сохранён** как dormant hook — фильтры в `Unprocessed*Query` при `$preliminary=true` корректны, но на практике не будут отфильтровывать данные, так как при наличии soft-ошибок (например, `sales_without_cost`) preflight-проверка в `RebuildPreliminaryForPeriodAction` завершится неудачей, и вызов `CloseMonthStageAction` будет пропущен. Префикс `[Оперативное закрытие DD.MM.YYYY HH:MM]` в comment операций и запись `settings.last_close_was_preliminary[stage]` или `settings.preliminary_calculated_at[stage]` — **оставлены**. Удалены тесты `testPreliminaryClose_BypassesSalesWithoutCostBlocker_AndExcludesThemFromDocument` и `testPreliminaryClose_BypassesUnknownServiceNamesBlocker_AndExcludesThemFromDocument`; `testPreliminaryClose_StillBlocksOnCostsAlreadyProcessed` переименован в `testPreliminaryClose_BlocksOnAnyPreflightError`. `DataSourcePreliminaryModeTest`, `UnprocessedCostsQueryCoherenceTest`, `PreflightActionDetailsTest`, `RebuildPreliminaryForPeriodActionTest` — **не тронуты**. |
| 1.42 | 2026-04-27 | feat(marketplace): soft-режим preflight для «Оперативного закрытия» + SKU в preflight UI. **(1) Интерфейс DataSource.** `MarketplaceDataSourceInterface::getUnprocessedEntries()` получил опциональный `bool $preliminary = false`. Все реализации (`SalesReturnsDataSource`, `CostsDataSource`, `RealizationDataSource`, `RealizationReturnDataSource`) принимают флаг. `Realization*DataSource` игнорируют `$preliminary` — для реализации нет понятия «без себестоимости» на уровне строки. Контракт `markProcessed()` **не изменился**: даже в preliminary-режиме помечаются все строки за период (исключённые строки не должны всплывать на следующем cron-тике). **(2) Soft-фильтры в Unprocessed*Query.** При `$preliminary=true` добавляются условные WHERE: `UnprocessedSalesQuery` — `AND s.cost_price IS NOT NULL AND s.cost_price > 0`; `UnprocessedReturnsQuery` — `AND r.cost_price IS NOT NULL AND r.cost_price > 0`; `UnprocessedCostsQuery` — `AND mcc.code != 'ozon_other_service'`. `UnprocessedCostsQuery::getControlSum()` получил тот же параметр с тем же фильтром — это критично: без него контрольная сумма включает ozon_other_service затраты, а `execute(preliminary=true)` их исключает → delta > 0.01 → ложный `RuntimeException`. Realization-запросы не изменялись. **(3) Soft-preflight в `CloseMonthStageAction`.** Добавлена константа `SOFT_IGNORABLE_IN_PRELIMINARY = ['sales_without_cost', 'returns_without_cost', 'costs_unknown_service_names']`. При `$command->preliminary = true` блокируют только ошибки с `key` вне этого списка: `finance_lock`, `already_closed`, `costs_already_processed` — остаются блокирующими в обоих режимах. `getUnprocessedEntries()` вызывается с `$command->preliminary`. `getControlSum()` тоже получает флаг. Финальное закрытие (`preliminary=false`) **не изменено**. **(4) Preflight details — SKU и service names.** `PreflightCheck::error()` получил опциональный `array $details = []` (аналогично существующему `warning()`). `PreflightSalesReturnsQuery` дополнен методами `getSalesWithoutCostSkus()` и `getReturnsWithoutCostSkus()` — GROUP BY `ml.marketplace_sku, ml.supplier_sku` через JOIN `marketplace_listings`, ORDER BY count DESC. `PreflightCostsQuery` дополнен `getUnknownServiceNamesList()` — GROUP BY `c.description` для `ozon_other_service`, ORDER BY count DESC. `MonthClosePreflightAction` заполняет `details[]` для `sales_without_cost`, `returns_without_cost`, `costs_unknown_service_names`. Существующий `details[]` для `costs_without_mapping` не тронут. **(5) UI.** JS-рендер preflight-деталей в `templates/marketplace/month_close/index.html.twig` расширен: `marketplace_sku` → «SKU {sku} (артикул: {supplier}) — {count} шт.»; `service_name` → «{name} — {count} шт.»; `category_name` → существующий формат (без изменений). Бейдж «Оперативное закрытие» не изменялся. **Что не изменилось:** Finance-модуль, `Document`, `DocumentOperation`, `FinanceFacade`, `markProcessed()`, логика финального закрытия, контрольная сумма как концепция (лишь добавлен preliminary-параметр для согласованности). Новые тесты: `PreflightActionDetailsTest` (3 кейса: SKU продаж/возвратов + service names в details); `DataSourcePreliminaryModeTest` (3 кейса: sales/returns exclude без cost_price, costs exclude ozon_other_service); расширение `CloseMonthStageActionPreliminaryTest` (4 новых кейса: bypass + document sum только из валидных строк / bypass + costs без ozon_other_service / `costs_already_processed` блокирует в preliminary / финальное блокируется на `sales_without_cost`). `CloseMonthStageActionRollbackTest` обновлён — stub `getControlSum` принимает новый параметр `$preliminary`. |
| 1.41 | 2026-04-27 | feat(marketplace): «Оперативное закрытие месяца» — ежедневная автопересборка ОПиУ за текущий открытый месяц без изменений в Finance. Поверх существующего пайплайна `CloseMonthStageAction` / `ReopenMonthStageAction` без новых сущностей в Finance, без новых статусов в `MonthCloseStageStatus` и без новых полей в `MarketplaceMonthClose` (всё состояние — в существующем JSON-blob `settings`). **Не менялись** в Finance: `Document`, `DocumentOperation`, `FinanceFacade`, `PLRegisterUpdater`. (1) `CloseMonthStageCommand` получил опциональный `bool $preliminary = false`. При `true`: каждый `PLEntryDTO::description` (= `DocumentOperation.comment`) получает префикс `[Оперативное закрытие DD.MM.YYYY HH:MM] ...` (один таймстемп на всё закрытие); в `settings` пишутся **per-stage** ключи `last_close_was_preliminary[stage] = true` и `preliminary_calculated_at[stage] = ISO-8601`. При `false` (включая ручное финальное закрытие финансиста) — поведение идентично прежнему, флаг **только этого этапа** сбрасывается в `false`, `preliminary_calculated_at[stage] = null`; флаг соседнего этапа того же месяца остаётся нетронутым. Существующий `CloseMonthStageActionRollbackTest` не правился. Новые методы Entity `MarketplaceMonthClose`: `getSettings()`, `setSettings()`, `isStageLastCloseWasPreliminary(CloseStage $stage): bool`, `getStagePreliminaryCalculatedAt(CloseStage $stage): ?DateTimeImmutable`. **Решение per-stage** (а не единый флаг на месяц) зафиксировано после P1-review от Codex: при смешанном состоянии (один этап финально закрыт, другой — предварительно) единый флаг ошибочно бы переоткрыл финально закрытый этап на следующем cron-rebuild; покрыто регрессией `CloseMonthStageActionPreliminaryTest::testPreliminaryFlagIsScopedToTheClosedStageOnly`. (2) `RebuildPreliminaryForPeriodAction` (`src/Marketplace/Application/`) — оркестратор для одной пары `(companyId, marketplace, year, month)`. Для каждого этапа `[SALES_RETURNS, COSTS]` читает per-stage флаг через `isStageLastCloseWasPreliminary($stage)`: `CLOSED + wasPreliminary=false` → skip (финальное закрытие неприкосновенно); `CLOSED + wasPreliminary=true` → `ReopenMonthStageAction` → preflight → `CloseMonthStageAction(preliminary=true)`; `PENDING/REOPENED` → preflight → close. `DomainException` и провал preflight гасятся `warning`-логом и переход к следующему этапу — один проблемный этап (например, unmapped costs) не валит rebuild всей компании. (3) `RebuildPreliminaryForPeriodMessage` + `RebuildPreliminaryForPeriodHandler` — async-цепочка через Messenger. Транспорт `async_pipeline` (как `CloseMonthStageMessage`); `DomainException` → no retry, прочие `Throwable` → re-throw для retry. (4) `app:marketplace:month-preliminary-rebuild` — cron-команда (`docker/cron/app.cron`: `45 4 * * *`, после `ozon-daily-sync` 04:00 и `marketplace-ads:daily-sync` 04:30). **Только текущий месяц** — расширение на предыдущий месяц явно отклонено как scope-creep (gemini-bot suggestion). Тонкая: `ActiveSellerConnectionsQuery` (новый DBAL Query, активные SELLER-подключения всех маркетплейсов) → диспатч сообщения per-row. `LockableTrait` против overlap, per-connection try/catch. Системный actor — фиксированный UUID `00000000-0000-0000-0000-000000000001` (поле `MarketplaceMonthClose.stageXxxClosedByUserId` — guid nullable БЕЗ FK, несуществующий пользователь не ломает закрытие). (5) `POST /marketplace/month-close/preliminary/rebuild` (`MonthPreliminaryRebuildController`) — ручной запуск пересчёта из UI. Body `{marketplace, year, month}`, ответ `202 + {queued: true}`, `429 + {error}` при превышении лимита. Rate-limit 1/мин на ключ `(companyId, marketplace, year, month)` через новый `framework.rate_limiter.marketplace_preliminary_rebuild`. (6) UI `templates/marketplace/month_close/index.html.twig`: жёлтый бейдж «Оперативное закрытие · DD.MM HH:MM» вместо зелёного «Закрыт» — per-stage, через `month_close.isStageLastCloseWasPreliminary(stage)` / `month_close.getStagePreliminaryCalculatedAt(stage)`; кнопка «Пересчитать сейчас» рядом с «Закрыть этап»/«Переоткрыть» (только для текущего календарного месяца); per-stage маркер «Оперативное закрытие» в таблице истории (через `constant('App\\Marketplace\\Enum\\CloseStage::SALES_RETURNS')` / `…::COSTS`); индексный контроллер фильтрует историю — периоды, где **оба** этапа закрыты предварительно, не показываются (это ещё не финальное закрытие); частично-финальное (один этап final, другой prelim) — показывается. Регрессии: `CloseMonthStageActionPreliminaryTest` (5 кейсов: префикс в comment / per-stage флаг в settings / **per-stage изоляция** / финальное закрытие сбрасывает флаг этапа / default = финальное закрытие); `RebuildPreliminaryForPeriodActionTest` (4 кейса: PENDING→preliminary / preliminary→reopen→preliminary / финально-закрытый этап skip / preflight fail skip); unit `RebuildPreliminaryForPeriodHandlerTest`, unit `MonthPreliminaryRebuildCommandTest`, integration `MonthPreliminaryRebuildControllerTest` (rate-limit, 400 на bad input). |
| 1.40 | 2026-04-26 | fix(finance): `app:finance:recalc-pl-register` без позиционного `companyId` падал на проде с `SQLSTATE[42P01]: Undefined table: relation "company" does not exist` — `RecalcPlRegisterCommand::resolveCompanies()` через `CompanyRepository::getAllActiveCompanyIds()` выполнял `SELECT id::text FROM company WHERE is_active = true`, тогда как реальная схема — таблица `companies` (множ.число) без поля `is_active` и без soft-delete (см. `src/Company/Entity/Company.php` + `migrations/Version20250725153117`). Команда переключена на прямой DBAL `Connection::fetchFirstColumn('SELECT id::text FROM companies ORDER BY id')` без зависимости от багнутого репо-метода. Режим с явным `companyId` работал и раньше — оставлен без изменений. Регрессия: новый `tests/Integration/Finance/RecalcPlRegisterCommandTest.php` (4 кейса: без `companyId` обрабатываются все компании; с явным `companyId` — ровно одна; несуществующий UUID → clean error и `Command::FAILURE`; пустая БД → clean exit). Тот же баг в `CompanyRepository::getAllActiveCompanyIds()` затрагивает `Cash/Infrastructure/Command/DispatchCounterpartyScoringCommand` и `MarketplaceAds/Command/LoadAdDataCommand` — отдельная задача (вне scope, чтобы не расширять blast radius фикса). |
| 1.39 | 2026-04-26 | fix(finance): follow-up к 1.38. Семантика marketplace_pl в `PLRegisterUpdater::aggregateDocuments()` уточнена: внутри потока marketplace_pl сосуществуют два соглашения о знаке — COGS/PROMO/OPEX (`charge=-X, storno=+X`, sum<0) и REV_*_RETURNS (`amount=+X` всегда, sum>0). Прежняя реализация инвертировала знак per-operation для EXPENSE-категорий и в случае REV_SPP_RETURNS давала `expense=-12509.17` → `SQLSTATE 23514` на CHECK `chk_pl_daily_totals_amounts` (требует `amount_income/amount_expense >= 0`). Теперь для marketplace_pl сначала суммируются операции по `(date, project, category)` со знаком в отдельные `mpIncomeSigned` / `mpExpenseSigned` буферы, и в `persistAggregatedTotals` пишется `abs(signedSum)` в соответствующий слот. Legacy-типы (CASHFLOW_*, TAXES, LOANS, PAYROLL, …) по-прежнему идут через `abs()` per-operation. Регрессия: добавлен `testMarketplacePlPositiveExpenseCategory` (REV_SPP_RETURNS-кейс, +12509.17 → expense=12509.17, без 23514). 4 существующих теста (`testMarketplacePlStornoReducesExpense`, `testCashflowExpenseLegacySemanticsPreserved`, `testMarketplacePlIncomeWithReturn`, `testNetReportValueMatchesDocumentOperationsSumForMarketplace`) сохраняют те же ожидаемые значения (поведение для COGS-стиля не изменилось: `signedSum<0`, `abs=expense`, инвариант `(income−expense)==SUM(amount)` сохраняется). Constraint в БД не трогали — он корректен. |
| 1.38 | 2026-04-26 | fix(finance): `pl_daily_totals` больше не удваивает storno-операции маркетплейса (Δ = 2×|storno|). `PLRegisterUpdater::aggregateDocuments()` ранее применял `abs($operation->getAmount())` ко всем документам, что ломало знаковую семантику `Document::type = marketplace_pl` (charge=-X, storno=+X). Для marketplace_pl теперь используется знаковая запись: `nature==INCOME → income += signedAmount`, `nature==EXPENSE → expense += -signedAmount`. Для legacy-типов (CASHFLOW_*, TAXES, LOANS, PAYROLL и др.) поведение сохраняется (`abs()` + nature по `category.flow`). Затронуты компании, закрывавшие месяц COSTS со сторно-возвратами комиссии/эквайринга/доставки (production-инцидент: company `b57d7682-505f-4b74-86f8-953d32d59874`, март 2026, Δ = +30 920.28 ₽ по `COGS_MP_COMMISSION` и др.). Регрессия: `tests/Integration/Finance/PLRegisterUpdaterStornoSymmetryTest` (4 теста: marketplace storno EXPENSE, legacy CASHFLOW_EXPENSE, marketplace INCOME с возвратом, март-2026 incident reproduction `(income − expense) == SUM(amount)`). Новая CLI: `app:finance:recalc-pl-register {companyId?} --from --to [--dry-run]` (`src/Finance/Command/RecalcPlRegisterCommand.php`) — backfill `pl_daily_totals` + `pl_monthly_snapshots` для одной компании или всех активных, идемпотентна, лочится через `LockFactory` (`finance_recalc_pl_register_<companyId>`, TTL 1800c), `--dry-run` печатает таблицу diff'а «до/после» без записи. `PlNatureResolver`, `UnprocessedCostsQuery`, `FinanceFacade::createPLDocument`, `PLSnapshotBuilder` не изменялись (после пересчёта `pl_daily_totals` они автоматически дают корректный результат, читая `SUM(income)` / `SUM(expense)`). |
| 1.37 | 2026-04-25 | MarketplaceAds: `AdEfficiencyQuery::getPage` — `totalAdSpend` теперь включает non-attributed РР (висячие `listing_id` в `ad_document_lines`, у которых нет живого листинга в `marketplace_listings`). Раньше `total_ad_spend` считался по `base_listings LEFT JOIN ads_agg` (inner-join на `marketplace_listings` отсекал висячие id), из-за чего суммы в `/marketplace-ads/efficiency` totals и `/marketplace-analytics/unit-extended` totals (тот всегда читал полный `getTotalAdCostForPeriod()`) расходились на сумму non-attributed РР — нарушалось ключевое требование «суммы за одинаковый период в двух отчётах должны совпадать». SQL агрегатора переписан на скалярные подзапросы по CTE: `total = (SELECT COUNT(*) FROM base_listings)`, `total_revenue = (SELECT SUM(sa.revenue) FROM sales_agg sa JOIN base_listings bl ON ...)`, `total_ad_spend = (SELECT SUM(ad_spend) FROM ads_agg)` — `ads_agg` НЕ фильтруется по существованию листинга, висячие 777-строки попадают в totals. Сами «висячие» listing_id в `items` по-прежнему НЕ появляются (для них нет видимой строки) — items строятся из `base_listings JOIN marketplace_listings`. `total` (item count) остаётся по `base_listings`, не раздувается. Регрессия — `tests/Integration/MarketplaceAnalytics/UnitExtendedAdSpendConsistencyTest` (без фильтра + Ozon с висячим РР + WB без висячих) проверяет паритет totals и расхождение items на величину non-attributed. Существующий `testOrphanedAdLineListingIdIsExcludedFromCountAndTotals` переименован в `testOrphanedAdLineListingIdIsExcludedFromItemsButCountedInTotalAdSpend`, ассерт `totalAdSpend = 150` исправлен на `192` (висячие 42.00 теперь учтены). |
| 1.36 | 2026-04-25 | MarketplaceAds: новый read-only query `AdSpendByListingQuery` (`src/MarketplaceAds/Infrastructure/Query/AdSpendByListingQuery.php`) — РР с разрезом по листингам за период. Семантически = CTE `ads_agg` из `AdEfficiencyQuery`, вынесен в отдельный класс для переиспользования из других модулей через фасад. SQL = `SELECT adl.listing_id, SUM(adl.cost) FROM marketplace_ad_document_lines adl JOIN marketplace_ad_documents ad ON ad.id = adl.ad_document_id WHERE ad.company_id = :companyId AND ad.report_date BETWEEN :periodFrom AND :periodTo [AND ad.marketplace = :marketplace] GROUP BY adl.listing_id`. В отличие от `AdEfficiencyQuery` НЕ inner-join'ит на `marketplace_listings` — «висячие» listing_id (line.listing_id без живого листинга) попадают в выдачу; это критично для согласованности с `getTotalAdCostForPeriod` на потребителях. Возвращает `array<string, string>` (listingId → decimal-строка), без float — bcmath-совместимость модуля. `MarketplaceAdsFacade` получил конструктор-параметр `AdSpendByListingQuery` рядом с `AdDocumentQuery` и публичный метод `getAdSpendByListingForPeriod(companyId, from, to, ?marketplace=null): array`, делегирующий в новый query. Существующий `getTotalAdCostForPeriod` (полная сумма за период по `marketplace_ad_documents.total_cost`, включая non-attributed) не трогали — его роль totals на потребителях. `AdEfficiencyQuery` и `AdEfficiencyController` не менялись (CTE-дубликат из 6 строк допустим). Тесты: integration `AdSpendByListingQueryTest` (5 кейсов: happy / marketplace-фильтр / пустой период / non-attributed listing_id попадает в выдачу / IDOR другой компании); unit `MarketplaceAdsFacadeTest` (делегация getAdSpendByListingForPeriod через мок + дефолт `marketplace=null`). |
| 1.35 | 2026-04-25 | Marketplace: рефакторинг UI `/marketplace/sales` — фильтр по диапазону дат и JSON-экспорт. `SalesListQuery::buildQueryBuilder` расширен опциональными `?\DateTimeImmutable $from, ?\DateTimeImmutable $to` (включительные границы `>=`/`<=`, формат `Y-m-d`); возвращает `QueryBuilder`, индексная страница оборачивает его в Pagerfanta-адаптер, экспорт читает напрямую через `fetchAllAssociative()`. Новый `SalesJsonExportController` — `GET /marketplace/sales/export.json` (route `marketplace_sales_export_json`), `JsonResponse` с `Content-Disposition: attachment`, payload `{exported_at, filters, count, sales[]}`, IDOR через `ActiveCompanyService`. Endpoint — page-route, не публичный API (OpenAPI и `schema.d.ts` не затронуты). `MarketplaceSalesController` читает `date_from`/`date_to` + hardened против `?key[]=foo`/массивных входов: невалидные query-параметры через `query->all()` + локальные `stringOrNull`/`parseDate` (с round-trip формата против rollover вроде `2026-04-31` → `2026-05-01`) трактуются как «фильтр не задан», без 4xx. Twig `marketplace/sales.html.twig`: в `.card-header` — два `<input type="date">`, кнопка «Применить», условная «Сбросить» + ссылка «Скачать JSON»; `onchange="this.form.submit()"` со `<select name="marketplace">` снят. Pagerfanta-навигация сохраняет активные фильтры через `routeParams` в include `partials/_pagerfanta.html.twig`. |
| 1.34 | 2026-04-24 | MarketplaceAds / Task-16: UI рефакторинг после стабилизации cron-pipeline'а. (1) «История загрузок» ограничена top-10 job'ами: `AdLoadJobsListController::LIMIT` 20 → 10, `AdLoadJobRepository::findRecentByCompanyAndMarketplace` переиспользован (limit уже параметризован). (2) «Сырые документы» ограничены top-20 документами: новый метод `AdRawDocumentRepositoryInterface::findRecentByCompanyMarketplaceAndDateRange(companyId, marketplace, from, to, limit=20): list<AdRawDocument>` — фильтр по диапазону дат применяется ДО лимита, сортировка `createdAt DESC`; `AdRawDocumentsListController` переключён на него с `LIMIT=20`. Старый `findByCompanyMarketplaceAndDateRange` (без лимита, `reportDate DESC`) сохранён — им пользуется `DownloadOzonAdReportHandler`-integration-тест и другие non-UI места, где нужен полный список. (3) Кнопки «Открыть batch N (dateFrom—dateTo)» удалены из колонки «Действия» таблицы «История загрузок»: `AdLoadJobsListController` больше не вызывает `collectBatchFiles()`/`collectRawDocumentFiles()` и не возвращает поле `files` в JSON; JS-хелперы `renderJobFiles`, `buildBatchDownloadUrl`, `formatDateRu` удалены из `templates/marketplace_ads/index.html.twig`. Кнопки «Обработать» (для `completed`/`partial_success` jobs с батчами, CSRF `extract-batches-<jobId>`) и «Пометить FAILED» (для `pending`/`running` jobs) **сохранены**. Пустая колонка «Действия» для running-jobs без кнопок рисует плейсхолдер `<span class="text-muted">—</span>`. (4) Endpoint `GET /marketplace-ads/batches/{id}/download` (`AdScheduledBatchDownloadController`) и репозиторный метод `AdScheduledBatchRepository::findDownloadableByJobId` **не удалены** — endpoint остаётся доступен через прямой URL для admin-debug, `findDownloadableByJobId` используется `ExtractBatchesToRawDocumentsAction` (кнопка «Обработать»). DI `AdLoadJobsListController` упрощён: убраны `AdRawDocumentRepository` и `AdScheduledBatchRepository` не нужен для `files` (остаётся для `countStatesForJob` в batchStats). Integration-тесты обновлены: `AdLoadJobsListControllerTest::testLimitsTo10Items` (15 jobs → 10 items), `AdRawDocumentsListControllerTest::testLimitsTo20Items` (30 documents в 30-дневном диапазоне → 20 items). Существующие `testJobWithScheduledBatchesExposes*` и `testJobWithoutScheduledBatchesFallsBackTo*` переписаны: вместо `assertCount(N, $item['files'])` теперь `assertArrayNotHasKey('files', $item)` — поле убрано из контракта. |
| 1.33 | 2026-04-24 | MarketplaceAds / Task-13b: `AdDailySyncCommand` (`app:marketplace-ads:daily-sync`) — ежедневная автоматическая точка инициации cron-driven pipeline'а. Для каждой компании с активным Ozon Performance подключением (`ActiveOzonPerformanceConnectionsQuery::getCompanyIds()`) создаёт один `AdLoadJob` на `dateFrom=dateTo=вчера в UTC`, синхронно планирует batch'и через `AdBatchPlanner::planBatchesForJob()` и переводит job в RUNNING (без этого Finalizer (v1.27) не подхватил бы его через `findAllRunning()`). Дальше работает автономно: scheduler → poller → finalizer → auto-extract (v1.31) → worker. Cron: `30 4 * * *` в scheduler-контейнере (`TZ=Europe/Moscow` → 04:30 MSK). `LockableTrait` — защита от наложения тиков; per-company isolation: сбой у одной компании (`Throwable`) не блокирует остальных, логируется `error`-уровнем. Идемпотентность: новый метод `AdLoadJobRepositoryInterface::existsByDateRange(companyId, marketplace, dateFrom, dateTo): bool` — повторный запуск в тот же день видит существующий job (любого статуса, включая FAILED/COMPLETED) и skipped'ит компанию; без него второй ручной запуск создавал бы дубликат. При ошибке Planner'а (например, `RuntimeException('No SKU campaigns found')`) job помечается `markFailed('Planning error: …')` — чтобы `existsByDateRange` видел его в следующий запуск и оператор видел причину в «Истории загрузок». Старый cron entry `30 4 * * * app:marketplace-ads:ozon-daily-sync` (Messenger event-driven pipeline) **удалён** из `docker/cron/app.cron`; сам класс `OzonAdDailySyncCommand` оставлен до Task-14, т.к. он диспатчит в Messenger-цепочку, которая ещё обслуживает остатки (выпиливание — отдельная задача). Тесты: unit `AdDailySyncCommandTest` (5 кейсов: happy path 3 компании с yesterday-UTC / empty company list / existsByDateRange → skipped / per-company isolation после Throwable / `existsByDateRange` зовётся с корректной UTC-датой и OZON marketplace.value); integration `Command/AdDailySyncCommandTest` (5 кейсов: 2 компании, только с PERFORMANCE-connection получает RUNNING job + PLANNED batch'и / повторный запуск в тот же день → skipped без дубликата / SELLER-only Ozon не попадает / Planner fail → job в FAILED, сиблинг получает RUNNING / no companies → SUCCESS message); integration `AdLoadJobRepositoryTest::testExistsByDateRange*` (5 кейсов: happy / empty → false / WB-job не матчится OZON-запросом / чужая компания → false / соседний день → false / терминальный FAILED job всё равно матчится — критично для идемпотентности). |
| 1.32 | 2026-04-24 | MarketplaceAds / Task-13a fix: PostLoad re-parse wall-clock как UTC в `AdScheduledBatch` Entity — устраняет false-positive ABANDONED из-за TZ mismatch при Doctrine hydration. Prod-инцидент: batch'и переводились в ABANDONED через 30 секунд после POST с ошибкой «stuck in NOT_STARTED for 3 hours» — scheduler-контейнер запущен с `TZ=Europe/Moscow` (нужен для daily-sync cron 04:30 MSK); Doctrine `datetime_immutable`-тип гидратирует `timestamp without time zone` через `DateTimeImmutable::createFromFormat('Y-m-d H:i:s', …)` без явного TZ → PHP применяет default TZ процесса → hydrated instant сдвинут на **-3 часа** относительно UTC-wall-clock'а, который Scheduler (v1.28) писал в БД. Поля заполняются через reflection в обход сеттеров, поэтому UTC-нормализация из v1.28 не помогает. Poller считал `age = UTC-now() - hydrated-startedAt-Unix-seconds` и получал реальный возраст + 10 800с → `MAX_AGE_BEFORE_ABANDON_HOURS=3` мгновенно превышался. Fix: `#[ORM\HasLifecycleCallbacks]` на Entity + `#[ORM\PostLoad] public function normalizeTimezonesAfterLoad()`, который **перестраивает каждый DateTimeImmutable** из его wall-clock-строки (`format('Y-m-d H:i:s.u')` в PHP default TZ равен UTC-строке, записанной в БД) как `new \DateTimeImmutable($wallClock, UTC)` — корректный instant восстанавливается. `setTimezone('UTC')` здесь не подходит: он сохраняет instant и лишь меняет TZ-метку, т.е. оставляет уже-сдвинутый на -3h момент. Тип колонки в БД не менялся — TIMESTAMPTZ остаётся отдельной задачей. Scheduler-контейнер TZ не трогали. Область: только `AdScheduledBatch`; `AdLoadJob` / `AdRawDocument` (старый Messenger-pipeline) не изменялись. Тесты: (1) integration `AdScheduledBatchRepositoryTest::testPostLoadNormalizesAllDatetimeFieldsToUtc` — сохраняем batch с UTC-полями под `date_default_timezone_set('Europe/Moscow')`, после `em->clear() + find()` проверяем, что `getTimezone()->getName() === 'UTC'` для всех пяти полей и `format('Y-m-d H:i:s')` равен исходному wall-clock'у (instant восстановлен); (2) integration `testHydratedStartedAtKeepsRealAgeUnderAbandonThreshold` — `startedAt=now-30s` UTC под Moscow PHP-TZ → после hydration `age < 300` секунд (ужесточено с `< 3h` в ходе review — ловит и полный сдвиг на 3 часа, и мелкие сдвиги); (3) unit `AdBatchPollerCommandTest::testRecentStartedAtDoesNotTriggerAbandon` — явный regression на ветку `NOT_STARTED` + `startedAt=now-30s` → batch остаётся IN_FLIGHT, `flush` не вызывается. Существующие тесты UTC-нормализации в сеттерах (v1.28) сохраняются — PostLoad дополняет, не заменяет их (сеттеры всё ещё нужны для прямого создания Entity в PHP; PostLoad покрывает hydration-путь). |
| 1.0 | 2026-03-28 | Инициализация на основе архитектурного документа v1.3 |
| 1.1 | 2026-03-31 | MarketplaceAnalytics: рефакторинг маппинга затрат — статья фиксированная (UnitEconomyCostType), категория МП выбирается из справочника (marketplace_cost_categories), убраны isSystem и costCategoryCode. Из Facade удалены remapCostMapping, resetCostMapping. |
| 1.2 | 2026-04-10 | Marketplace: автозапуск daily pipeline после загрузки sales_report. Новый Message `ProcessDayReportMessage` (companyId, rawDocumentId) → `ProcessDayReportHandler` сбрасывает статус конкретного документа и диспатчит `ProcessRawDocumentStepMessage` для каждого шага. SyncWb/OzonReportHandler диспатчат `ProcessDayReportMessage` после успешной загрузки. |
| 1.3 | 2026-04-12 | MarketplaceAds: новый модуль обработки рекламных отчётов. Entity: `AdRawDocument` (raw JSONB + status DRAFT/PROCESSED), `AdDocument` (кампания × parent SKU за дату), `AdDocumentLine` (распределение на листинг). Domain Port `ListingSalesProviderInterface` с bulk `getSalesQuantitiesByListings` + реализация `ListingSalesProviderMarketplace` через Facade. Domain `AdCostDistributor`: распределение bcmath, при 0 продажах — равномерно, поправка округления на максимальную долю. В `MarketplaceFacade` добавлены `findListingsByMarketplaceSku` (без фильтра `is_active`, для исторических отчётов) и `getSalesQuantitiesForListings` (bulk GROUP BY, убирает N+1). |
| 1.4 | 2026-04-12 | MarketplaceAds: `ProcessAdRawDocumentAction` — загрузка AdRawDocument (DRAFT) и конвертация в AdDocument + AdDocumentLine. Идемпотентность через `deleteByRawDocumentId`, частичный успех оставляет статус DRAFT. Bulk-prefetch листингов и продаж на уровне Action (2 запроса на документ), `AdCostDistributor` стал чистой функцией без DI. В `MarketplaceFacade` добавлен `findListingsByMarketplaceSkus` (bulk, сгруппирован по parentSku), в `ListingSalesProviderInterface` — `findListingsByParentSkus`. |
| 1.5 | 2026-04-13 | MarketplaceAds: CLI-обвязка + Messenger-пайплайн (`app:marketplace-ads:load`, `app:marketplace-ads:reprocess`, `ProcessAdRawDocumentMessage`, `ProcessAdRawDocumentHandler`). Batch-flush в Load (единый flush по всему проходу), guardrails в Reprocess (--all / хотя бы один фильтр, batch clear BATCH_SIZE=50). `MarketplaceAdsFacade` — публичный API модуля: `getAdCostsForListingAndDate`, `getTotalAdCostForPeriod`. `AdDocumentQuery` — DBAL-запросы к `marketplace_ad_documents` JOIN `marketplace_ad_document_lines`. `AdCostForListingDTO` для передачи распределённых данных между модулями. |
| 1.6 | 2026-04-15 | Marketplace: новый enum `MarketplaceConnectionType` (`SELLER` / `PERFORMANCE`). Entity `MarketplaceConnection` получила поле `connectionType` (дефолт `SELLER`), `UniqueConstraint` расширен до `(company_id, marketplace, connection_type)`. Позволяет одной компании хранить отдельные подключения к Ozon Seller API и Ozon Performance API. |
| 1.7 | 2026-04-15 | Marketplace: `MarketplaceCredentialsQuery::getCredentials()` получил опциональный параметр `MarketplaceConnectionType $connectionType = SELLER` с SQL-фильтром `AND mc.connection_type = :connection_type`. В `MarketplaceFacade` добавлен метод `getConnectionCredentials()` для кросс-модульного доступа к credentials (используется из MarketplaceAds для Performance API). Существующие вызовы (WbFetcher, OzonFetcher, OzonProductBarcodeFetcher) продолжают работать без изменений за счёт дефолта. |
| 1.8 | 2026-04-15 | MarketplaceAds: `OzonAdClient` реализован для работы с Ozon Performance API (`https://api-performance.ozon.ru`). Credentials забираются через `MarketplaceFacade::getConnectionCredentials(..., PERFORMANCE)`; OAuth2 access-token кэшируется в Symfony Cache с TTL = `expires_in - 300`. Пайплайн `fetchAdStatistics()`: листинг SKU-кампаний → async `/api/client/statistics` батчами по 100 кампаний → polling состояния (5 сек × 36 попыток = 3 мин) → скачивание CSV → парсинг через `fgetcsv` над `php://memory` (RFC 4180, автодетект `,` или `;`) → JSON `{"rows":[{campaign_id, campaign_name, sku, spend, views, clicks}]}`. `withAuthRetry()` один раз ретраит запрос при 401 (через внутренний `OzonAuthExpiredException`), 403 сигнализируется как permanent failure без ретрая. |
| 1.9 | 2026-04-15 | MarketplaceAds: команды CLI переименованы в единый паттерн `app:marketplace-ads:*` — `marketplace-ads:load` → `app:marketplace-ads:load`, `marketplace-ads:reprocess` → `app:marketplace-ads:reprocess`. Приводит именование к общему для проекта префиксу `app:` (как у `app:marketplace:wb-daily-sync` и пр.). |
| 1.10 | 2026-04-15 | Marketplace: UI модалки «Новое подключение» (`templates/marketplace/index.html.twig`) поддерживает два типа подключения — «Основное (Seller API)» и «Реклама (Performance API)». Тип `performance` доступен только при выборе Ozon, иначе опция скрыта и автоматически откатывается на `seller`. Form `action` переключается между `marketplace_connection_create` и `marketplace_connection_performance_create` без перезагрузки страницы; неактивный блок полей `disabled`, чтобы лишние поля не попадали в POST. В списке активных подключений для Performance подключения: суффикс «(Реклама)» к названию маркетплейса, скрыты кнопки «Синхронизировать», «За период», «Реализация», «Переобработать», строка синхронизации — статическая «—» (поллер статуса не запускается). |
| 1.12 | 2026-04-22 | MarketplaceAds: `OzonAdRawDataParser` теперь принимает обе формы `raw_payload` — legacy flat `{"rows":[…]}` и nested `{"campaigns":[{campaign_id, campaign_name, rows:[…]}]}`, записываемый `DownloadOzonAdReportHandler` (шаг 4 async-poll редизайна). Парсер диспатчится по наличию ключа `campaigns`, пробрасывает `campaign_id` / `campaign_name` из родительского объекта в каждую row и делегирует агрегацию общему пути. Фикс silent no-op: до этого nested-payload уходил в `[]` → `ProcessAdRawDocumentAction` создавал 0 `AdDocument` и помечал `AdRawDocument` как `PROCESSED`, UI «Эффективность рекламы» показывал 0 затрат. Unit-тесты гарантируют идентичность entries для обеих форм. |
| 1.13 | 2026-04-23 | MarketplaceAds: backpressure по in-flight pending_reports. Добавлен `OzonAdPendingReportRepository::countInFlightByCompany`. `RequestOzonAdBatchHandler` перед POST проверяет COUNT in-flight ≥ 3 (Ozon лимит «активных отчётов» на аккаунт) и откладывает сообщение на 60с без инкремента `rateLimitAttempts`. `BATCH_SPACING_MS = 90_000` из `FetchOzonAdStatisticsHandler` удалён — spacing больше не нужен при наличии backpressure. |
| 1.14 | 2026-04-23 | MarketplaceAds: `MAX_RATE_LIMIT_ATTEMPTS` в `RequestOzonAdBatchHandler` снижен 10 → 3. После v1.13 backpressure 429 по лимиту активных отчётов невозможен; 3 попыток (3 минуты) достаточно для transient глобального throttle'а. Тесты обновлены: `rateLimitAttempts` boundary значение теперь 3, инкремент-тест использует 1 → 2. |
| 1.15 | 2026-04-23 | MarketplaceAds: `OzonAdReportPoller::MAX_AGE_BEFORE_ABANDON_SECONDS` увеличен 3600 → 10 800 (1ч → 3ч). По итогам инцидента 22–23 апреля 2026: Ozon Performance под нагрузкой держит отчёты в очереди генерации до 2 часов; 1-часовой порог приводил к ложным ABANDONED. Unit-тест `testMissingFromListAndOldAbandons` обновлён (ageSeconds: 3700 → 10 900). |
| 1.16 | 2026-04-23 | MarketplaceAds: `OzonAdReportPoller::reconcileOne` защищён от гонки в terminal-OK ветке. Проверка affected rows от `updateStateWithSchedule`: при 0 строк (уже финализирован параллельно) `DownloadOzonAdReportMessage` не диспатчится, пишется `warning` с `companyId` / `reportUuid` / `pendingReportId`. Гарантирует контракт «OK видна в БД до прихода message» для `DownloadOzonAdReportHandler`. Добавлены unit-тесты `testOkStateDispatchesDownloadWhenUpdateAffectedRow` и `testOkStateSkipsDownloadDispatchWhenUpdateReturnsZeroRows`. |
| 1.17 | 2026-04-23 | MarketplaceAds: ПЕРЕДЕЛКА polling. `OzonAdReportPoller` теперь делает per-UUID polling через `GET /api/client/statistics/{uuid}` вместо сломанного `GET /api/client/statistics/list` (инцидент 23.04.2026: listing возвращал `total=0` при реально готовых отчётах — 4 pending_reports висели в REQUESTED 4+ часа, force-download по UUID подтвердил state=OK). Новый метод `OzonAdClient::pollOneReport(companyId, uuid): array{state, raw}`. Старый `listReportsForCompany` помечен `@deprecated` (не удалён — может пригодиться для диагностики). Защита от race в terminal-OK ветке (v1.16) сохранена: UPDATE → check rows → dispatch. Permanent-exception path сохранён: `OzonPermanentApiException` от `pollOneReport` финализирует row как ERROR без ожидания 3h до ABANDONED (ловится внутри `pollAndReconcile`, транзиентные ошибки продолжают пролетать в внешний catch). Force-abandon через `MAX_AGE_BEFORE_ABANDON_SECONDS = 10 800` (v1.15) сохранён. Сигнатура `OzonAdReportPoller::__invoke` упрощена: `(string $companyId): PollResult` (убран параметр `$now` — создаётся внутри). Количество HTTP calls: с 1/tick/company до N/tick/company, где N = in-flight reports (ограничено backpressure v1.13 сверху 3), итого ≤ 3×companies HTTP-calls за тик. Переписаны unit-тесты `OzonAdReportPollerTest` (7 новых кейсов: ok/dispatch, in-progress/reschedule, error/finalize, old-in-progress/force-abandoned, per-uuid-exception/isolation, ok+0rows/skip-dispatch, permanent-exception/finalize-error). Интеграционный `OzonPollReportsCommandTest` перепрофилирован на `pollOneReport`-контракт. |
| 1.18 | 2026-04-23 | MarketplaceAds: downloaded Ozon-отчёты теперь сохраняются как файлы на диск через StorageService (путь: `marketplace-ads/<companyId>/<uuid>.<csv|zip>`), а не в БД. Расширение определяется по Content-Type → magic bytes (PK\x03\x04 → zip, иначе csv). `raw_payload = '{}'` (поле NOT NULL). `status=draft` («загружено, но не обработано»). Парсинг (`ProcessAdRawDocumentMessage`) временно отключён — `DownloadOzonAdReportHandler` больше не диспатчит `ProcessAdRawDocumentMessage`, аналогично `LoadAdDataCommand`, `ReprocessAdDataCommand`, `ReprocessAdRawDocumentAction`. `OzonAdClient::fetchReportContent(companyId, uuid): {body, contentType}` — новый публичный метод без парсинга CSV. В UI «История загрузок» добавлена кнопка «Открыть» на каждый день периода; в «Сырых документах» — одна кнопка «Открыть» на строку с `hasFile=true`. Endpoint: `GET /marketplace-ads/raw-documents/{id}/download` (IDOR через `ActiveCompanyService` + `findByIdAndCompany`, BinaryFileResponse с `Content-Disposition: attachment`). `AdLoadJobsListController` теперь резолвит `files` за период каждого job'а. Парсер (`ProcessAdRawDocumentAction`, `ProcessAdRawDocumentHandler`, `ProcessAdRawDocumentMessage`), entities `AdDocument` / `AdDocumentLine` не удалялись — будут переиспользованы при возврате парсинга отдельной задачей. |
| 1.19 | 2026-04-23 | MarketplaceAds / Task-11.1: подготовка схемы для cron-driven pipeline. Новая таблица `marketplace_ad_scheduled_batches` — план последовательной обработки батчей Ozon Performance (1 активный отчёт на аккаунт, батчи по 10 кампаний, ≤ 62 дня). Заменяет event-driven (Messenger + DelayStamp) подход, не справлявшийся с ~26 батчами при 260 кампаниях (ложные rate-limited > N attempts). Колонки: `id` (UUID PK), `job_id` (FK → `marketplace_ad_load_jobs.id`), `company_id`, `marketplace` (default `'ozon'`), план — `campaign_ids JSONB`, `date_from`/`date_to`, `batch_index`; state machine `state` (`PLANNED → IN_FLIGHT → OK | FAILED | ABANDONED`, default `PLANNED`); scheduling — `scheduled_at`/`started_at`/`finished_at` (`TIMESTAMP(0) WITHOUT TIME ZONE` + `DC2Type:datetime_immutable`); ozon — `ozon_uuid`; storage — `storage_path`, `file_hash`, `file_size`; retry — `retry_count`, `last_error`; `created_at`/`updated_at`. Индексы под hot-paths: `idx_asb_scheduler (scheduled_at) WHERE state='PLANNED'` (planner/poster), `idx_asb_poller (id) WHERE state='IN_FLIGHT'` (poller), `idx_asb_job (job_id, state)` (finalizer), UNIQUE `idx_asb_job_batch (job_id, batch_index)` (идемпотентность планирования). Только схема — Entity/Repository/команды появятся в Task-11.2+. |
| 1.31 | 2026-04-24 | MarketplaceAds / Task-13a: auto-extract batch'ей интегрирован в Poller. `AdBatchPollerCommand::downloadAndFinalize` после успешного `$this->em->flush()` (когда batch в OK, storage_path записан) вызывает новый публичный `ExtractBatchesToRawDocumentsAction::processBatch(AdScheduledBatch): array{processed, skipped}` — точечная версия `__invoke` для одного уже-OK-батча. Общая логика (`extractCsvsFromBatch` → `createOrFindRawDocument` → dispatch `ProcessAdRawDocumentMessage`) вынесена в private `processBatchInternal(AdScheduledBatch): array{processed, skipped, dispatchIds}`, переиспользуется и из `__invoke` и из `processBatch`. `processBatch` делает один `em->flush()` + dispatch по dispatchIds после успешного прохода (flush до dispatch — handler должен увидеть свежий AdRawDocument в БД). Безопасность завершения: `try/catch (\Throwable)` вокруг вызова `processBatch` в Poller — если auto-extract падает (битый zip, пропал файл на диске, DB hiccup), batch **не** марким как FAILED (файл скачан, state=OK сохраняется), пишется `ERROR: Poller: auto-extract failed, batch remains for manual extraction`. Идемпотентность сохранена на уровне (company, batch, filename) через существующий `AdRawDocumentRepository::findByBatchAndFilename`: повторный вызов (cron-перезапуск после частичного сбоя / ручная кнопка на уже обработанном job'е) → `skipped++`, без дубликатов AdRawDocument и без повторных messages. Кнопка «Обработать» (`ExtractBatchesController`, `__invoke`) **сохранена** как safety-net: обрабатывает job целиком через `findDownloadableByJobId` — fallback при сбое auto-extract'а, retry после фикса парсера, ручное тестирование. DI `AdBatchPollerCommand`: добавлен `ExtractBatchesToRawDocumentsAction $extractAction` (после `EntityManagerInterface`). Unit-тесты: `ExtractBatchesToRawDocumentsActionTest` +4 (processBatch happy path / идемпотентность existing doc → skipped=1 / zip с multiple csv → processed=2 / propagation extraction error). `AdBatchPollerCommandTest` unit обновлён: ожидание `extractAction->processBatch($batch)` в `testOkStateDownloadsAndMarksOk`, новый `testOkStateAutoExtractFailureDoesNotMarkBatchFailed`. Integration `AdBatchPollerCommandTest` +3: `testOkStateAutoExtractsRawDocumentAndDispatchesMessage` с **реальным StorageService** (tmp-root, не mocked), `testAutoExtractIsIdempotentOnRepeatedProcessBatchCall` (второй вызов → skipped=1), `testAutoExtractFailureDoesNotMarkBatchFailed` (mocked storage возвращает пустой absolutePath → file_exists=false → extract throw → batch остаётся OK без AdRawDocument, fallback через кнопку). Unique-constraint`(company_id, marketplace, report_date)` на `marketplace_ad_raw_documents` снят в v1.29. `PATTERNS.md` / `ARCHITECTURE.md` entities — без изменений. |
| 1.30 | 2026-04-24 | MarketplaceAds / Task-12-test parser: `OzonPerformanceCsvParser` (`Infrastructure/Api/Ozon/`) — нативный CSV-парсер для payload'ов, записанных `ExtractBatchesToRawDocumentsAction` (v1.29) с маркером `batch_id=<uuid>\nfilename=<name>\n---\n<csv>`. Раньше `OzonAdRawDataParser` ожидал только JSON (`{"campaigns":…}`/`{"rows":…}`), и dry-run всех 10 CSV внутри zip'а давал `ProcessAdRawDocumentHandler` → FAILED на `\JsonException`. Новый парсер: (1) sniff'ит payload — если `str_starts_with(payload, 'batch_id=')` → CSV-путь, иначе → делегирование в композитный `OzonAdRawDataParser` (обратная совместимость с legacy-документами старого Messenger-pipeline'а); (2) CSV-путь: `splitMarker` → `stripPreamble` (вытаскивает `campaign_id` из `№\s*(\d+)` и `campaign_name` как полный preamble-текст `Кампания по продвижению товаров № X, период …`) → `iterateCsvAssocRows` через `fgetcsv` над `php://memory` с автодетектом `;`/`,` и BOM-очисткой → `isFooterOrEmptyRow` отсекает «Всего» + пустой sku → агрегация bcmath по (campaignId, parentSku) с HALF-UP +0.005 на финальной стадии; `campaign_id` fallback — из filename маркера (`<campaignId>_<from>-<to>.csv`). `campaignName` приходит прямо из Ozon-preamble — то, что видит пользователь в UI Ozon, вместо искусственного «Кампания № X». CSV-хелперы копированы из private-методов `OzonAdClient` (где они обслуживают старый CSV → JSON → raw_payload путь `convertCsvToRowsByDate`) — дедупликация после выпиливания старого pipeline (Task-13+), чтобы не трогать работающую логику `FetchOzonAdStatisticsHandler`. DI: `OzonAdRawDataParser` теперь **без тега** `marketplace_ads.raw_data_parser` (остаётся сервисом-зависимостью), `OzonPerformanceCsvParser` получил тег и инжектится в `ProcessAdRawDocumentAction::$parsers` как единственный парсер для `marketplace=ozon`; сам класс `OzonAdRawDataParser` **не изменён** — нужен для обработки остатков JSON-документов через делегирование. Unit-тесты `OzonPerformanceCsvParserTest` (10 веток: supports / preamble+footer агрегация / filename-fallback при отсутствии preamble / multi-sku агрегация / footer + empty-sku отсекаются / empty-csv → []; маркер без `---` → \RuntimeException; JSON-делегирование без маркера / `{}` → []; invalid JSON → \JsonException; legacy `,`-delimiter CSV). Existing `OzonAdRawDataParserTest` + `ProcessAdRawDocumentActionTest` (integration) проходят без изменений, т.к. новый парсер делегирует JSON-формат в нетронутый старый класс. |
| 1.29 | 2026-04-24 | MarketplaceAds / Task-12-test: `ExtractBatchesToRawDocumentsAction` — ручная обработка batch-файлов нового cron-driven pipeline через UI-кнопку «Обработать». Dry-run этап перед автоматизацией в Poller (Task-13): для всех OK-батчей job'а (`AdScheduledBatchRepository::findDownloadableByJobId(jobId, companyId)`) распаковывает zip (или читает одиночный csv) через `ZipArchive` + `StorageService::getAbsolutePath()`, на каждый CSV создаёт `AdRawDocument` с `raw_payload = "batch_id=<uuid>\nfilename=<name>\n---\n<csv>"` и диспатчит `ProcessAdRawDocumentMessage` в `async_pipeline`. Идемпотентность: новый метод `AdRawDocumentRepository::findByBatchAndFilename(companyId, batchId, filename)` ищет существующий документ по префиксу `raw_payload LIKE` (escaped `%`/`_`/`\`) — повторный клик даёт `skipped=N`, без дубликатов и повторных messages. Снято UNIQUE `(company_id, marketplace, report_date)` на `marketplace_ad_raw_documents` (`Version20260424120000`): batch нового pipeline'а содержит до 10 CSV/день (по одному на кампанию), старый UNIQUE блокировал это полностью; идемпотентность теперь обеспечивается маркером в `raw_payload`. Новый controller `ExtractBatchesController` (`POST /marketplace-ads/jobs/{jobId}/extract-batches`, `ROLE_COMPANY_OWNER`, CSRF `extract-batches-<jobId>`): делегирует Action → redirect на `marketplace_ads_index` с flash `Обработка запущена: N документов в очереди, пропущено M, ошибок K`. IDOR-guard: `findDownloadableByJobId` фильтрует по `companyId` из `ActiveCompanyService`, чужой jobId → «0 processed» без exception. UI: `AdLoadJobsListController` добавил в JSON поле `extractToken` (`CsrfTokenManagerInterface::getToken('extract-batches-<jobId>')`) для jobs в `completed`/`partial_success` с батчами и `ROLE_COMPANY_OWNER`; JS рисует `<form POST>` с токеном + `onclick=confirm('Запустить обработку…')`. Flash-блок добавлен в `marketplace_ads/index.html.twig`. Zip-файлы на диске **не удаляются** — важно для повторных нажатий «Обработать» без 30-60 минутной перезагрузки с Ozon (лимит 1 активная выгрузка). Существующий `ProcessAdRawDocumentHandler` / `ProcessAdRawDocumentAction` / парсер не трогались — это dry-run этап. Unit-тесты `ExtractBatchesToRawDocumentsActionTest` (8 веток: csv / zip / non-CSV в zip игнорируются / corrupted zip / unknown extension / missing file / null storage_path / buildRawPayload format); integration `ExtractBatchesControllerTest` (happy / idempotent-skip / foreign-job IDOR / invalid CSRF). `AdRawDocumentRepository` добавлен в `BypassFinals::allowPaths` для mock'ов. |
| 1.28 | 2026-04-24 | MarketplaceAds / Task-11.9a-fix: UTC-таймстампы в модуле. Все `new \DateTimeImmutable()` в `AdScheduledBatch` (конструктор + сеттеры `setScheduledAt`, `setStartedAt`, `setFinishedAt`, `markUpdatedAt`), `AdBatchPlanner::planBatchesForJob`, `AdBatchSchedulerCommand::execute`, `AdBatchPollerCommand::processBatch`/`downloadAndFinalize` теперь принудительно нормализуются в UTC (`new \DateTimeZone('UTC')` в конструкторе или `->setTimezone('UTC')` в сеттерах Entity). Инцидент первого prod-run'а Task-11.9a: 26 PLANNED-батчей повисли на 3 часа, потому что PHP писал локальную Europe/Moscow (+3) в `TIMESTAMP WITHOUT TIME ZONE`, а `findNextPlanned()` сравнивает `scheduled_at <= NOW()`, где Postgres `NOW()` возвращает UTC — условие «становится true» лишь через 3 часа, scheduler-cron бездействовал весь этот период. Ручной hotfix (`UPDATE ... SET scheduled_at = scheduled_at - INTERVAL '3 hours' WHERE state='PLANNED'`) — временный; это коммит устраняет корень. Область ограничена модулем MarketplaceAds (`AdScheduledBatch` и связанные команды/сервис); `AdLoadJob`, `AdRawDocument` и другие модули не трогались. Тип колонки `TIMESTAMP WITHOUT TIME ZONE` не менялся — TIMESTAMPTZ потребовал бы миграцию данных, это отдельная задача. `AdLoadJobRepository::markCompleted`/`markFailed`/`markPartialSuccess` уже писали через SQL `NOW()` — на стороне Postgres (UTC), коррекции не требовалось. Тесты: (1) новый интеграционный `AdBatchPlannerTest::testFirstBatchIsDueImmediatelyVsPostgresNow` — через `SELECT EXTRACT(EPOCH FROM (scheduled_at - NOW()))::bigint` подтверждает, что первый batch становится due в пределах ±5 секунд от `planBatchesForJob`-запуска (до фикса delta была бы +10800); (2) `AdBatchSchedulerCommandTest::testRateLimitReschedulesAndIncrementsRetry` переведён на Postgres-side сравнение delta, чтобы не зависеть от PHP default TZ при Doctrine-десериализации `datetime_immutable`; (3) `AdScheduledBatchBuilder` — дефолтные `dateFrom`/`dateTo`/`scheduledAt`/`startedAt`/`finishedAt` явно UTC, что делает round-trip через БД консистентным независимо от TZ тестовой среды (docker: Europe/Moscow, host: UTC). |
| 1.27 | 2026-04-23 | MarketplaceAds / Task-11.9a: включение cron-driven pipeline и переключение HTTP-контроллера на Planner. В `docker/cron/app.cron` добавлены три новые задачи (supercronic, все с `--no-interaction --quiet`): `app:marketplace-ads:scheduler` каждую минуту (берёт 1 PLANNED батч → POST `/statistics` → IN_FLIGHT), `app:marketplace-ads:poller` каждую минуту + ещё раз с offset'ом 30 секунд (обрабатывает все IN_FLIGHT — poll + download + финализация; `LockableTrait` защищает от overlap), `app:marketplace-ads:finalizer` каждую минуту (RUNNING jobs → COMPLETED/FAILED/PARTIAL_SUCCESS по агрегату batch'ей). Старый `app:marketplace-ads:ozon-poll-reports` (каждые 2 мин) **оставлен** — обслуживает остатки `OzonAdPendingReport`'ов старого Messenger-pipeline'а до Task-11.9b. `DispatchOzonAdLoadAction` переключён с диспатча `LoadOzonAdStatisticsRangeMessage` на синхронный вызов `AdBatchPlanner::planBatchesForJob()` + переход `markRunning` (иначе Finalizer не обнаружит job через `findAllRunning`). Новый guard: период `> MAX_DAYS_PER_LOAD=62 дней` → `\DomainException` с понятным сообщением «Период X дней превышает лимит Ozon Performance API (62 дней). Разбейте период на несколько загрузок.» — заменяет поведение старого Handler'а, который сам разбивал на чанки. Follow-up: `OzonAdInitialLoadController` (`POST /api/.../initial-load`, Jan 1 → yesterday) теперь в большинство календарного года отдаёт 400 с этим сообщением — multi-chunk поддержка отложена на отдельную задачу. На момент Task-11.9a: старый Messenger-pipeline (`FetchOzonAdStatisticsHandler`, `RequestOzonAdBatchHandler`, `DownloadOzonAdReportHandler`, `OzonAdReportPoller`), таблицы `marketplace_ad_pending_reports` / `marketplace_ad_raw_documents` и Message-классы не удалены — работают в параллель. Удаление — Task-11.9b. Unit-тест `DispatchOzonAdLoadActionTest` обновлён: мок `AdBatchPlanner` вместо `MessageBusInterface`, happy-path использует 10-дневный период (был Jan 1 → yesterday), + новые тесты для 62-дневного guard'а и для `Planning error → markFailed + rethrow`. Новый integration-тест `OzonAdLoadRangeControllerTest`: happy-path (Planner вызван × 1, `async_ads`-транспорт пуст — старый Messenger не задействован), 63-дневный период → 400 без persist'а job'а, reversed dates → 400. Из `OzonAdInitialLoadControllerTest` убран `testHappyPathReturns200WithJobId` (initial-load теперь календарно-зависим после 62-дневного guard'а). |
| 1.26 | 2026-04-23 | MarketplaceAds / Task-11.8: UI готов к включению cron-driven pipeline. Новый controller `AdScheduledBatchDownloadController` (`GET /marketplace-ads/batches/{id}/download`, IDOR через `ActiveCompanyService` + новый `AdScheduledBatchRepository::findByIdAndCompany($id, $companyId): ?AdScheduledBatch`). Выдаёт `BinaryFileResponse` файла из `StorageService` с `Content-Disposition: attachment; filename=ozon-ad-batch-<idx>-<from>_<to>.<ext>`. 404-инварианты: чужая company / `storage_path IS NULL` / файла нет на диске. `AdLoadJobsListController` (API `/api/marketplace-ads/load-jobs`) теперь enrich'ит каждый job новым полем `batchStats`: `{total, ok, failed, pending, hasBatches}` (через `countStatesForJob`); `files` для jobs нового pipeline'а (`hasBatches=true`) приходит из `findDownloadableByJobId` с `kind='batch'` + `batchIndex` / `dateFrom` / `dateTo` / `campaignCount` / `fileSize`, для legacy — старый `kind='raw'` путь сохранён (AdRawDocument). Twig `marketplace_ads/index.html.twig`: колонка «Чанки» переименована в «Прогресс» (три бейджа `X/N OK` + pending + failed для новых, `Чанки: N` для legacy); status-badge получил `partial_success` → orange `bg-warning` «Частично»; JS `renderJobFiles` ветвится на `kind` — «Открыть batch N (DD.MM.YYYY–DD.MM.YYYY)» для батчей, прежнее «Открыть DD.MM.YYYY» для legacy. В активном job-widget добавлен `partial_success` → alert-warning с `failureReason`. JS-поллер (Task-11.7 hotfix уже в master) терминальные статусы признаёт `completed` / `failed` / `partial_success`. Integration-тесты: 4 для нового download-контроллера (happy / IDOR-404 / no storage / no file) + 2 для list-контроллера (новый `batchStats` + `kind='batch'` files, legacy без батчей). Старый UI-путь (`AdRawDocument` кнопки «Открыть DD.MM.YYYY») сохранён — Messenger-pipeline всё ещё активен; переключение контроллера «Загрузить за период» на Planner — Task-11.9. |
| 1.25 | 2026-04-23 | MarketplaceAds / Task-11.7: `AdJobFinalizerCommand` (`app:marketplace-ads:finalizer`) — третья и последняя cron-команда cron-driven pipeline. Сканирует все `AdLoadJob` в статусе RUNNING через новый `AdLoadJobRepository::findAllRunning(): list<AdLoadJob>` (глобально, без company-фильтра — cron cross-tenant), для каждого читает агрегат `AdScheduledBatchRepository::countStatesForJob()`. Решение: `PLANNED > 0 OR IN_FLIGHT > 0` → рано, скип; `ok === total` → `markCompleted`; `ok === 0` → `markFailed` с reason «All N batches failed»; микс → **новый** `markPartialSuccess` с reason «N of M batches failed». Batch'ов нет → warning-лог, не финализируем (Planner-аномалия). Новый статус `AdLoadJobStatus::PARTIAL_SUCCESS` ('partial_success'): `isTerminal()` теперь включает его, `isActive()` — нет; label «Частично завершён»; Messenger-pipeline (`AdLoadJobFinalizer`) не использует. Новый метод `AdLoadJobRepository::markPartialSuccess($jobId, $companyId, $reason): int` — raw DBAL UPDATE, идемпотентен (`status IN pending/running`), IDOR-safe (`company_id` в WHERE), пишет в существующий столбец `failure_reason`. `LockableTrait` против overlap. Per-job try/catch: сбой на одном job'е не прерывает сканирование остальных. **Не подключена в cron** — все три команды (scheduler / poller / finalizer) включатся одним релизом перед Task-11.9. Frontend-шаблон `marketplace_ads/index.html.twig` пока показывает PARTIAL_SUCCESS как error-alert с `failureReason` (acceptable MVP, UI-polish — Task-11.8). Unit (9 веток: empty / no-batches / PLANNED / IN_FLIGHT / all-OK / all-FAILED / all-ABANDONED / mix-partial / per-job isolation) + integration (empty, full-OK, full-FAILED, mixed → PARTIAL_SUCCESS, PLANNED-blocking, acceptance-кейс с двумя jobs, no-batches warning). |
| 1.24 | 2026-04-23 | MarketplaceAds / Task-11.6: `AdBatchPollerCommand` (`app:marketplace-ads:poller`) — cron processor всех IN_FLIGHT-батчей в cron-driven pipeline. За один запуск: `findAllInFlight` → per-батч `OzonAdClient::pollOneReport(companyId, uuid)` → switch по normalized state: `OK`/`READY` → `fetchReportContent` + `StorageService::storeBytes` (путь `marketplace-ads/<companyId>/<uuid>.<csv\|zip>`) → batch=OK + storagePath/Hash/Size + finishedAt; `ERROR`/`CANCELLED` → batch=FAILED + lastError с state + finishedAt; `NOT_STARTED`/`IN_PROGRESS` → не трогаем; unknown state → warning-лог. Per-batch try/catch: `OzonPermanentApiException` → FAILED; прочие `\Throwable` (сеть, 5xx) → continue на следующий батч (батч остаётся IN_FLIGHT для retry на следующем тике). Sanity-guard: IN_FLIGHT без `ozon_uuid` → FAILED с "Invariant violation" (этот инвариант гарантируется Scheduler'ом в транзакции Task-11.5, но Poller защищает от рассинхрона). Ozon не лимитирует `GET /statistics/{uuid}`, поэтому обрабатываем весь queue за один тик. Новый helper `App\MarketplaceAds\Application\Service\OzonReportExtensionDetector::detect(body, contentType): string` — детекция `csv`/`zip` по magic bytes → Content-Type (fallback), вынесено из `DownloadOzonAdReportHandler::detectExtension` (старый Messenger-pipeline тоже теперь использует этот helper — DRY). Команда **не подключена в cron** в Task-11.6: ждёт Finalizer (Task-11.7), включится одним релизом. `StorageService` добавлен в `BypassFinals::allowPaths` для mock'ов. Unit (9 веток: empty / OK / ERROR / CANCELLED / NOT_STARTED / IN_PROGRESS / unknown state / permanent / invariant-violation / transient-isolation) + integration (mixed queue 2 OK + 1 ERROR, ZIP extension, NOT_STARTED, transient per-batch изоляция). |
| 1.23 | 2026-04-23 | MarketplaceAds / Task-11.5: `AdBatchSchedulerCommand` (`app:marketplace-ads:scheduler`) — cron processor одного PLANNED-батча в cron-driven pipeline. Берёт батч через `AdScheduledBatchRepository::findNextPlanned()` (FOR UPDATE SKIP LOCKED внутри явной `Connection::beginTransaction()`), делает POST `/api/client/statistics` через новый публичный `OzonAdClient::postStatistics(companyId, campaignIds, dateFrom, dateTo): string` (чистая обёртка — не пишет в `marketplace_ad_pending_reports` в отличие от `requestOneBatch`), переводит батч в IN_FLIGHT с `ozonUuid` и `startedAt`. Один инвокейшн = один батч, естественный rate-limiter под лимит Ozon «1 активная выгрузка на аккаунт» при минутном cron. Обработка исключений: `OzonRateLimitException` (429) → PLANNED + `scheduled_at += 5 мин` + `retryCount++` + `lastError`, commit, exit SUCCESS; `OzonPermanentApiException` (403/нет creds) → FAILED + `finishedAt`, commit, exit SUCCESS; прочие `\Throwable` (5xx/сеть/JSON) → `rollBack`, батч остаётся PLANNED неизменённым, exit FAILURE. Переиспользует существующий `App\MarketplaceAds\Exception\OzonRateLimitException` вместо `Ozon429Exception`. Команда **не подключена в cron** в Task-11.5: ждёт Poller (Task-11.6) и Finalizer (Task-11.7), включится одним релизом. Unit (mocks для Connection / EM / Repository / OzonAdClient) + integration (реальный Postgres, happy / 429 / permanent / transient-rollback / FOR UPDATE SKIP LOCKED через параллельное DBAL-соединение / --help). |
| 1.22 | 2026-04-23 | MarketplaceAds / Task-11.3: сервис `AdBatchPlanner` (`src/MarketplaceAds/Application/Service`) — генерация плана батчей для `AdLoadJob` в cron-driven pipeline. `planBatchesForJob(jobId, companyId, dateFrom, dateTo): int` делает `GET /api/client/campaign` через новый публичный `OzonAdClient::listAllSkuCampaigns(companyId): list<array{id,title,state}>` (обёртка над существующим private `listSkuCampaigns`, без фильтров по state/recency — они зона ответственности потребителя), разбивает список на чанки по `BATCH_SIZE=10` и создаёт N `AdScheduledBatch` в state=PLANNED с `scheduledAt = now() + batchIndex * SPACING_SECONDS` (`SPACING_SECONDS=120` — буфер для 1-активной-выгрузки-на-аккаунт лимита Ozon). Идемпотентен: при существующих батчах для `jobId` через `findByJobId` ранний выход без ходьбы в Ozon — снимает гонку с UNIQUE `(job_id, batch_index)`. Пустой список кампаний → `RuntimeException`. Один `em->flush()` в конце цикла (N persist + 1 flush), для 260 кампаний ~26 INSERT'ов за секунды. Dead code — подключение cron-командами в Task-11.5+. Существующий `OzonAdClient::prepareStatisticsBatches` не трогали (старый pipeline работает). Unit + integration тесты: 260→26 батчей, spacing 120s, PLANNED + retry=0, empty→throw, идемпотентный повтор, UNIQUE не нарушается. `AdScheduledBatchRepository` добавлен в `BypassFinals::allowPaths` в `tests/bootstrap.php` для mock'ов в unit-тестах. |
| 1.21 | 2026-04-23 | MarketplaceAds / Task-11.2 review fix: IDOR-guard в `AdScheduledBatchRepository`. Методы `findByJobId`, `findDownloadableByJobId`, `countStatesForJob` теперь принимают обязательный `string $companyId` и фильтруют по `company_id` в WHERE (через QB `andWhere` и raw DBAL bind) — соответствие правилу CLAUDE.md §Безопасность. Критично для `findDownloadableByJobId`, который будет вызываться из UI Task-11.8: без защиты подмена `jobId` в URL отдавала бы download-ссылки чужой компании. Добавлены регрессии `testFindByJobIdReturnsEmptyForForeignCompanyIDOR`, `testCountStatesForJobReturnsEmptyForForeignCompanyIDOR`, `testFindDownloadableByJobIdReturnsEmptyForForeignCompanyIDOR`. |
| 1.20 | 2026-04-23 | MarketplaceAds / Task-11.2: Entity + Repository для `marketplace_ad_scheduled_batches`. `AdScheduledBatch` (Entity, `class`, репозиторий через `repositoryClass`) — поля повторяют схему Task-11.1, `campaignIds: list<string>` маппится на JSONB (`type: 'json'`), `state: AdScheduledBatchState` (enumType), таймстампы — `datetime_immutable`. Конструктор принимает внешний `id` (UUID), `jobId`, `companyId`, `campaignIds`, `dateFrom`/`dateTo` (нормализация до 00:00, inverted-range → `\DomainException`), `batchIndex`, `scheduledAt`; дефолты `marketplace='ozon'`, `state=PLANNED`, `retryCount=0`. Сеттеры `setState` / `setOzonUuid` / `setStartedAt` / `setFinishedAt` / `setStoragePath` / `setFileHash` / `setFileSize` / `setRetryCount` / `setLastError` / `setScheduledAt` обновляют `updatedAt` через публичный `markUpdatedAt()`. Enum `AdScheduledBatchState` (`PLANNED` / `IN_FLIGHT` / `OK` / `FAILED` / `ABANDONED`, `isTerminal()`). `AdScheduledBatchRepository` (`final`, extends `ServiceEntityRepository`): `findNextPlanned()` — native SQL `SELECT ... WHERE state='PLANNED' ORDER BY scheduled_at ASC, batch_index ASC LIMIT 1 FOR UPDATE SKIP LOCKED` через `createNativeQuery` + `ResultSetMappingBuilder` (DQL `SKIP LOCKED` не поддерживает); `findAllInFlight()` / `findByJobId($jobId)` / `findDownloadableByJobId($jobId)` — QB; `countStatesForJob($jobId): array<string,int>` — raw DBAL `GROUP BY state`; `save(...)` — persist + flush сразу (scheduler ожидает физической видимости). Partial-индексы из миграции (`idx_asb_scheduler`, `idx_asb_poller`) в Entity не объявлены (ORM-атрибуты не выражают WHERE). Dead code — никто пока не вызывает, используется в Task-11.3+. Builder `AdScheduledBatchBuilder` (tests/Builders/MarketplaceAds). Интеграционные тесты покрывают save/reload round-trip, `findNextPlanned` (порядок, tie-break, пропуск non-PLANNED, SKIP LOCKED concurrency через параллельное DBAL-соединение с `FOR UPDATE`), `findAllInFlight` (`startedAt ASC`), `findByJobId`, `countStatesForJob` (агрегаты + пустой результат по неизвестному job), `findDownloadableByJobId` (фильтр `storage_path IS NOT NULL`), обновление `updatedAt` в сеттерах, валидация inverted-range и отрицательного `fileSize`. |
| 1.11 | 2026-04-19 | MarketplaceAds: серия задач по Ozon Ads pipeline. Новые Entity: `AdLoadJob` (пакетная загрузка за период, атомарный счётчик `loadedDays` через raw SQL), `AdChunkProgress` (идемпотентная фиксация успеха чанка через `UniqueConstraint` `(job_id, date_from, date_to)`). Новый Message `LoadOzonAdStatisticsRangeMessage` + handler-оркестратор: PENDING → RUNNING, разбиение диапазона на чанки ≤ 62 дня (лимит Ozon Performance API), dispatch `FetchOzonAdStatisticsMessage` на каждый чанк. `FetchOzonAdStatisticsHandler`: upsert AdRawDocument + идемпотентный `markChunkCompleted` + inc `loadedDays` только при успешной фиксации чанка. Enum `AdRawDocumentStatus` расширен кейсом `FAILED` (+`isTerminal()`); финализация job'а перешла на per-document статус и считает через `countByCompanyMarketplaceAndDateRange`. Удалены мёртвые counter-поля `processedDays` / `failedDays` из `AdLoadJob` (миграция `Version20260419080739`). Новые методы репозиториев: `AdLoadJobRepository::markCompleted` / `markFailed` / `findRecentByCompanyAndMarketplace`; `AdChunkProgressRepository::markChunkCompleted` / `countCompletedChunks`; `AdRawDocumentRepository::markFailedWithReason` / `countByCompanyMarketplaceAndDateRange` / `findByCompanyMarketplaceAndDateRange`. |
