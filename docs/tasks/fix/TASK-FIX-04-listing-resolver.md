# TASK-FIX-04 — Резолвинг listingId в FinancialTransaction при нормализации

## 0. Сводка

- **Бизнес-цель.** Связать `FinancialTransaction` с `MarketplaceListing` при нормализации, чтобы аналитические отчёты (юнит-экономика, ABC/XYZ анализ, оборачиваемость) работали в разрезе товаров и размеров. Каждая площадка резолвит листинг по своему natural key: Ozon — по `supplierSku` (offer_id), WB — через `MarketplaceListingBarcode` по баркоду (даёт размер). Контракт расширяем — новые площадки добавляются как отдельные resolver'ы без изменения pipeline.
- **Модуль.** `App\Ingestion` (существующий).
- **Тип.** feature.
- **Ветка.** `feature/ingestion-listing-resolver`.
- **Подзадачи.** B1 `listingId` в `FinancialTransaction` + миграция · B2 `ListingResolverInterface` + реестр · B3 `OzonListingResolver` · B4 `WbListingResolver` (заглушка) · B5 Интеграция в нормализатор · B6 Тесты.
- **Затрагивает другие модули.** Да → `App\Marketplace` (читает `MarketplaceListing`, `MarketplaceListingBarcode` через Facade).
- **Требует миграции БД.** Да (ADD COLUMN `listing_id` + ADD COLUMN `listing_sku` + индекс).
- **Меняет публичный API.** Нет.

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- `App\Ingestion\Entity\FinancialTransaction` не имеет поля `listingId`.
- `App\Marketplace\Entity\MarketplaceListing` — таблица `marketplace_listings`. Natural key: `(company_id, marketplace, marketplace_sku, size)`. Поля: `marketplaceSku` (nm_id WB / Ozon SKU), `supplierSku` (offer_id Ozon / sa_name WB).
- `App\Marketplace\Entity\MarketplaceListingBarcode` — таблица `marketplace_listing_barcodes`. Поля: `listingId`, `marketplace`, `barcode`. Даёт связь баркод → листинг (с размером).
- В `OzonSellerReportMapper` каждая строка содержит `offer_id` (артикул продавца = `supplierSku`) и `sku` (Ozon id = `marketplaceSku`).
- `MarketplaceListing` индексирован по `(company_id, marketplace)` и `(marketplace, marketplace_sku)`. Индекса по `supplierSku` нет.

### 1.2 Желаемое состояние

- `FinancialTransaction.listingId: ?string` — UUID `MarketplaceListing`, nullable (fallback если не найден).
- `FinancialTransaction.listingSku: ?string` — сохраняем raw sku из источника для диагностики.
- При нормализации `NormalizeRawRecordAction` зовёт `ListingResolverRegistry::resolve($source, $companyId, $sourceData)` → `?string $listingId`.
- **Ozon:** резолвинг по `supplierSku` (`offer_id`) через `(companyId, OZON, supplierSku)`. Fallback: если `supplierSku` пуст — по `marketplaceSku`.
- **WB (заглушка):** резолвинг через `MarketplaceListingBarcode` по баркоду. В этой задаче — только интерфейс + заглушка, реализация после WB-коннектора.
- Если листинг не найден — `listingId = null`, `listingSku` заполнен из `sourceData`. Лог WARNING с `companyId`, `rawRecordId`, `sku`.
- Новый `Facade\MarketplaceListingFacade` — единственная точка входа из Ingestion в Marketplace.

### 1.3 In scope

- ADD COLUMN `listing_id`, `listing_sku` в `ingest_financial_transactions` + индекс.
- `ListingResolverInterface` + `ListingResolverRegistry`.
- `OzonListingResolver` (реальная реализация).
- `WbListingResolver` (заглушка — возвращает null, логирует).
- `App\Ingestion\Facade\MarketplaceListingFacade` — читает `MarketplaceListing` из Marketplace-модуля.
- Добавление метода в `MarketplaceListingRepository` (`findBySupplierSku`).
- Интеграция резолвера в `NormalizeRawRecordAction`.
- Миграция добавления индекса по `supplierSku` в `marketplace_listings`.

### 1.4 Out of scope

- WB реальный резолвинг через баркод — после WB-коннектора.
- Ретроактивный резолвинг существующих транзакций — отдельная задача (TASK-FIX-05).
- Резолвинг через Ozon Performance Ads — отдельный коннектор.
- Изменение `MarketplaceListing` Entity — только добавление Repository-метода.
- HTTP API.

