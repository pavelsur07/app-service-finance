# ARCHITECTURE.md — VashFinDir

> **Живой документ.** Обновляется после каждого нового модуля или изменения публичного контракта.
> Читается: Claude Code (через CLAUDE.md) и Claude.ai Projects (через Knowledge).
> Версия: 1.0 / 2026-03-28

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
| `UnitEconomyCostMapping` | MarketplaceAnalytics | `string $companyId` ✅ |
| `ListingDailySnapshot` | MarketplaceAnalytics | `string $companyId` ✅ |
| `ProductImport` | Catalog | `string $companyId` ✅ |
| `ProductBarcode` | Catalog | `string $companyId` ✅ |
| `ProductPurchasePrice` | Catalog | `string $companyId` ✅ |
| `AuditLog` | Shared | `string $companyId` ✅ |
| `CashTransaction`, `MoneyAccount` и др. | Cash | `Company $company` (legacy) |

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
| `Deal`, `ChargeType` | Deals | `Company $company` (legacy) |
| `PLCategory`, `Document` и др. | legacy `src/Entity/` | `Company $company` (legacy) |

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

// Себестоимость по листингу и дате (null если не задана)
getCostPriceForListing(string $companyId, string $listingId, \DateTimeImmutable $date): ?string

// Список категорий затрат для формы маппинга юнит-экономики
// @return array<array{id: string, code: string, name: string}>
getCostCategoriesForCompany(string $companyId, string $marketplace): array
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