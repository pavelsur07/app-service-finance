# ARCHITECTURE.md — VashFinDir

> **Живой документ.** Обновляется после каждого нового модуля или изменения публичного контракта.
> Читается: Claude Code (через CLAUDE.md) и Claude.ai Projects (через Knowledge).
> Версия: 1.11 / 2026-04-19

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
| `UnitEconomyCostMapping` | MarketplaceAnalytics | `string $companyId` ✅ |
| `ListingDailySnapshot` | MarketplaceAnalytics | `string $companyId` ✅ |
| `AdRawDocument` | MarketplaceAds | `string $companyId` ✅ |
| `AdDocument` | MarketplaceAds | `string $companyId` ✅ |
| `AdDocumentLine` | MarketplaceAds | `string $companyId` ✅ |
| `AdLoadJob` | MarketplaceAds | `string $companyId` ✅ |
| `AdChunkProgress` | MarketplaceAds | через `jobId` (IDOR через AdLoadJob) |
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

> **Остальные Enum** (ProductStatus, TransactionType, MarketplaceType и др.) добавлять сюда по мере реализации.
> Не угадывать значения — спрашивать или смотреть в исходниках.

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
| 1.11 | 2026-04-19 | MarketplaceAds: серия задач по Ozon Ads pipeline. Новые Entity: `AdLoadJob` (пакетная загрузка за период, атомарный счётчик `loadedDays` через raw SQL), `AdChunkProgress` (идемпотентная фиксация успеха чанка через `UniqueConstraint` `(job_id, date_from, date_to)`). Новый Message `LoadOzonAdStatisticsRangeMessage` + handler-оркестратор: PENDING → RUNNING, разбиение диапазона на чанки ≤ 62 дня (лимит Ozon Performance API), dispatch `FetchOzonAdStatisticsMessage` на каждый чанк. `FetchOzonAdStatisticsHandler`: upsert AdRawDocument + идемпотентный `markChunkCompleted` + inc `loadedDays` только при успешной фиксации чанка. Enum `AdRawDocumentStatus` расширен кейсом `FAILED` (+`isTerminal()`); финализация job'а перешла на per-document статус и считает через `countByCompanyMarketplaceAndDateRange`. Удалены мёртвые counter-поля `processedDays` / `failedDays` из `AdLoadJob` (миграция `Version20260419080739`). Новые методы репозиториев: `AdLoadJobRepository::markCompleted` / `markFailed` / `findRecentByCompanyAndMarketplace`; `AdChunkProgressRepository::markChunkCompleted` / `countCompletedChunks`; `AdRawDocumentRepository::markFailedWithReason` / `countByCompanyMarketplaceAndDateRange` / `findByCompanyMarketplaceAndDateRange`. |