### 1.5 Допущения и открытые вопросы

- Допущение: `supplierSku` в Ozon `transaction_list` присутствует в большинстве строк. Fallback на `marketplaceSku` если пусто.
- Допущение: `MarketplaceListingFacade` живёт в `App\Ingestion\Facade\` (Ingestion зависит от Marketplace через Facade — допустимо, Marketplace не зависит от Ingestion).
- Открытый вопрос: одна транзакция может содержать несколько товаров (bundle)? Принимаем: нет, один `offer_id` = один листинг.

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

#### `App\Ingestion\Entity\FinancialTransaction` (правка)

Добавить два поля:

| Поле | Тип PHP | Колонка БД | Nullable | Default | Инвариант / правило |
|---|---|---|---|---|---|
| `listingId` | ?string UUID | `listing_id` GUID | да | null | ссылка на `marketplace_listings.id`; не ManyToOne |
| `listingSku` | ?string | `listing_sku` VARCHAR(255) | да | null | raw sku из источника для диагностики |

Добавить методы:
- `setListing(string $listingId, string $listingSku): void` — устанавливает оба поля, `updatedAt = now`.
- `getListingId(): ?string`.
- `getListingSku(): ?string`.

### 2.2 Связи

`FinancialTransaction.listingId` → `marketplace_listings.id` (строка, не ManyToOne — разные модули).

### 2.3 Enum

N/A.

### 2.4 Матрица переходов

N/A.

---

## 3. Слой доступа к данным

### 3.1 Repository

#### `App\Marketplace\Repository\MarketplaceListingRepository` (правка — только добавление метода)

Добавить метод (не изменять существующие):

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findBySupplierSku(string $companyId, string $marketplace, string $supplierSku): ?MarketplaceListing` | Поиск по `(company_id, marketplace, supplier_sku)`. Возвращает первый найденный (supplier_sku не уникален при наличии размеров — берём любой, `listingId` достаточно для связи). | да | `?MarketplaceListing` |
| `findByMarketplaceSku(string $companyId, string $marketplace, string $marketplaceSku): ?MarketplaceListing` | Fallback: поиск по `(company_id, marketplace, marketplace_sku)`. | да | `?MarketplaceListing` |

**Работа в legacy-зоне (`App\Marketplace\Repository`) — риск 🔴.**

#### `App\Marketplace\Repository\MarketplaceListingBarcodeRepository` (правка — только добавление метода)

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findListingIdByBarcode(string $companyId, string $marketplace, string $barcode): ?string` | Поиск `listing_id` по `(company_id, marketplace, barcode)`. Для WB-резолвинга в будущем. | да | `?string` (listing_id) |

### 3.2 Query

N/A.

### 3.3 Индексы

`marketplace_listings`:
- Добавить INDEX `(company_id, marketplace, supplier_sku)` → `idx_marketplace_listing_company_supplier_sku`. Миграция: `Version20260619130000.php`.

`ingest_financial_transactions`:
- Добавить INDEX `(company_id, listing_id)` → `idx_ftx_company_listing`. Миграция: та же.

Оба — zero-downtime (только `CREATE INDEX`, не изменение существующих колонок).

---

## 4. Слой приложения

### 4.1 Domain Contract

#### `App\Ingestion\Domain\Contract\ListingResolverInterface`

Файл: `src/Ingestion/Domain/Contract/ListingResolverInterface.php`.

Методы:
- `supports(IngestSource $source): bool` — возвращает true если резолвер обслуживает данный source.
- `resolve(string $companyId, array $sourceData): ?string` — возвращает `MarketplaceListing.id` или null. `$sourceData` — поля из `MappedTransaction.sourceData`. Чистая функция с доступом к БД через Facade, без HTTP.

### 4.2 Registry

#### `App\Ingestion\Domain\Service\ListingResolverRegistry`

Файл: `src/Ingestion/Domain/Service/ListingResolverRegistry.php`. `final class`.

Конструктор: `iterable<ListingResolverInterface>` через тег `app.ingestion.listing_resolver`.

Методы:
- `resolve(IngestSource $source, string $companyId, array $sourceData): ?string` — находит поддерживающий resolver, зовёт `resolve()`. Если нет поддерживающего — null + лог WARNING.

### 4.3 Resolver'ы

#### `App\Ingestion\Application\Service\OzonListingResolver`

Файл: `src/Ingestion/Application/Service/OzonListingResolver.php`. `final class`. Тег: `app.ingestion.listing_resolver`.

Конструктор: `MarketplaceListingFacade $listingFacade`.

Методы:
- `supports(IngestSource $source): bool` → `$source === IngestSource::OZON`.
- `resolve(string $companyId, array $sourceData): ?string`:
  1. Извлечь `offer_id` (= `supplierSku`) из `$sourceData['offer_id']` или `$sourceData['item_code']`.
  2. Если не пуст — `$listingFacade->findBySupplierSku($companyId, 'ozon', $offerSku)` → `?string $listingId`.
  3. Если null или `offer_id` пуст — fallback: взять `sku` из `$sourceData['sku']`, вызвать `$listingFacade->findByMarketplaceSku($companyId, 'ozon', $sku)`.
  4. Вернуть `$listingId` или null.
  5. Кешировать результат в `array $cache[companyId][key]` на время жизни объекта (один запрос к БД на уникальный `offer_id` за batch).

#### `App\Ingestion\Application\Service\WbListingResolver`

Файл: `src/Ingestion/Application/Service/WbListingResolver.php`. `final class`. Тег: `app.ingestion.listing_resolver`.

**Заглушка** для этой задачи.

Методы:
- `supports(IngestSource $source): bool` → `$source === IngestSource::WILDBERRIES`.
- `resolve(string $companyId, array $sourceData): ?string` → всегда null + лог WARNING `«WB listing resolver not implemented yet»`.

### 4.4 Facade

#### `App\Ingestion\Facade\MarketplaceListingFacade`

Файл: `src/Ingestion/Facade/MarketplaceListingFacade.php`. `final readonly class`.

Конструктор: `MarketplaceListingRepository $listingRepository`, `MarketplaceListingBarcodeRepository $barcodeRepository`.

Методы:
- `findBySupplierSku(string $companyId, string $marketplace, string $supplierSku): ?string` — возвращает `MarketplaceListing.id` или null.
- `findByMarketplaceSku(string $companyId, string $marketplace, string $marketplaceSku): ?string` — fallback.
- `findByBarcode(string $companyId, string $marketplace, string $barcode): ?string` — для WB (использует `MarketplaceListingBarcodeRepository`).

### 4.5 Action (правка)

#### `App\Ingestion\Application\Action\NormalizeRawRecordAction` (правка)

Добавить шаг после маппинга `MappedTransaction`:

Для каждой `MappedTransaction`:
1. Вызвать `ListingResolverRegistry::resolve($rawRecord->getSource(), $companyId, $mapped->sourceData)` → `?string $listingId`.
2. Если `$listingId !== null` — вызвать `$transaction->setListing($listingId, $mapped->sourceData['offer_id'] ?? $mapped->sourceData['sku'] ?? '')`.
3. Если null — лог WARNING с полями `companyId`, `rawRecordId`, `offer_id/sku`. `listingId` остаётся null.

### 4.6 DTO

N/A — изменения только внутри Action, DTO не меняется.

---

## 5. Асинхронность (Messenger)

Без изменений.

---

## 6. Обработка ошибок

| Класс | Когда | HTTP-статус | error.code | error.message |
|---|---|---|---|---|
| N/A — листинг не найден | Лог WARNING, `listingId = null`, нормализация продолжается | — | — | — |

Резолвинг не блокирует нормализацию — fallback в null всегда безопасен.

---

## 7. HTTP API

N/A.

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | ADD COLUMN `listing_id`, `listing_sku` в `ingest_financial_transactions` + методы Entity | — | 🔴 | unit: `setListing()` |
| B2 | Добавить методы в `MarketplaceListingRepository` + `MarketplaceListingBarcodeRepository` + индекс | — | 🔴 | integration: метод находит по supplierSku |
| B3 | `ListingResolverInterface` + `ListingResolverRegistry` + теги | — | 🟢 | unit: registry вызывает нужный resolver |
| B4 | `MarketplaceListingFacade` | B2 | 🟡 | integration: facade возвращает id |
| B5 | `OzonListingResolver` (с кешем) | B3, B4 | 🟡 | unit: offer_id → id; fallback на sku; кеш |
| B6 | `WbListingResolver` (заглушка) | B3 | 🟢 | unit: returns null |
| B7 | Правка `NormalizeRawRecordAction` | B1, B3, B5 | 🟡 | integration: после нормализации `listingId` заполнен |
| B8 | Тесты + `ARCHITECTURE.md` | все | 🟢 | tenant-leak на `listingId` |

**B1 — детализация:**
- Создаёт: `site/migrations/Version20260619130000.php`.
- Меняет: `src/Ingestion/Entity/FinancialTransaction.php`.
- Миграция: ADD COLUMN `listing_id GUID NULL`, `listing_sku VARCHAR(255) NULL` + INDEX.
- DoD: `doctrine:schema:validate` зелёный.

**B2 — детализация:**
- Меняет: `src/Marketplace/Repository/MarketplaceListingRepository.php` (добавить 2 метода).
- Меняет: `src/Marketplace/Repository/MarketplaceListingBarcodeRepository.php` (добавить 1 метод).
- **Только добавление методов — не изменять существующие.**
- DoD: методы возвращают корректные данные по companyId.

---

## 9. Ограничения и запреты

- Не изменять существующие методы `MarketplaceListingRepository` — только добавление.
- Не создавать ManyToOne между `FinancialTransaction` и `MarketplaceListing` — только строка.
- Резолвинг не блокирует нормализацию — исключения внутри resolver'а ловятся, лог WARNING, `listingId = null`.
- Не реализовывать WB резолвинг в этой задаче.
- Миграции zero-downtime: только ADD COLUMN (nullable) и CREATE INDEX.
- Performance: кеш внутри resolver'а на время батча — не Redis, только in-memory array. Каждый уникальный `offer_id` запрашивается один раз.
- Безопасность: `MarketplaceListingFacade` принимает companyId во всех методах — IDOR защита.

---

## 10. Критерии приёмки

Функциональные:
- [ ] После нормализации Ozon-транзакции с известным `offer_id` → `listingId` заполнен.
- [ ] После нормализации с неизвестным `offer_id` → `listingId = null`, `listingSku` заполнен, лог WARNING.
- [ ] Fallback: `offer_id` пуст → резолвинг по `sku`.
- [ ] WB-транзакция → `listingId = null`, лог WARNING «not implemented».
- [ ] Кеш: один `offer_id` — один запрос к БД за batch (проверяется mock-тестом).
- [ ] `ListingResolverRegistry` с пустым реестром → null, без исключения.

Технические:
- [ ] `doctrine:schema:validate --skip-sync --env=test` — зелёный.
- [ ] `lint:container --env=test` — зелёный.
- [ ] `make site-test-unit` — зелёный.
- [ ] Integration-тест: полный pipeline нормализации с `OzonListingResolver` (mock `MarketplaceListingFacade`).
- [ ] Tenant-leak: `listingId` компании A не проставляется транзакции компании B.
- [ ] `php-cs-fixer` — зелёный.
- [ ] `ARCHITECTURE.md` обновлён: `ListingResolverInterface`, `MarketplaceListingFacade`.

---

## 11. План отката

- DROP COLUMN `listing_id`, `listing_sku` — отдельная миграция. Данные теряются (nullable, аналитика ещё не запущена).
- DROP INDEX `idx_marketplace_listing_company_supplier_sku` — отдельной миграцией.
- Удалить `ListingResolverRegistry`, resolver'ы, Facade — без последствий (нормализация продолжит работать, `listingId = null`).
- Revert правки `NormalizeRawRecordAction` — нормализация работает как до задачи.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь для каждого класса.
- [x] Таблица новых полей Entity с nullable и инвариантами.
- [x] `ListingResolverInterface` — контракт с двумя методами.
- [x] Ozon fallback на `marketplaceSku` если `supplierSku` пуст — явно описан.
- [x] WB — заглушка, явно out of scope реализация.
- [x] Facade как единственная точка входа из Ingestion в Marketplace.
- [x] Кеш in-memory за batch — описан в resolver'е.
- [x] Не блокирует нормализацию — fallback в null.
- [x] Работа в legacy-зоне `Marketplace\Repository` — помечена 🔴.
- [x] Индексы с именами.
- [x] HTTP — N/A.
- [x] Out of scope: WB реализация, ретроактивный резолвинг.
- [x] Plan отката без критических потерь.
