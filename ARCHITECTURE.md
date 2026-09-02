# ARCHITECTURE.md — VashFinDir

> **Живой документ.** Обновляется после каждого нового модуля или изменения публичного контракта.
> Читается: Claude Code (через CLAUDE.md) и Claude.ai Projects (через Knowledge).
> Версия: 1.86 / 2026-09-01

---

## Модули (`src/`)

| Модуль | Назначение | companyId паттерн |
|---|---|---|
| `Cash` | Денежные счета, транзакции, банковский импорт, план платежей | `Company $company` (legacy) |
| `Marketplace` | WB/Ozon: продажи, возвраты, расходы, закрытие месяца | смешанный |
| `Catalog` | Товары, штрихкоды, закупочные цены | смешанный (`Product` ещё на `Company $company`) |
| `Deals` | Сделки | `Company $company` (legacy) |
| `Finance` | PnL-отчёты, кэшфлоу, фасады финансовой аналитики | `Company $company` (legacy) |
| `Company` | Компании, пользователи, приглашения, тарифы | — (владелец) |
| `Balance` | Управленческий баланс, провайдеры значений | `string $companyId` ✅ |
| `Billing` | Биллинг и подписки | — |
| `Loan` | Кредиты и займы | legacy |
| `Ai` | Интеграция с LLM | — |
| `Telegram` | Telegram-бот, вебхуки | — |
| `MoySklad` | Интеграция с МойСклад | `string $companyId` ✅ |
| `Analytics` | Аналитические запросы и дашборды | — |
| `MarketplaceAnalytics` | Аналитика маркетплейсов (витрина) | — |
| `MarketplaceAds` | Рекламные отчёты WB/Ozon: загрузка raw → распределение затрат | `string $companyId` ✅ |
| `Inventory` | Загрузка raw-остатков маркетплейсов, нормализация в StockSnapshot, UI-отчёт остатков | `string $companyId` ✅ |
| `Ingestion` | Каркас для будущих ingestion-пайплайнов, credential seam и unified raw-слой | `string $companyId` + Doctrine `company` filter ✅ |
| `Notification` | Каналы уведомлений (email и др.) | — |
| `Shared` | Общий код: ActiveCompanyService, аудит, безопасность, storage | — |
| `Admin` | Административная панель (отдельный firewall) | — |
| `Mcp` | MCP-сервер: инструменты для LLM-агентов над Cash-данными | — |
| `Report` | Построители отчётов (ДДС cashflow) | — |

**Legacy-зона** (пуста после миграции, новый код туда НЕ идёт):
`src/Entity/` · `src/Service/` · `src/Repository/` · `src/Controller/`

Сущностей здесь больше нет — каталоги пусты (только `.gitignore`). Переехали:
`Document`, `DocumentOperation`, `PLCategory`, `PLDailyTotal`, `PLMonthlySnapshot` → `Finance/Entity/`;
`ProjectDirection`, `Counterparty`, `ReportApiKey` → `Company/Entity/`.

Каталоги оставлены как guard-путь: класть сюда новые Entity/Service/Repository/Controller всё равно запрещено — используй `src/{Module}/`.

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
| `MarketplaceListingTag` | Marketplace | `string $companyId` ✅ |
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
| `IngestionTenantProbe` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `IngestionCredential` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `IngestRawRecord` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `IngestCursor` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `SyncJob` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `FinancialTransaction` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `SystemCounterparty` | Ingestion | global dictionary, no company filter |
| `ExternalCategory` | Ingestion | global marketplace source dictionary, no company filter |
| `ExternalCategoryMapping` | Ingestion | global mapping dictionary, no company filter |
| `NormalizationIssue` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `IngestOrder` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `IngestOrderItem` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `IngestOrderStatusEvent` | Ingestion | `string $companyId` + Doctrine `company` filter ✅ |
| `Product` | Catalog | `Company $company` (legacy) — ещё не мигрирован |
| `ProductImport` | Catalog | `string $companyId` ✅ |
| `ProductBarcode` | Catalog | `string $companyId` ✅ |
| `ProductPurchasePrice` | Catalog | `string $companyId` ✅ |
| `AuditLog` | Shared | `string $companyId` ✅ |
| `FinancialResponsibilityCenter` | Company | `string $companyId` ✅ |
| `FinancialResponsibilityCenterProject` | Company | `string $companyId` + same-company pair guard ✅ |
| `CashTransactionSplit` | Cash | `string $companyId` ✅ |
| `CashTransaction`, `MoneyAccount` и др. | Cash | `Company $company` (legacy) |
| `Deal`, `ChargeType` | Deals | `Company $company` (legacy) |
| `PLCategory`, `Document`, `DocumentOperation`, `PLDailyTotal`, `PLMonthlySnapshot` | Finance | `Company $company` (legacy) |
| `ProjectDirection`, `Counterparty`, `ReportApiKey` | Company | `Company $company` (legacy) |
| `CompanyRole` | Company | `?Company $company` — `NULL` = системный шаблон (общий для всех компаний) |

### Ingestion: tenant isolation

- Новые tenant-owned Ingestion entity должны лежать в `App\Ingestion\Entity`, иметь scalar `string $companyId` и реализовывать marker-интерфейс `App\Ingestion\Domain\TenantOwnedInterface`.
- Doctrine SQL Filter `company` применяется только если одновременно выполняются два условия: entity находится в namespace `App\Ingestion\Entity\*` и реализует `TenantOwnedInterface`. Legacy-модули и Ingestion entity без marker-интерфейса фильтр не затрагивает.
- HTTP-контекст с активной компанией: `CompanyFilterRequestSubscriber` включает filter и задаёт `companyId` из `ActiveCompanyService`.
- HTTP-контекст без активной компании: admin, неавторизованные страницы, страница выбора компании и другие non-workspace запросы не включают filter и не должны падать с 500 из-за отсутствия компании.
- Messenger-контекст: `CompanyFilterMiddleware` включает filter для сообщений `App\Ingestion\Message\CompanyAwareMessage`, берёт `companyId` из сообщения и восстанавливает прежнее состояние filter после обработки.
- Системные запросы без tenant-контекста должны осознанно выполнять `$em->getFilters()->disable('company')` или не включать filter. Это допустимо для maintenance/admin/batch операций, где требуется видеть данные всех компаний.

### Ingestion: raw storage

- `RawStorageFacade` — единая точка записи/чтения raw payload для нового Ingestion-модуля. Legacy raw-сущности Marketplace/Inventory/Ads не меняются.
- `RawStorageFacade::storeAndGetIds(RawBatch): list<string>` — та же запись, но возвращает скалярные id вместо `IngestRawRecord`. Для вызывающих из других модулей: `App\Ingestion\Entity\*` не пересекают границу модуля (`tests/Unit/Ingestion/Architecture/EntityBoundaryTest`). Внутри Ingestion используется `store()`.
- `IngestRawRecord` хранит только metadata: company/connection/shop/source/resource/external id, storage path, hash, byte size, fetched/sync timestamps и normalization status. Payload в БД не хранится.
- Payload записывается как canonical NDJSON, gzip-compressed, один файл на `RawBatch` chunk. Путь: `{company}/{source}/{shop}/{resource}/{yyyy}/{mm}/{dd}/{syncJobId}/{externalId}/{hash}.ndjson.gz`, чтобы несколько batch внутри одного sync job не перезаписывали друг друга.
- Dedup: перед записью сверяется SHA-256 hash canonical uncompressed NDJSON по `(companyId, source, resourceType, externalId)`. Совпавший hash обновляет `lastSeenAt`; новый object не создаётся.
- `app:ingestion:normalize-pending` is the cron safety net for raw records that remain `PENDING` after fetch/dispatch interruptions. It scans stale pending rows by `(normalization_status, fetched_at)` and re-dispatches `NormalizeRawRecordMessage`; it does not normalize inline.
- Storage seam общий для проекта: `App\Shared\Service\Storage\ObjectStorageInterface`. Default driver — `local`, он делегирует запись в существующий `StorageService`; S3 driver через Flysystem включается только явным `APP_OBJECT_STORAGE_DRIVER=s3` и `APP_OBJECT_STORAGE_S3_*`.
- Legacy-модули пока продолжают использовать `StorageService` напрямую. Их переезд на `ObjectStorageInterface` выполняется отдельными задачами, по одному модулю.

### Ingestion: cursor and sync jobs

- `app:ingestion:reap-stale-jobs` (cron `52 * * * *`) переводит задачи, застрявшие в `OPEN`/`RUNNING` без движения дольше порога (по умолчанию 6 ч), в `FAILED` с причиной `stale_no_progress`. Без этого ресурс блокируется навсегда: `SyncJobRepository::findLatestForResource()` считает активной любую такую задачу **без ограничения по возрасту**, а `StartIncrementalAction` бросает на неё `ActiveBackfillExistsException`. Воркер, убитый по SIGKILL или OOM, не выполняет `finally`, и загрузка прекращается молча — `RunIncrementalCommand` засчитывает это как `skippedActive` и возвращает успех.

- `app:ingestion:run-incremental --resource=<type>` ограничивает обход одним ресурсом. Опция существует ради каденции: заказы обходятся раз в час, финансовые ресурсы — раз в сутки, и у каждой группы своя строка в `docker/cron/app.cron` (`35/37 * * * *` для `ozon_orders_fbo`/`ozon_orders_fbs`, `0 3 * * *` для остального). Переводить общую команду на почасовой запуск нельзя: финансовые стратегии просыпались бы 24 раза в сутки без нужды. Неизвестное значение даёт пустой отбор и ноль задач, а не полный обход — опечатка в cron не должна тихо превращаться в обход всех ресурсов.
- Каденция принадлежит стратегии, а не крону: `IncrementalResourceStrategyInterface::cursorIsDue()` — единственный источник истины. `AbstractHourlyCursorIncrementalStrategy` пропускает курсор, обновлённый менее `$minIntervalMinutes` назад. По умолчанию 55, а не 60: порог намеренно меньше периода крона, потому что курсор пишется по фактическому времени воркера — при ровно часовом пороге прогон, завершившийся в 12:36, отодвигал бы следующий обход с 13:35 на 14:35, и ресурс молча становился двухчасовым. Лишний запуск крона безвреден: повторный обход окна идемпотентен.

- `IngestCursor` stores opaque cursor state for `(companyId, connectionRef, resourceType, shopRef)` and advances only through `UpdateCursorAction` / `SyncFacade::updateCursor`.
- `SyncJob` stores orchestration state for backfill, incremental, and manual sync runs. Parent backfill jobs are split into child chunk jobs; children are dispatched as `RunSyncChunkMessage` through `ingest_fetch`.
- `SyncJobStatus` owns the explicit state transition matrix. Entity methods reject invalid transitions and terminal jobs cannot be reopened.
- Repository reads require explicit `companyId`; the Doctrine `company` filter is an additional guard, not the only tenant boundary.
- `SyncFacade::startBackfill()` creates a parent job, splits it into 7-day chunks by default, and dispatches chunk messages after DB flush.
- `SyncFacade::startIncremental()` creates one non-windowed incremental job and dispatches one `RunSyncChunkMessage` after DB flush; the worker reads the shared cursor for that `(companyId, connectionRef, resourceType, shopRef)`.
- `RunSyncChunkHandler` resolves a `SourceConnectorInterface` through `ConnectorRegistry`, pulls one chunk, stores raw payload through `RawStorageFacade`, dispatches `NormalizeRawRecordMessage`, advances cursor, and finalizes the job.
- `ingest_fetch` uses the sync transport DSN for external source fetches; `ingest_normalize` uses the pipeline DSN for local normalization work.

### Ingestion: canonical finance layer

- `FinancialTransaction` is the canonical transaction record produced from normalized raw source rows. Natural key: `(companyId, source, externalId, type)`.
- **Boundary rule:** `FinancialTransaction` and all other `App\Ingestion\Entity\*` types must not cross a module boundary. `IngestionFacade::getTransactions` projects each entity into a read-only `App\Ingestion\Application\DTO\FinancialTransactionView` (enum fields exposed as scalar `value`) so consumers never receive a managed, mutable entity. Enforced by `tests/Unit/Ingestion/Architecture/EntityBoundaryTest`.
- Amounts are stored in minor units. Shared `Money` is signed and can represent positive, negative, or zero values; `TransactionDirection` (`IN`/`OUT`) remains the normalized flow classification. `Money` enforces one ISO-4217 currency per arithmetic operation.
- `operationGroupId` groups decomposed transactions from one source operation for audit and sum-control checks.
- `SystemCounterparty` is a global source dictionary (`source`, `name`, optional `inn`) for marketplace/system counterparties. It is not tenant-owned and is resolved by source during normalization; missing source rows leave `FinancialTransaction.counterpartyId = null` and are logged.
- `ExternalCategory` and `ExternalCategoryMapping` are global Ingestion dictionaries for marketplace source categories (`source`, `resourceType`, `scope`, normalized external key). They are intentionally not tenant-owned: Ozon/WB category names and base mappings are source-level semantics, while admin changes affect future normalization and metadata refresh across companies.
- `FinancialTransaction.listingId` and `listingSku` are nullable marketplace listing attribution fields. Missing or ambiguous listing matches must not block normalization.
- `ListingResolverInterface` resolves source-specific listing attribution from `MappedTransaction.sourceData`; implementations are registered with `app.ingestion.listing_resolver`. `OzonListingResolver` uses supplier SKU (`offer_id`/`item_code`) and marketplace SKU fallbacks. `WbListingResolver` резолвит по `nm_id`, затем по `supplier_article`, и намеренно ничего не создаёт — см. раздел «Ingestion: orders».
- `MarketplaceListingFacade` is the Ingestion-facing boundary to legacy Marketplace listing repositories. Ingestion must not query Marketplace entities directly outside this facade.
- `NormalizationIssue` is append-only for mapper failures, control-sum mismatches, unknown fields, and currency mismatches; resolving sets `resolvedAt`.
- Repository reads require explicit `companyId`; period reads use `toIterable()` for large verification ranges.
- `SourceConnectorInterface` is the per-source boundary for `discoverShops`, `pull`, and future `push`. Production connectors must be registered with `app.ingestion.connector`.
- `SourceMapperInterface` maps raw rows to `MappedTransaction` DTOs and control sums. Mappers are pure and registered with `app.ingestion.mapper`.
- `OzonSellerReportConnector` is the first production source connector. It supports `ozon_seller_daily_report` (`/v3/finance/transaction/list`) and `ozon_seller_realization` (`/v2/finance/realization`) through the Ingestion Ozon adapter. Legacy Marketplace Ozon jobs remain enabled until a later shadow/switch task.
### Ingestion: orders

- Заказы живут в `Ingestion` как `IngestOrder` / `IngestOrderItem` / `IngestOrderStatusEvent`. `Marketplace\Entity\MarketplaceOrder` не затрагивается: у него своя роль и своя запись.
- Нормализация заказов идёт **мимо** финансового `NormalizeRawRecordAction`: `NormalizeRawRecordHandler` ветвится по `OrderMapperRegistry::has(source, resourceType)` и зовёт `NormalizeOrderRawRecordAction`. `MappedTransaction` требует `type`/`direction`/`money`/`operationGroupId` — заказу это чуждо, поэтому у заказов свои DTO `MappedOrder`/`MappedOrderItem` и свой контракт `OrderMapperInterface` (тег `app.ingestion.order_mapper`).
- Статус хранится дважды: сырой строкой источника (`rawStatus`) и нормализованным `IngestOrderStatus`. Неизвестное значение источника даёт `UNKNOWN` и запись `NormalizationIssueKind::UNKNOWN_ORDER_STATUS`, а не `NULL`: `NULL` одновременно ломает данные и прячет факт поломки.
- Терминальность определяется в одном месте — `IngestOrderStatus::isTerminal()`. Дублировать предикат в запросах нельзя: три копии рано или поздно разойдутся.
- История append-only: `IngestOrderStatusEvent` не имеет публичных мутаторов. `IngestOrder::observeStatus()` возвращает `false` и не двигает статус, если наблюдение старше текущего `statusObservedAt` — статус не едет назад при перестановке сообщений.
- `IngestOrderScheme` (`FBO`/`FBS`) выводится из resourceType, а не из тела ответа: у Ozon схема задана эндпоинтом.
- Идентичность заказа — `(companyId, source, connectionRef, externalId)`. `connectionRef` обязателен: `posting_number` уникален в пределах кабинета продавца, а не глобально, и без него два кабинета Ozon одной компании слились бы в одну запись.
- Идентичность позиции — `lineKey` (пара `sku:<sku>|offer:<offerId>` плюс номер повторения этой пары), а не порядковый `lineNo`. Именно пара, а не один из идентификаторов: две строки с одним SKU и разными `offer_id` — разные предложения, и ключ по одному sku различал бы их позиционно. Позиционный ключ ломается при перестановке `products` в ответе источника: строка сохраняла бы прежние опознавательные поля, но получала количество, цену и листинг соседнего товара. `lineNo` остаётся порядком отображения и обновляется вместе с содержимым.
- Событие журнала уникально по `(companyId, orderId, rawRecordId, rawStatus)`. Устаревшее наблюдение не двигает статус заказа, поэтому «статус отличается от текущего» остаётся истиной навсегда — без этого ключа каждая перенормализация того же сырья дописывала бы копию.
- Строка, которую маппер не разобрал, становится `NormalizationIssueKind::MAPPER_FAILURE`, а не исчезает: курсор после нормализации уже уехал, и молчаливый пропуск — постоянная потеря, неотличимая от «заказов в окне не было». Дата заказа при этом никогда не подменяется временем загрузки.
- Связь с листингами хранится скалярами `listingId`/`listingSku`. Нерезолвленная позиция сохраняет `listingSku` при `listingId = null` — это видимая очередь на доразбор, а не потеря данных.
- **Wildberries: два потока, и оба нужны.** `wildberries_orders_marketplace` (`/api/v3/orders` + `/api/v3/orders/status`) знает состав заказа и обе оси статуса, но не показывает отмены, случившиеся позже. `wildberries_orders_statistics` (`/api/v1/supplier/orders?flag=0`) — поток ИЗМЕНЕНИЙ по `lastChangeDate`: приносит отмены и правки задним числом, но состава заказа не отдаёт. Сшиваются по `rid = srid`: оба потока приводят заказ к одному `externalId`, поэтому попадают в одну запись через общий ключ `(companyId, source, connectionRef, externalId)`.
- **statistics-api Wildberries работает в московском времени, а не в UTC.** Это проверено на данных: один и тот же заказ имеет `createdAt = 2026-08-30T19:18:04Z` в marketplace-api и `date = 2026-08-30T22:18:04` без зоны в statistics-api — ровно +3 часа. Поэтому `dateFrom` тоже отправляется московским, а разбор обеих форм живёт в одном месте — `WbOrderDateParser`. Две копии этого знания разошлись бы сдвигом заказов на три часа, и заметить это было бы нечем.
- **Денежные единицы у потоков WB разные.** `price` в marketplace-api уже в копейках (в выгрузке 195700 при цене 1957 ₽), `finishedPrice` в statistics-api — в рублях. Общей конвертации у них быть не может: прогон копеек через рублёвую формулу завысил бы цену в сто раз.
- Водяной знак `wildberries_orders_statistics` — максимальный `lastChangeDate`, а не время запроса: `flag=0` отдаёт поток изменений, и следующий обход обязан начаться там, где кончился предыдущий. Пустой ответ означает, что после курсора записей нет, и тогда курсор безопасно переезжает на время запроса — иначе окно росло бы бесконечно.
- **Свежесть статуса и свежесть снимка — разные отметки.** `IngestOrder.statusObservedAt` двигает только статус, `snapshotObservedAt` — только полный снимок (состав, цена, валюта). Потоки приходят вперемешку: частичное наблюдение из statistics может быть скачано позже, а разобрано раньше полного снимка из marketplace, и на одной отметке оно навсегда закрыло бы дорогу авторитетным полям. Сценарий строго последовательный и гонки не требует.
- Курсор `wildberries_orders_marketplace` встаёт на ПОТОЛОК окна — время начала обхода, замороженное на первой странице и перенесённое через все продолжения, — а не на время финального вызова. Длинная пагинация идёт минутами, и «сейчас» последнего вызова объявило бы прочитанным всё до этого момента, тогда как ранние страницы снимались раньше.
- Водяной знак statistics ограничен сверху временем запроса: одна ошибочно будущая отметка иначе стала бы курсором навсегда, а пустые ответы не смогли бы это исправить, потому что курсор назад не едет.
- **Полное наблюдение и частичное — разные вещи.** `MappedOrder::$itemsAuthoritative` разделяет их: полный снимок (Ozon, marketplace-api WB) заменяет состав заказа целиком, включая удаление исчезнувших позиций; частичное наблюдение (statistics-api WB) вправе добавить недостающую позицию, но не переписывать и не удалять чужие. Без этого поток, пришедший последним, стирал бы цену и состав, которых он попросту не видит, и снимок заказа зависел бы от порядка потоков.
- Владение полями между потоками WB: `externalOrderId` принадлежит marketplace (номер заказа WB), `gNumber` из statistics — другая сущность и живёт только в атрибутах. Цену позиции задаёт marketplace и только он: `finishedPrice` из statistics — цена после всех скидок в рублях, а `price` marketplace — цена продажи в копейках; они уходят в разные места (`IngestOrderItem.priceMinor` и атрибут заказа `finished_price_minor`), потому что одна колонка с двумя значениями меняла бы смысл в зависимости от того, какой поток заполнил запись.
- Атрибуты сливаются ПО ВЛАДЕЛЬЦУ ОСИ. `MappedOrder::$statusAttributes` (`is_cancel`, `cancelled_at`, `supplier_status`, `wb_status`, `is_cancellable`) меняются во времени и применяются только с принятым статусом; `MappedOrder::$attributes` описывают заказ как таковой и применяются со снимком. Одно общее условие означало бы, что более старый первый снимок запишет свои оси статуса поверх свежей отмены — заказ показывал бы `CANCELLED` рядом с устаревшими `wb_status` и `supplier_status`.
- **Три отметки наблюдения, а не одна.** `statusObservedAt` (nullable — статуса могло не быть вовсе), `snapshotObservedAt` (последний полный состав) и `partialObservedAt` (последнее частичное сообщение). Потоки сообщают о заказе РАЗНОЕ, и общая отметка теряла бы данные: частичное наблюдение без статуса отбрасывалось бы целиком, а наблюдение без статуса закрывало бы дорогу первому настоящему статусу, окажись тот старше по времени скачивания.
- Позицию частичное наблюдение добавляет, только если оно не старше последнего полного снимка: снимок описывает состав целиком, поэтому более раннее наблюдение не может знать о позиции, которой в нём нет.
- **Отсутствие статуса — не статус.** `MappedOrder::$statusObserved` отделяет «источник сообщил статус» от «источник промолчал». `isCancel = false` в потоке изменений WB говорит лишь «отмены не было», а `/api/v3/orders` без ответа `/orders/status` не говорит ничего; принять это за наблюдение значило бы затирать реальный этап жизни заказа — и крон ставит statistics ПОСЛЕ marketplace, так что затирало бы почти всегда.
- Схему частичное наблюдение уточняет, но не портит: распознанный тип склада заполняет `UNKNOWN`, а `UNKNOWN` никогда не затирает известную схему. Авторитетного снимка может не быть вовсе — заказы FBO в marketplace-поток не попадают.
- Денежные значения отбраковываются, если не помещаются в `BIGINT`: приведение длинной строки к `int` молча дало бы предельное целое, то есть другую сумму без единого признака ошибки.
- **Отметки наблюдения приводятся к зоне приложения перед сравнением и записью.** Колонки времени в схеме — без зоны, а Doctrine пишет отметку в её собственной зоне и читает обратно в зоне PHP: проверено, что `2026-09-01T10:00:00+00:00` уходит в базу как `10:00:00`, а возвращается как `10:00:00+03:00` — сдвиг 10800 секунд. Коннекторы берут время у `ClockInterface` (UTC), остальное приложение живёт в своей зоне, поэтому сравнение сохранённой отметки со свежей давало бы разницу в три часа и устаревшее наблюдение проходило бы как новое. Нормализация сделана в одной точке — `NormalizeOrderRawRecordAction`.
- Гранулярность сравнения отметок — СЕКУНДА. Стандартный тип `datetime_immutable` пишет `Y-m-d H:i:s` независимо от объявленной точности колонки, поэтому два наблюдения внутри одной секунды считаются одновременными и побеждает обработанное последним. Это зафиксировано тестом, а не подразумевается.
- Курсоры WB сериализуются с микросекундами. `DATE_ATOM` их отбрасывает, и водяной знак `11:30:00.123456` сохранялся бы как `11:30:00`: та же строка на следующем обходе снова оказывалась бы «новее курсора», и он вечно возвращался бы к той же секунде.
- `lastChangeDate` — обязательная часть протокола statistics: отсутствующая или неразбираемая отметка даёт `MalformedConnectorResponseException`. Молчаливый пропуск означал бы, что непустой испорченный ответ считается доказанным «изменений нет», и курсор закрыл бы непрочитанный участок окна.
- Схему заказа задаёт только принятый авторитетный снимок: запись мог создать частичный поток, который схемы не знал и оставил `UNKNOWN`.
- statistics-api не сообщает валюту, и она не подставляется: «наверное, рубли» — финансовое утверждение без основания. Для заказа, который есть и в marketplace, валюту даёт тот поток.
- `IngestOrderScheme::UNKNOWN` — синтетическое значение для случая, когда источник не сообщил схему в понятном виде. У WB схема выводится из названия склада строгим словарём; незнакомая строка не должна молча превращаться в настоящую схему, но и терять из-за неё заказ нельзя.
- `WbListingResolver` — ЧИТАЮЩИЙ, в отличие от `OzonListingResolver`: он не заводит недостающие листинги. Каталога WB в системе пока нет, а создавать карточку из одного `nmId` значило бы плодить пустышки, неотличимые от настоящих. Порядок поиска — `nmId` (SKU маркетплейса), затем артикул продавца: первый присваивает WB и он уникален, второй задаёт продавец и может повторяться.
- **Почасовой перепрос статусов — `app:ingestion:orders:refresh-statuses`** (cron `47 * * * *`). Потоки заказов фильтруются по времени СОЗДАНИЯ, поэтому заказ попадает в них один раз и его дальнейшие смены статуса не увидел бы никто; единственное исключение — поток изменений WB statistics, но он сообщает только об отмене.
- Цикл работает **без `SyncJob` и без курсора**: продвигать здесь нечего — множество опрашиваемых заказов задаётся их собственным состоянием, а не окном времени. Задача синхронизации упиралась бы в `ActiveBackfillExistsException` на каждом втором часе. Параллельные прогоны отсекает `LockableTrait`.
- Порядок опроса планируется по отметке ПОПЫТКИ (`statusRefreshAttemptedAt`), а не наблюдения: ни разу не опрошенные первыми, затем по возрастанию. Попытка бывает без наблюдения — 404, ответ без поля статуса, отсутствие заказа в успешном ответе WB, — и по отметке наблюдения такие заказы стояли бы в начале очереди вечно, а остальные заказы кабинета не опрашивались бы никогда, попадая сразу в `STUCK_ORDER`.
- Ozon перечитывается по одному отправлению (`/v2/posting/fbo/get`, `/v3/posting/fbs/get`); 404 означает «Ozon такого отправления не знает» и не роняет прогон. Дополнительные блоки (`analytics_data`, `financial_data`) не запрашиваются: перепросу нужен только статус, а ответ целиком лежит в raw год. Схема выбирает эндпоинт исчерпывающим `match`: `UNKNOWN` спросить нечем, и «по умолчанию FBO» дало бы ложный 404 вместо честной ошибки. Ответ, нарушивший контракт, едет вместе с исключением и попадает в raw — кроме невалидного JSON, где разбирать нечего. Номер в ответе сверяется с запрошенным — иначе статус чужого отправления записался бы этому заказу незаметно. WB опрашивается пачками через `/api/v3/orders/status`, и только те заказы, у которых есть номер marketplace-api (`externalOrderId`): заказ, известный лишь из потока изменений, спросить не у кого — эндпоинта «статус по `srid`» не существует. Отсев идёт в SQL, ДО лимита: у таких заказов `statusObservedAt` навсегда NULL, то есть они вечно первые в очереди и иначе съедали бы лимит целиком.
- Сбой одного подключения не отменяет уже полученного: ответы, приехавшие до 429 или таймаута, применяются. Отказ авторизации считается отдельно от повторяемых сбоев — протухший ключ ждёт человека, поэтому даёт агрегированный `error` и ненулевой код возврата команды, тогда как 429 и таймаут остаются `warning`. Испорченный ответ ОДНОГО отправления подключение не роняет: иначе одно вечно кривое отправление каждый час останавливало бы обработку всех следующих заказов кабинета. Заказы чанка, на который ответа не пришло вовсе, не считаются «неизвестными маркетплейсу»: их просто не спросили.
- Наблюдения применяются в короткой транзакции с перечитыванием заказов под `PESSIMISTIC_WRITE` ОДНИМ запросом на пачку (`findManyForUpdate`, порядок по `id` — единый порядок захвата блокировок): между выборкой и записью проходят внешние HTTP-запросы, и загруженные сущности за это время устаревают. Блокировка берётся ПОСЛЕ сети — держать её во время опроса значило бы держать минутами. Время наблюдения снимается СРАЗУ после каждого ответа (у WB — после каждого чанка), а не один раз на подключение: подключение — это до тысячи последовательных запросов, и общая отметка приписала бы первому ответу время последнего, из-за чего устаревшее наблюдение выигрывало бы у свежего. Ту же блокировку берёт и нормализация (`findManyByExternalIdsIndexed(forUpdate: true)` внутри транзакции): односторонняя блокировка не защищает — writer, прочитавший строку раньше, всё равно перезаписал бы её отложенным UPDATE. Первой в этой транзакции блокируется сама запись сырья и перечитывается: две параллельные доставки одного сообщения иначе обе увидели бы статус не `DONE`, и вторая пошла бы разбирать как первый раз. Порядок блокировок один для всех нормализаторов: сырьё, затем заказы, обе выборки — по `id`. Тем же способом останавливаются зависшие заказы: кандидаты перечитываются под блокировкой и условие зависания перепроверяется, иначе конкурентная нормализация успела бы довести заказ до терминального статуса между выборкой и записью.
- Ответ маркетплейса кладётся в raw ради аудита под отдельным `resourceType` (`*_order_status_refresh`) с идентификатором прогона вместо `syncJobId`. Маппера у этого ресурса нет, и запись сразу помечается `skipped`: оставленная в очереди, она висела бы там вечно.
- Заказ, нетерминальный дольше окна опроса, перестаёт опрашиваться (`refreshStoppedAt`) и попадает в видимую очередь как `NormalizationIssueKind::STUCK_ORDER`. Проблема привязывается к сырью, из которого заказ наблюдался последний раз: у самой остановки своего payload нет, а разбирающему нужно с чего-то начать. Заказ без сырья (состояние недостижимое, но не проверяемое схемой) НЕ останавливается: тихая остановка без видимой очереди хуже, чем заказ, который продолжают опрашивать. Поиск зависших идёт ОТДЕЛЬНО от подключений и по всем компаниям (`findStuckAcrossCompanies`, `@companyScopeExempt`): зависание не зависит от того, живо ли ещё подключение, а отключённый кабинет иначе оставлял бы свои заказы вечно нетерминальными и невидимыми.
- Незнакомый токен статуса при перепросе даёт тот же `UNKNOWN_ORDER_STATUS`, что и при нормализации: одно доменное понятие — одно определение. Ответ, нарушающий контракт (отправление без поля статуса), считается отдельно от честного 404 и всё равно сохраняется в raw — именно он и нужен как доказательство.
- Устаревшее наблюдение фиксируется как факт, но переходом не считается: у события `applied = false` и пустой `previousStatus`. `NULL` в этой колонке — строки, записанные до её появления: восстановить признак для них нечем, и проставить `true` значило бы задним числом объявить переходами то, чего не было.
- Ключ наблюдения включает `previousStatus`. Без него последовательность A → B → A из одного сырья давала расхождение: заказ возвращался в A, а журнал заканчивался на B, потому что третье наблюдение подавлялось ключом первого.
- Учётные данные Ozon запоминаются на процесс (`CredentialFacadeOzonCredentialProvider`): перепрос ходит по одному отправлению, и чтение секрета из БД на каждый вызов было бы N+1 с повторной расшифровкой. Заменённый ключ подхватывается следующим процессом. Записанное иначе (`previousStatus = DELIVERED`, `status = SHIPPED`), оно утверждало бы движение заказа, которого не было.
- Промах тоже документируется: 404 Ozon и заказ, отсутствующий в успешном ответе WB, кладутся в raw синтетической строкой с `_ingestion_outcome`. Тела у промаха нет, но утверждение «спросили — не нашли» через месяц иначе нечем подтвердить.
- Очередь перепроса опирается на частичный индекс `idx_ingest_order_refresh_queue` (`Version20260902170000`): `LIMIT` ограничивает результат, но не число строк, которые PostgreSQL обязан отфильтровать и отсортировать на каждое подключение.
- `OrderStatusJournal` — единственное место, где статус наблюдается и попадает в журнал. Путей наблюдения два (разбор сырья и перепрос), инварианты у них общие, и две копии правил «когда писать событие» и «когда двигать статус» разошлись бы. Повтор уже разобранного сырья наблюдением не является и идёт через `reapply()`: состояние пересчитывается, событий не появляется. Иначе повтор вёл бы себя по-разному в зависимости от момента — сырьё, впервые разобранное при том же статусе, событий не давало, а после следующей смены статуса тот же повтор дописывал бы перевёрнутый переход с `previousStatus` из будущего.
- **Ограничение текущего охвата:** Ozon фильтрует отправления по времени СОЗДАНИЯ, поэтому почасовой обход видит заказ один раз и его последующие смены статуса не заметит. Отслеживание статусов обеспечивает описанный выше цикл перепроса, а не этот коннектор.
- Продолжение пагинации несёт замороженное окно и смещение (`{"since","to","offset"}`), а персистентный курсор двигается только после того, как окно дочитано. Курсор вида `{"since"}` — обычное состояние, `{"since","to","offset"}` — незавершённое окно.
- **Отметки наблюдения хранят микросекунды** (`datetime_immutable_us`, `Version20260902160000`). Колонки были `TIMESTAMP(6)` и раньше, но стандартный `datetime_immutable` форматирует значение как `Y-m-d H:i:s` независимо от точности колонки, и микросекунды терялись при записи. Два наблюдения внутри одной секунды становились неразличимы, а наблюдение принимается «не старше» текущего — поэтому `10:00:00.900`, сохранённое как `10:00:00`, проигрывало более СТАРОМУ `10:00:00.100`, обработанному позже: статус ехал назад, а в журнале появлялся перевёрнутый переход. Тип применён точечно — к четырём отметкам `ingest_orders`, `observed_at` журнала и `fetched_at` сырья (последнее и есть момент наблюдения для нормализации). Остальные 55 колонок времени в проекте не тронуты: это отдельное решение с отдельной проверкой совместимости.
- Таблицы заказов созданы **без FK** на `companies`/`marketplace_listings`: Ingestion ссылается на чужие сущности скалярами (см. правило `string $companyId` вместо `#[ManyToOne]`), и FK ввёл бы схемный дрейф относительно `doctrine:schema:update`.

- `OzonSellerReportMapper` and `OzonRealizationMapper` decompose one Ozon operation into multiple canonical transactions with a shared `operationGroupId`. External ids use `ozon:operation:{operation_id}:{component}` so multiple same-type Ozon components can coexist under the current `(companyId, source, externalId, type)` natural key.
- `NormalizeRawRecordAction` reads NDJSON raw payload, calls the mapper, upserts canonical transactions, records `NormalizationIssue` on mapper/control-sum problems, and marks the raw record `DONE`/`FAILED`. Normalization does not publish Finance events or write P&L aggregates.

### Ingestion: verification client API

- Client verification API lives under `/api/ingestion/verification/*` and is exposed through `App\Ingestion\Facade\IngestionFacade`.
- Endpoints:
  - `GET /api/ingestion/verification/coverage` — raw/canonical/open-issue coverage heatmap plus shop options.
  - `GET /api/ingestion/verification/reconciliation` — canonical transaction totals by shop vs latest legacy `OzonTransactionTotalsCheck` company-period control total.
  - `GET /api/ingestion/verification/issues` — unresolved normalization issues with human-readable descriptions only.
  - `GET /api/ingestion/verification/financial-summary` — monthly/category summary read directly from canonical `FinancialTransaction` rows.
- All controllers resolve `companyId` from `ActiveCompanyService`; DBAL read queries also include explicit `company_id` predicates and do not rely only on Doctrine filters.
- API period validation errors use `IngestionExceptionListener` and return `{ "error": { "code": "...", "message": "..." } }` with HTTP 422.
- Reconciliation reads `OzonTransactionTotalsCheck::getOzonTotals()["total_minor"]`; missing or invalid legacy totals are returned as `null`.
- Financial summary applies the requested `shop_ref` directly to canonical transactions and does not read or mutate Finance P&L tables.

### Ingestion: verification client UI

- Client verification pages are Twig + React islands under `/ingestion/verification/*`:
  - `GET /ingestion/verification/coverage` — coverage heatmap island.
  - `GET /ingestion/verification/reconciliation` — shop-scoped reconciliation island.
  - `GET /ingestion/verification/issues` — open normalization issues island.
  - `GET /ingestion/verification/financial-summary` — canonical financial summary island.
- Page controllers live in `App\Ingestion\Controller\Page`, require `ROLE_COMPANY_USER`, and only render Twig templates.
- React source lives in `site/assets/react/ingestion-verification/`; flat Vite entries live in `site/assets/react/ingestion-verification-*-page.tsx`.
- Data loading uses the existing `useAbortableQuery` + `httpJson` pattern and generated `site/assets/api/schema.d.ts` aliases. No TanStack Query dependency is added.
- `ShopSelector` persists the selected shop in `localStorage` key `ingestion.selected_shop`; reconciliation requires a concrete shop and does not call its API until one is selected.
- Coverage is also used as the current shop-options source because the backend verification API exposes shop options only through the coverage response.

### Finance: Ingestion P&L projection is disabled

- Ingestion canonical transactions are not projected into `PLDailyTotal`, `PLMonthlySnapshot`, `Document`, or `DocumentOperation`.
- The dirty-period table, rebuild actions, batch command, and `PnlFacade` integration have been removed. Finance/Marketplace legacy writers remain responsible for P&L aggregates.
- `MarkPnlPeriodDirtyMessage` and `RebuildPnlPeriodMessage` plus their routes remain only as deprecated compatibility tombstones. Their handlers consume messages queued before removal without changing database state.

### Finance: перенос дерева категорий ОПиУ между компаниями

- `ImportPLCategoryTreeAction` принимает источник как `list<App\Finance\Application\DTO\PLCategoryTreeNode>` в DFS pre-order, а не компанию: одно и то же дерево приходит либо из другой компании аккаунта, либо из файла, выгруженного в чужом аккаунте. Матчинг узла — по `code`, при его отсутствии по `(parent, name)`; узлы целевой компании, отсутствующие в источнике, не удаляются и не изменяются.
- `PLCategoryTreeExporter` — единственное место сериализации формата обмена (`fromEntities()`, `toFilePayload()`), `PLCategoryTreeFileReader::read()` — единственное место разбора и валидации файла. Набор полей в обоих обязан совпадать с `ImportPLCategoryTreeAction::applyFields()`; расхождение означает тихую потерю настройки строки P&L при переносе.
- Формат файла v1 (`PLCategoryTreeExporter::FORMAT_VERSION`): `{version, exportedAt, company, categories[]}`, категория — `{name, code, type, format, flow, expenseType, weightInParent, isVisible, formula, calcOrder, sortOrder, children[]}`. `id` и `level` не пишутся и не читаются: идентификаторы чужого аккаунта в целевой компании смысла не имеют, уровень выводится из вложенности. Читатель игнорирует неизвестные ключи и дополнительно принимает голый массив категорий — формат прежней выгрузки без конверта. Категория обязана нести весь набор полей: импорт перезаписывает поля целиком, поэтому отсутствующее поле означало бы тихую замену настройки строки P&L значением по умолчанию (`flow` INCOME → NONE, вес −0.5000 → 1.0000) под видом обычного «обновить». `children` необязателен.
- Импорт всегда идёт в активную компанию; из файла не читается ни идентификатор компании, ни идентификаторы категорий. Ограничения читателя: 1 МБ, 1000 узлов, 5 уровней, уникальность `code` внутри файла (иначе нарушается `uniq_plcat_company_code` уже в середине транзакции).
- Формулы переносятся как есть. `ImportPLCategoryTreeResult::$unresolvedFormulaCodes` — предупреждение о токенах формул, которых не будет в целевой компании после импорта; это эвристика на токенах, парсера формул в проекте нет.

### Finance: выгрузка операций ОПиУ в JSON

- `App\Finance\Infrastructure\Export\PlOperationJsonExporter::export(companyId, companyName, exportedAt)` — единственное место сборки плоской выгрузки операций ОПиУ. Строка результата = одна `DocumentOperation` с продублированными полями своего документа; конверт `{exported_at, company, count, operations[]}`.
- Маршрут `document_operations_export_json` (`GET /documents/operations/export.json`, `PlOperationJsonExportController`) — кнопка «Выгрузить JSON» в шапке страницы «Операции ОПиУ». Путь намеренно из двух сегментов: `/documents/export.json` перехватил бы `document_show` (`/documents/{id}`).
- Выгрузка отдаёт все операции активной компании: фильтры страницы `document_index` не применяются, потому что контроллер списка их тоже не применяет. Изоляция компании — `WHERE d.company_id` плюс `company_id = d.company_id` в каждом JOIN справочника: FK на `pl_categories`, `"counterparty"` и `project_directions` компанию не несут, поэтому строка с чужой ссылкой отдаёт `NULL` вместо чужого названия. Закреплено тестами `testExcludesOtherCompanyOperations` и `testDoesNotLeakForeignCompanyReferenceNames`.
- `amount` остаётся строкой (`NUMERIC(15,2)`); `counterparty` и `project` — значения операции с фоллбэком на документ, как их показывает экран.

### Finance: responsibility-center fact-schema expansion

- Stage 7.5 adds nullable scalar `responsibility_center_id` UUID columns to `cash_transaction`, `documents`, `document_operations`, and `pl_daily_totals`. Each column has a restrictive FK to Company-owned `financial_responsibility_centers`.
- Stage 7.6.2 connects `CashTransactionService` and `CashFacade` to `CashTransactionResponsibilityCenterResolver`. New core Cash/facade-created transactions with empty project/ЦФО receive the company `PROJECT_GENERAL × CFO_GENERAL` pair; explicit pairs are accepted only when active and allowed. The existing manual Cash form exposes the scalar ЦФО choice so its existing project field can submit a complete pair. Duplicate facade branches return before pair resolution and never rewrite existing classification.
- Stage 7.6.4 keeps file, 1C client-bank, and bank-provider imports on their direct `CashTransaction` writer paths to preserve existing duplicate, overwrite, preview, batching, logging, cursor, and balance behavior. Each import resolves the company system pair once and applies it only to newly persisted transactions. Existing 1C overwrite rows keep their stored Project×ЦФО. Dynamic project→ЦФО filtering, Finance Entity mappings, and P&L writes remain deferred to later approved units.
- Stage 7.7.1 maps the already deployed nullable scalar `responsibility_center_id` columns on `Document`, `DocumentOperation`, and `PLDailyTotal`. `FinanceResponsibilityCenterPairValidator` reuses the Company responsibility-center facade to validate only complete Project × ЦФО pairs; `NULL` ЦФО remains valid for legacy facts. No Finance writer or P&L aggregation key is switched in 7.7.1.
- Stage 7.7.2 propagates Cash transaction ЦФО into newly created Finance `Document` and `DocumentOperation` rows. Manual Finance document forms expose a scalar ЦФО choice and validate Project × ЦФО server-side; operation-level pair overrides document-level pair, while missing operation project/ЦФО inherits from the document. New manual rows with an empty pair default to company `PROJECT_GENERAL × CFO_GENERAL`; unchanged historical incomplete or archived current pairs remain saveable. Marketplace `createPLDocument()` callers still pass `NULL` ЦФО unless a later contract stage explicitly supplies it.
- Stage 7.7.3 switches new document-driven `pl_daily_totals` writes to the `Project × ЦФО` aggregation key. `PLRegisterUpdater` groups by operation ЦФО, falls back to document ЦФО, and keeps `NULL` as the legacy unallocated bucket. The storage contract uses two partial unique expression indexes: categorized rows key by `company_id × pl_category_id × date × project_direction_id × COALESCE(responsibility_center_id, zero-uuid)`, while uncategorized rows key by `company_id × date × project_direction_id × COALESCE(responsibility_center_id, zero-uuid) WHERE pl_category_id IS NULL`. `pl_category_id` still uses default PostgreSQL nullable semantics; no `NULLS NOT DISTINCT` is used. Category deletion merges affected daily totals into the uncategorized bucket before removing the category so the partial unique index is not violated. Reports without a ЦФО dimension continue to read summed totals.
- Stage 7.7.4 adds the read-side `responsibilityCenterId` filter to P&L preview, preview JSON, and public P&L JSON endpoints. `PlReportCalculator`, grid builder, project comparison builder, and `PLDailyTotalFactsProvider` pass the optional ЦФО filter through to `pl_daily_totals`; invalid or foreign ЦФО ids are ignored at controller boundaries. Project comparison can now show project columns scoped to one selected ЦФО. Raw P&L debug output displays Project and ЦФО for document operations and daily totals. No historical rebuild or data backfill is performed.
- The P&L Preview UI/JSON additionally accepts `quarter`, `projectDirectionIds[]`, and `responsibilityCenterIds[]`. The legacy `dimensionFiltersPresent` marker applies to both lists; the filter card uses `projectFiltersPresent` and `responsibilityCenterFiltersPresent` so an empty selection is tracked independently for each dimension. Lists are validated against the active company; selected project subtrees are deduplicated before facts are summed, and the project comparison total is calculated from their union. `null` remains the unfiltered/all state (including legacy unallocated ЦФО facts), while an explicit empty list returns no matching facts. Submitting every active choice is normalized to `null`, so the visible «all selected» state remains identical to the default periods report and retains unallocated facts. When a tracked dimension has no active choices, a list omitted under its marker is an explicit empty selection and returns no facts; the UI omits a dimension marker when an empty catalogue is in its default unfiltered state. In the projects layout any plural request switches `Итого` to the deduplicated union of selected project columns. Legacy singular Preview parameters remain compatible. Public P&L endpoints keep their existing `day|week|month` and singular-filter contracts.
- Stage 7.8.1 adds the same optional read-only ЦФО filter to the existing ДДС cashflow report and public cashflow JSON/CSV endpoints. `CashflowReportRequestMapper` resolves active company-owned `responsibilityCenterId` values through `FinancialResponsibilityCenterFacade`; invalid, archived, or foreign ids are ignored. `CashflowReportBuilder` filters transaction category totals by `CashTransaction::responsibilityCenterId` when selected, while account opening/closing balances remain company-wide because account balances are not stored by ЦФО. The default unfiltered report remains unchanged and includes legacy `NULL` rows.
- Stage 7.8.2 adds an additive read-side Project × ЦФО matrix to the existing ДДС cashflow report and JSON payload. `CashflowReportBuilder` groups the selected transaction row set by project, responsibility center, currency, and existing period buckets; inflows stay positive and outflows negative. The UI renders the same matrix in both directions (`ЦФО → проекты` and `Проект → ЦФО`). Legacy `NULL` ЦФО rows appear as `Не задано`; missing projects appear as `Без проекта`. Account balances remain company-wide and no historical recalculation is performed.
- The cashflow UI, protected JSON export, and public JSON/CSV endpoints additionally accept `projectDirectionIds[]` and `responsibilityCenterIds[]`, with independent `projectFiltersPresent` and `responsibilityCenterFiltersPresent` markers for explicit empty selections. Both lists are validated against the active company and combine with AND; selected project subtrees are deduplicated before filtering transactions. `null` remains the unfiltered/all state and includes legacy unallocated transactions, while an explicit empty list returns no movements. A partial project or responsibility-center selection excludes the corresponding legacy `NULL` movements (`Без проекта` / `Не задано`). A marker submitted for an empty catalogue is also an explicit empty selection; the Stage 2 UI must omit that marker for an empty default catalogue. Selecting every available choice normalizes to `null`. A plural responsibility-center marker or list takes precedence over a simultaneous singular `responsibilityCenterId`; the legacy response field contains the selected id only when exactly one plural center remains. Existing `from`, `to`, `group`, and singular `responsibilityCenterId` requests remain compatible; account opening/closing balances remain company-wide.
- The protected cashflow UI and JSON export support the opt-in reconciliation scope `reconcile=dashboard&activity=<operating|financing|investing|all>&currency=<FiatCurrency>`. It uses the dashboard period and gross split aggregator rules: one currency, no transfers, no deleted movements, no technical categories, and no unallocated category for a specific activity. Project/ЦФО filters are ignored in this scope. Account opening/closing balances keep the existing company-wide report semantics: archived accounts and all categorized movements in the selected currency, including transfers and technical categories, remain included, so balances may differ from both the filtered movement rows and the active-account dashboard balance. Public cashflow JSON/CSV ignore all three reconciliation parameters and keep their existing contract.
- The expand migration performs no fact backfill, classification inference, or P&L rebuild. Existing rows remain `NULL` and later UI/report stages interpret that state as `Не распределено`.
- Stage 7.5 originally left `pl_daily_totals` uniqueness unchanged; Stage 7.7.3 is the reviewed switch point. Stage 7.11 removed the temporary runtime old-schema detection after production migration acceptance, so the runtime writer now requires the Project × ЦФО indexes. `old code / new schema` is not a supported rollback mode after the switch migration because the old writer targets the removed project-only unique key; rollback requires a reviewed forward-fix or redeploying Stage 7.7.3-compatible code. The migration locks `pl_daily_totals` before duplicate guards and is forward-only because restoring the old project-only unique key can collapse separate ЦФО buckets.

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

### Marketplace: загрузка каталога товаров Ozon

Pipeline: `app:marketplace:ozon-listing-catalog:sync` (cron `40 3 * * *`, либо
`--company=<uuid>` вручную) → `SyncOzonListingCatalogMessage` (`async_sync`) →
`SyncOzonListingCatalogHandler` → `RefreshOzonListingCatalogAction`.

- `OzonProductCatalogClient` обходит `/v3/product/list` по `last_id` (limit 1000),
  затем `/v3/product/info/list` чанками по 1000 `product_id`. Первый эндпоинт —
  единственный источник, видящий товары **без продаж**: вход в него не зависит
  от содержимого нашей БД.
- Сырые ответы обоих эндпоинтов уходят в S3 через `RawStorageFacade::storeAndGetIds`,
  ресурсы `ozon_seller_product_list` и `ozon_seller_product_info`,
  `IngestSource::OZON`, `externalId` = `page-N` / `chunk-N`.
- **Ключ сопоставления — всё множество `sources[].sku`, а не верхнеуровневый `sku`.**
  У товара Ozon может быть несколько источников (sds/fbs), у каждого свой sku;
  на реальной выгрузке 50 товаров дали 78 SKU. Листинг, заведённый финансовым
  документом по FBS-схеме, находится только по вторичному sku. Обновляются **все**
  листинги товара.
- Товар, ни один SKU которого не имеет листинга, создаёт **одну** строку по
  верхнеуровневому `sku`. Вторая появится сама при первой продаже по второй схеме.
- `OzonListingCatalogUpsertQuery` — `ON CONFLICT ... DO UPDATE` по `name`,
  `supplier_sku`, `marketplace_created_at`, `last_seen_at`, `marketplace_data`.
  Финансовый `OzonListingUpsertQuery` остаётся `ON CONFLICT DO NOTHING`: общий
  `DO UPDATE` позволил бы финансовому документу перезаписывать каталожное имя.
- Каталог не меняет `is_active` и не пишет колонку `price`. Каталожная цена
  витринная, а не цена продажи, и лежит в `marketplace_data` вместе со статусом,
  признаком архива, картинкой и категорией. `marketplace_data` перезаписывается
  целиком: каталог — единственный писатель этой колонки.
- Глобальной транзакции на прогон нет: upsert идемпотентен, частичное применение
  дозаполняется следующим прогоном, а транзакция на весь каталог держала бы
  блокировки на `marketplace_listings` и мешала бы финансовому pipeline.
- Ручной запуск — `POST /marketplace/listings/sync-ozon-catalog`
  (`SyncOzonListingCatalogController`, CSRF, `ModuleAccess::MARKETPLACE_WRITE`,
  компания из `getActiveCompany()`). Диспатчит по одному сообщению на каждое
  активное Ozon SELLER-подключение компании.
- Каждый прогон пишет `MarketplaceJobLog` с `JobType::LISTING_CATALOG_SYNC_OZON`:
  `running` → `done` со счётчиками `products_fetched` / `listings_upserted` /
  `raw_records_stored`, либо `failed`. В `summary.error` кладётся **класс**
  исключения, а не текст (он может нести детали ответа внешнего API); формат
  один для всех ошибок, включая 429. Итог последнего прогона показывается на
  странице листингов.
- Терминальный статус журнала при ошибке пишется через DBAL
  (`MarketplaceJobLogFailQuery`), а не через ORM: сбой внутри чанковой
  транзакции закрывает `EntityManager` (`wrapInTransaction()` вызывает
  `close()`), и `persist()` подменил бы исходное исключение техническим,
  оставив запись в `running`.
- Взаимное исключение прогонов — блокировка `LockFactory` по
  `(companyId, connectionId)`, TTL 300 с с продлением на границах страниц и
  чанков (`RefreshOzonListingCatalogAction` принимает прогресс-колбэк). Короткий
  TTL не запирает подключение после аварийного завершения воркера, а продление
  не даёт lease протухнуть посреди живого обхода крупного каталога. Триггеров
  два (cron и кнопка); второй прогон отступает — без блокировки он удвоил бы
  запросы к Ozon. Проверка «уже идёт» в контроллере была бы гонкой, поэтому её
  там нет.
- Аварийно оборванный воркер (SIGKILL, OOM) оставляет запись в `running`:
  переводить просроченные прогоны в терминальный статус пока нечем. Это общее
  свойство `MarketplaceJobLog` для всех `JobType`, не только этого — вынесено
  в FOLLOW-UP.
- HTTP 429 поднимает `OzonCatalogRateLimitException`; handler логирует `warning`
  (ретрай, а не инцидент) и пробрасывает исключение как есть. Оборачивать его в
  `RecoverableMessageHandlingException` нельзя: Symfony считает
  `RecoverableExceptionInterface` retryable безусловно, в обход `max_retries`, и
  постоянный 429 крутился бы бесконечно, не доходя до failed-очереди. Обычное
  исключение оставляет в силе `retry_strategy` транспорта `async_sync`.
- Ответ Ozon с нарушенной структурой (`/v3/product/list` без `result.items` или
  без строкового `last_id`, `/v3/product/info/list` без `items`) поднимает
  `OzonCatalogApiException`. Тихо завершать обход нельзя: неполный каталог
  отчитался бы успехом.
- Ответ, из которого не извлеклось **ничего**, тоже поднимает
  `OzonCatalogApiException`. Для карточек счёт ведётся от числа **запрошенных**
  `product_id`, а не от числа элементов ответа: иначе `{"items":[]}` на непустой
  чанк дал бы `received = 0`, ни одно условие не сработало бы, и прогон молча
  отчитался бы успехом, не загрузив ничего.
- Обрабатываются только карточки запрошенных `product_id` (сверка по
  `items[].id`): ответ «не про тот чанк» не подменяет выборку.
- Частичный пропуск ошибкой не считается — один недостающий или мусорный товар
  не должен отменять ночную выгрузку, — но уходит в `warning` со счётчиками
  `requested` / `returned` / `usable` / `skipped`.
- Полнота обхода сверяется с `result.total`, который Ozon отдаёт на каждой
  странице: ноль собранных при непустом каталоге — `OzonCatalogApiException`,
  недобор — `warning` с `reported_total` / `collected` / `missing`. Без сверки
  оборванная пагинация выглядела бы полной выгрузкой. Перебор расхождением не
  считается: каталог мог вырасти по ходу обхода.
- Пустая страница в raw не сохраняется: `RawStorageFacade` отвергает батч без
  строк, а каталог, кратный размеру страницы, штатно отдаёт пустую последнюю
  страницу. Raw кладётся до интерпретации — при непригодном ответе сохранённый
  payload остаётся единственным свидетельством того, что прислал Ozon.
- Каталожный `DO UPDATE` выполняется только при
  `last_seen_at IS NULL OR EXCLUDED.last_seen_at > last_seen_at`. Прогон,
  начавшийся раньше, но завершившийся позже, не подменяет более свежий снимок.
  Сравнение строгое: `last_seen_at` имеет точность в секунду, и при `>=` два
  прогона, стартовавшие в одну секунду, снова перезаписывали бы друг друга.
  Внутри одного прогона повтора нет — у двух листингов одного товара разные
  `marketplace_sku`, то есть разные conflict-ключи.

### Marketplace: даты жизненного цикла листинга

- `MarketplaceListing.createdAt` — момент появления строки **у нас**; проставляется `#[ORM\PrePersist]`.
- `MarketplaceListing.marketplaceCreatedAt` (`?DateTimeImmutable`, колонка `marketplace_created_at`) — дата создания товара **на стороне маркетплейса**. Для Ozon источник — `items[].created_at` из `/v3/product/info/list`. Понятия разные, смешивать нельзя.
- `MarketplaceListing.lastSeenAt` (`?DateTimeImmutable`, колонка `last_seen_at`) — когда листинг последний раз встретился в выгрузке каталога маркетплейса. Пропажа из каталога **не** меняет `isActive`: разбор ручной.
- Оба поля nullable и не заполняются финансовым pipeline: их пишет только каталожная загрузка.

### Marketplace: теги листингов

- Entity: `MarketplaceListingTag` (таблица `marketplace_listing_tags`).
- Назначение: пользовательские теги листингов как аналитическое измерение (сезон, коллекция, статус продвижения).
- Поля: `id` (UUID v7), `companyId`, `name`, `slug`, `createdAt`.
- `slug` = `mb_strtolower(trim(name))`, уникален в паре `(company_id, slug)` — «Зима» / «зима» / «ЗИМА» это один тег.
- Связи «листинг ↔ тег» лежат в таблице `marketplace_listing_tag_assignments` **без ORM-маппинга**: все операции массовые и идут через
  `App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository` (DBAL). FK на `marketplace_listings` и `marketplace_listing_tags` — с `ON DELETE CASCADE`.
- Скоуп компании обеспечивается внутри SQL (`INSERT … SELECT … WHERE l.company_id = :companyId`), а не проверкой в PHP.
- Кросс-модульный доступ — только через `ListingTagFacade` (см. раздел Facade). Внутри модуля Marketplace контроллеры реестра листингов ходят в репозитории напрямую.

### Marketplace: базовый (автоматический) маппинг в ОПиУ

Два независимых конфига в `config/marketplace/`, каждый со своим провайдером, preview- и apply-экшеном. Оба матчат категории ОПиУ по `pl_categories.code`, поэтому работают только на компании со стандартным деревом ОПиУ.

| | Затраты | Продажи и возвраты |
|---|---|---|
| Конфиг | `default_cost_mapping.yaml` | `default_sale_mapping.yaml` |
| Ключ правила | `cost_code` (`marketplace_cost_categories.code`) | `amount_source` (`AmountSource`) |
| Провайдер | `DefaultCostMappingYamlProvider` | `DefaultSaleMappingYamlProvider` |
| Preview / Apply | `PreviewDefaultCostMappingAction` / `ApplyDefaultCostMappingAction` | `PreviewDefaultSaleMappingAction` / `ApplyDefaultSaleMappingAction` |
| Writer | `DefaultCostMappingWriter` | `DefaultSaleMappingWriter` |
| Таблица | `marketplace_cost_pl_mappings` | `marketplace_sale_mappings` |
| Маршруты | `/marketplace/cost-pl-mapping/default/{preview,apply}` | `/marketplace/pl-mappings/default/{preview,apply}` |

Общие правила:

- **Существующее правило не перезаписывается.** Затраты дополняют только пустой `pl_category_id` (`WILL_FILL_EMPTY`), продажи не трогают ничего: активное правило на источник суммы → `SKIPPED_EXISTING`.
- Отсутствующая или не-`LEAF_INPUT` категория ОПиУ, **найденная на preview**, блокирует apply целиком (`hasBlockingIssues()`), а не пропускает строку. Если категория исчезает, меняет код или тип уже во время записи, вставка этого правила не проходит и строка отчитывается как `skipped`; остальные правила сохраняются.
- Правила продаж пишутся одной инструкцией `INSERT … SELECT` из `pl_categories`: категория перепроверяется по компании, коду и типу внутри самой вставки, а частичный индекс `uniq_active_sale_mapping_source` не даёт появиться второму активному правилу на источник суммы.
- Правила продаж создаются сразу активными; уникальный ключ `uniq_sale_mapping` включает `pl_category_id`, поэтому отключённое правило с той же целью занимает место — preview показывает это отдельным сообщением.
- Знак: у всех правил с `operation_type = return` обязателен `is_negative: true` (источники отдают возвраты положительными, а родитель-`SUBTOTAL` суммирует листья напрямую). Инвариант закреплён тестом `tests/Unit/Marketplace/Config/DefaultMappingConfigTest.php`, там же гварды на неизвестные `cost_code` / `pl_code`.
- Коды затрат WB частично динамические: `WbDeductionCalculator` слугифицирует название удержания и режет до 50 символов, поэтому помесячные удержания перечислены в конфиге по букве месяца.

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

### `IngestionFacade` (`src/Ingestion/Facade/IngestionFacade.php`)
```php
// Канонические финансовые транзакции за период для P&L rebuild.
// Отдаёт read-only DTO (НЕ managed Entity) — Entity не пересекает границу модуля.
// Генератор-проектор: память не растёт на больших периодах.
// @return iterable<App\Ingestion\Application\DTO\FinancialTransactionView>
getTransactions(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $shopRef = null): iterable

// Количество открытых normalization issues по компании.
countOpenIssues(string $companyId): int

// Verification UI/API read models.
// @return array{cells: list<CoverageCellView>, shops: list<ShopOptionView>}
getCoverage(string $companyId, ?string $shopRef, DateTimeImmutable $from, DateTimeImmutable $to): array

// @return array{summary: ReconciliationSummaryView, byType: list<ReconciliationByTypeView>}
getReconciliation(string $companyId, string $shopRef, int $year, int $month): array

// @return array{items: list<IssueListItemView>, meta: PaginationMeta}
listIssues(string $companyId, ?string $shopRef, ?int $year, ?int $month, int $page, int $limit): array

// @return array{byMonth: list<FinancialSummaryMonthView>, byCategory: list<FinancialSummaryCategoryView>}
getFinancialSummary(string $companyId, ?string $shopRef, int $yearFrom, int $monthFrom, int $yearTo, int $monthTo): array
```

### `MarketplaceListingFacade` (`src/Ingestion/Facade/MarketplaceListingFacade.php`)
```php
findBySupplierSku(string $companyId, string $marketplace, string $supplierSku): ?string
findByMarketplaceSku(string $companyId, string $marketplace, string $marketplaceSku): ?string
findByBarcode(string $companyId, string $marketplace, string $barcode): ?string
```

### `MarketplaceSyncFacade` (`src/Marketplace/Facade/MarketplaceSyncFacade.php`)
```php
// Активные SELLER-подключения ОДНОЙ компании: по одному на кабинет продавца.
// Ingestion ходит сюда за парами (компания, маркетплейс) — прямой вызов
// Marketplace\Infrastructure\Query нарушил бы границу модулей.
activeSellerConnections(string $companyId): list<ActiveSellerConnectionDTO>

// Страница реестра ВСЕХ компаний, keyset-курсор по connectionRef.
// @companyScopeExempt — системный обход для cron.
activeSellerConnectionsPage(int $limit, ?string $afterConnectionRef = null): list<ActiveSellerConnectionDTO>
// ActiveSellerConnectionDTO: connectionRef, companyId, marketplace
```

Два метода, а не один с nullable-параметром: межкомпанейский проход нельзя
получить случайно, попросив «просто подключения», — он назван отдельно, требует
явного лимита и несёт документированный `@companyScopeExempt`. Курсор keyset, а
не OFFSET: подключение, добавленное или отключённое между страницами, сдвинуло
бы OFFSET, и одно подключение обработалось бы дважды, а другое — ни разу.

### `CompanyFacade` (`src/Company/Facade/CompanyFacade.php`)
```php
// Создать пользователя-владельца, компанию и активного CompanyMember OWNER.
// Используется внешними модулями, включая Admin, вместо прямого вызова Company Service/Application.
createOwnerAccount(string $email, string $plainPassword, string $companyName): Company

// Найти компанию по ID
findById(string $companyId): ?Company

// Глобально разрешить точное название без учёта регистра в единственный ID.
// InvalidArgumentException — пустое название; DomainException — совпадений нет или несколько.
resolveIdByName(string $name): string

// ID всех активных компаний
// @return list<string>
getAllActiveCompanyIds(): array

// Компании по списку ID как простые DTO-массивы
// @param list<string> $companyIds
// @return list<array{id: string, name: string}>
getCompaniesByIds(array $companyIds): array

// Контрагент строго в рамках компании — защита от обращения к чужим данным по id
findCounterpartyByIdAndCompany(string $counterpartyId, string $companyId): ?Counterparty

// Компании, доступные пользователю: которыми он владеет либо в которых
// состоит активным CompanyMember. Для выбора компании при межкомпанийных
// операциях (например, импорт справочников).
// @return list<array{id: string, name: string}>
listAccessibleCompaniesForUser(string $userId): array

// Владелец компании либо активный CompanyMember — иначе доступа нет.
userHasAccess(string $companyId, string $userId): bool
```

### Справочник контрагентов (`Company`)

`CounterpartyFacade` (`src/Company/Facade/CounterpartyFacade.php`) — единственная точка
доступа соседних модулей к справочнику **для форм**:

```php
// Варианты выбора как DTO: Entity чужого модуля в формы не попадает.
// Архивные не предлагаются, но уже выбранный архивный остаётся (keepId).
// @return list<CounterpartyChoiceDTO>  id, name, inn, kpp, isArchived, label()
getSelectable(string $companyId, ?string $keepId = null): array

// Контрагент строго в рамках компании — для форм, которым нужна сама сущность.
findEntityByIdAndCompany(string $counterpartyId, string $companyId): ?Counterparty
```

Выбор контрагента в формах — только через `CounterpartyPickerType`
(`src/Company/Form/Type/CounterpartyPickerType.php`): `company_id` обязателен и
проверяется как UUID, поэтому tenant-фильтр нельзя забыть; `choices` — полный
company-scoped список, он же no-JS fallback и граница допустимых значений при submit;
`value_type: 'entity'` подключает `CounterpartyEntityTransformer` (id ↔ Entity) для
форм, привязанных к сущности. Разметка — `templates/form/counterparty_picker_theme.html.twig`,
поиск — `assets/counterparty_picker.js` через `GET /api/counterparties/search`.
`EntityType` с `Counterparty` в проекте больше не используется.

Вне форм `CounterpartyRepository` пока импортируется напрямую из `DealManager`,
`CreatePLDocumentAction`, `FinanceFacade`, `ScoreCompanyCounterpartiesAction` — остаток
долга, отдельная задача.

Название контрагента разделено на три роли и записывается только целиком:

```php
// src/Company/Domain/ValueObject/CounterpartyName.php — immutable VO
// display        — как ввёл пользователь: 'ООО "Ромашка"'
// legalFormHint  — разобранная ОПФ: 'ООО' | null. АРТЕФАКТ РАЗБОРА СТРОКИ,
//                  не правовой статус: не показывать пользователю, не ветвить
//                  бизнес-логику. Статус — CounterpartyType и длина ИНН.
// core           — нормализованное название для поиска и сравнения: 'РОМАШКА'
//
// Конструктор приватный: создать VO можно только нормализатором.

// src/Company/Domain/Service/CounterpartyNameNormalizer.php
// Чистый детерминированный сервис, единственная точка нормализации.
normalize(string $rawName): CounterpartyName

// Entity Counterparty: name/legalFormHint/nameCore пишутся только вместе
rename(CounterpartyName $name): void          // + touch updatedAt
refreshNormalizedName(CounterpartyName $name) // backfill: updatedAt не меняется
assignTaxIds(?string $inn, ?string $kpp)      // КПП без ИНН — исключение
hasInconsistentLegalFormHint(): bool          // диагностика: 'ИП' + 10-значный ИНН
effectiveLegalFormHint(CounterpartyName): ?string  // подсказка после кросс-проверки по ИНН
// assignTaxIds() сам сбрасывает 'ИП' при 10-значном ИНН — инвариант держится
// во всех путях записи, включая импорт 1С
belongsToCompany(string $companyId): bool     // IDOR-guard
archive(): void / restore(): void
// setName/setInn/setCompany/setIsArchived/setUpdatedAt удалены намеренно

// src/Company/Application/SaveCounterpartyAction.php
// Создание и изменение записи справочника (нормализация, проверка ИНН,
// сброс несогласованной подсказки ОПФ с логом warning).
__invoke(Company $company, CounterpartyFormData $data, ?Counterparty $counterparty = null): Counterparty

// src/Company/Application/BackfillCounterpartyNamesAction.php
// Идемпотентный пересчёт производных полей; updatedAt не трогает.
// CLI: app:counterparty:backfill-names [--dry-run] [--report-company-id] [--similarity]
__invoke(bool $dryRun): CounterpartyBackfillResult

// src/Company/Infrastructure/Query/CounterpartySearchQuery.php — DBAL, скаляры
// Цифры → префикс по inn; иначе nameCore префикс + similarity(nameCore) > 0.3.
// Всегда company_id и is_archived = false, LIMIT 20.
search(string $companyId, string $query, int $limit = 20): array

// src/Company/Infrastructure/Query/CounterpartyDuplicateCandidatesQuery.php
// Только отчёт, ничего не меняет. ОПФ обязана совпадать: ООО и АО с одним
// названием — разные юрлица.
findSimilarNamePairs(float $threshold = 0.6, ?string $companyId = null): array
findSameInnGroups(?string $companyId = null): array
findInvalidInnRows(?string $companyId = null): array

// src/Company/Repository/CounterpartyRepository.php
findSelectableByCompany(string $companyId, ?string $keepId = null): array // архивные скрыты, выбранное остаётся
findOneByIdAndCompany(string $id, string $companyId): ?Counterparty
findOneByNormalizedName(string $companyId, string $nameCore, ?string $legalFormHint): ?Counterparty
findOneByInn(string $companyId, string $inn, ?string $exceptId = null): ?Counterparty
findAllForBackfill(): iterable
```

Публичный endpoint: `GET /api/counterparties/search?q=` — автокомплит,
companyId только из сессии, ответ `[{id, name, inn, kpp, type}]` без пагинации.
`legalFormHint` в ответе намеренно отсутствует.

`name_core` в БД пока nullable: `SET NOT NULL` — contract-миграцией после
backfill на PROD (`docs/tasks/counterparty-name-normalization/plan.md`).

### `FinancialResponsibilityCenterFacade` (`src/Company/Facade/FinancialResponsibilityCenterFacade.php`)

```php
// Активные ЦФО компании как scalar DTO для форм и соседних модулей.
// @return list<FinancialResponsibilityCenterDTO>
getActiveChoices(string $companyId): array

// Один ЦФО с обязательной проверкой company boundary.
// DTO содержит optimistic-lock version для последующей записи.
findByIdAndCompany(string $id, string $companyId): ?FinancialResponsibilityCenterDTO

// Системная пара PROJECT_GENERAL × CFO_GENERAL компании как scalar DTO.
findGeneralPair(string $companyId): ?FinancialResponsibilityCenterProjectDTO

// Один company-scoped snapshot всех активных разрешённых пар для batch planning.
// @return list<FinancialResponsibilityCenterProjectDTO>
getActivePairs(string $companyId): array

// Проверка разрешённой пары project × ЦФО в рамках компании.
isProjectAllowed(string $companyId, string $projectDirectionId, string $responsibilityCenterId): bool

// @return list<string> project direction IDs
getAllowedProjectIds(string $companyId, string $responsibilityCenterId): array
```

Внутреннее управление справочником выполняют company-scoped Actions:

- `CreateFinancialResponsibilityCenterAction` — создаёт обычный ЦФО с автоматически сгенерированным стабильным кодом;
- `UpdateFinancialResponsibilityCenterAction` — изменяет имя/сортировку с expected version;
- `ArchiveFinancialResponsibilityCenterAction` — архивирует обычный ЦФО с expected version;
- `ConfigureFinancialResponsibilityCenterProjectsAction` — атомарно заменяет разрешённые проекты, повышает версию ЦФО и не позволяет удалить системную пару.

Actions не являются публичным cross-module API; соседние модули используют только facade/DTO.

Защищённый справочник доступен существующим пользователям через `Справочники → ЦФО` (`/financial-responsibility-centers/`). Twig-контроллеры явно ограничивают каждое чтение активной компанией и передают изменения существующих записей в Actions с expected version. Основные данные и разрешённые проекты сохраняются отдельными POST-формами, чтобы конфликт одной операции не приводил к частичному применению другой. Проекты передаются в форму по UUID, а отображаются полным путём по дереву, поэтому совпадающие названия не скрывают допустимые пары. Системный ЦФО отображается в списке, но не может быть переименован или архивирован; его системную пару нельзя снять.

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
    ?string $projectDirectionId = null,
): string  // ID созданного документа

// Физически удалить технический PL-документ при переоткрытии месяца Marketplace.
// Не использовать для ручного удаления из UI.
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

Ручное удаление на странице «Операции ОПиУ» является мягким: `Document` получает
`deleted_at`, `deleted_by` и `delete_reason`, а основные списки, отчёты и JSON-выгрузки
читают только неудалённые документы. `SoftDeleteDocumentAction` и
`RestoreDocumentAction` ограничивают поиск активной компанией, сохраняют операции
документа, пересчитывают связанное распределение транзакции ДДС и регистр ОПиУ за
день документа. Восстановление связанного с ДДС документа отклоняется, если его сумма
больше доступного остатка транзакции. Удалённые записи доступны на отдельной вкладке
`/documents/deleted`; просматривать, редактировать, копировать и экспортировать их как
активные документы нельзя.

Технический `FinanceFacade::deletePLDocument()` намеренно сохраняет физическую
семантику: его использует переоткрытие месяца Marketplace для удаления и повторного
построения производных документов.

### `BalanceFacade` (`src/Balance/Facade/BalanceFacade.php`)
```php
// Дерево категорий баланса компании (вложенные children)
// @return list<array{id: string, name: string, level: int, type: string, children: list<mixed>}>
getCategoriesForCompany(string $companyId): array

// Плоский список категорий для ChoiceType: "— Название" => id
// @return array<string, string>
getCategoryChoicesForCompany(string $companyId, array $excludeCategoryIds = []): array

// Отчёт «Баланс» на дату
getReportForCompany(string $companyId, \DateTimeImmutable $date): BalanceReport

// Создать дефолтную структуру баланса, если у компании её ещё нет.
// Вызывать после flush компании. true — структура создана, false — уже была.
seedDefaultStructure(string $companyId): bool
```

> **Остальные Facade** добавлять сюда по мере реализации модулей.

### Cash transaction auto-rule governance

- `CashTransactionAutoRule` is company-owned and has an immutable company, a Doctrine-managed optimistic-lock `revision`, lifecycle timestamps, and nullable user IDs for create/update/disable actors.
- Rules are disable-only: an inactive rule cannot be re-enabled or physically deleted through the Cash auto-rule UI.
- One matcher is shared by preview, manual apply, and the worker. It resolves category, project, ЦФО, and counterparty by the same priority, specificity (condition count plus a direction constraint), and immutable-rule-ID ordering. Project and ЦФО winners are then applied as one validated pair; category and counterparty remain independent.
- Exact conditions may additionally match a company-owned money account, currency, import-source identifier, transfer flag, and normalized document type. Money accounts use a foreign-key identity and are restricted to the rule company. Missing import lineage is represented explicitly by `__MISSING__`; it is not treated as an empty condition value. Existing source data is not normalized or backfilled by the rule module.
- The authenticated company-scoped preview is read-only and always models the `safe` application plan; it does not predict `replace_auto_assigned` changes. It uses one active-pair snapshot, limits only displayed rows, and calculates full-period counts plus independent month, currency, resulting-category, resulting-project, and resulting-ЦФО breakdowns.
- The authenticated rule-candidate report is also read-only and company-scoped. It inspects the last 180 days, accepts only the latest user-confirmed category assignment, requires at least five samples across three operation dates with 100% category consistency, and proposes one exact condition signal at a time. It returns at most 100 category-only candidates and never creates rules, dispatches work, or changes historical transactions.
- A category/counterparty conflict skips only its field. A project or ЦФО conflict blocks both paired changes. Existing custom pairs are preserved, the exact `PROJECT_GENERAL × CFO_GENERAL` pair is an eligible placeholder, incomplete/unavailable pairs fail closed, `CF_UNALLOC` remains empty for category filling, and null rule targets never clear transaction fields.
- Range processing has two explicit modes. `safe` preserves the existing fill-only behavior. `replace_auto_assigned` may additionally replace an assigned category or the complete project×ЦФО pair only when the latest same-company field audit proves that the current value was assigned by an auto rule and still equals that audit's `after` value. A later manual audit, unknown provenance, conflicting same-second audit, or a manually owned half of the pair fails closed and preserves the complete pair. Counterparties are never force-replaced, and a missing current winner never clears an old value.
- Each actual auto-rule mutation persists one `AuditLog` for the `CashTransaction` in the same flush. Its diff contains a range/transaction correlation UUID, apply mode, per-field rule ID/revision, and changed field before/after IDs; descriptions, INNs, and bank payloads are excluded. User-launched range work also propagates the initiating user ID to the audit record.
- Range messages propagate one correlation UUID to every child transaction message and safe structured log. Optional message fields plus native-serialization wakeup keep payloads queued before this contract backward-compatible.
- `AutoRuleDispatchGuard` carries the application plan during that flush so the generic transaction audit subscriber does not duplicate the explicit provenance record.
- `app:cash-auto-rules:assign-general-cfo` is a manual transition command for Stage 7.9.2. It is read-only by default and targets only active `PROJECT_GENERAL` rules without a ЦФО. `--execute` requires an existing approving user UUID and the exact candidate count from dry-run, validates every company's active `PROJECT_GENERAL × CFO_GENERAL` pair before changing any rule, and commits all assignments atomically through Doctrine so rule revision and actor metadata are preserved. It never applies rules to transactions or processes history.

### Cash: разбивка транзакции ДДС по категориям (`cash_transaction_split`)

- `CashTransactionSplit` — строка разбивки: транзакция, `string $companyId`, категория ДДС, положительная сумма и `CashTransactionSplitSource` (`manual` / `auto` / `import`). Уникальна пара (транзакция, категория); удаление транзакции каскадно удаляет строки.
- `CashTransaction::replaceSplits()` — единственная точка проверки инвариантов набора: состав непустой, сумма строк строго равна сумме транзакции, категории не повторяются, все строки принадлежат этой транзакции. При количестве строк больше одной запрещены категории с `allowPlDocument = true` — документы ОПиУ строятся из одной категории транзакции, и мультиразбивка их семантику не поддерживает. Строки с совпадающей категорией переиспользуются, а не пересоздаются: пара DELETE+INSERT по одному ключу в одном flush падает на уникальном индексе.
- `CashTransactionSplitSynchronizer` — dual-write на переходный период: строки повторяют `cash_transaction.cashflow_category_id` один в один, включая её пустое состояние (нет категории → нет строк). Вызывается из ручного создания и редактирования (`CashTransactionService`), воркера автоправил и ручного применения правила в контроллере. Синхронизация безусловна: защита ручной категоризации от автоправил живёт на уровне колонки (режимы `SAFE` и `REPLACE_AUTO_ASSIGNED` плюс провенанс из `AuditLog`), и дублировать её на уровне строк нельзя — ранний выход оставил бы колонку и строки рассинхронизированными.
- Происхождение строк описывает колонка `source`, а не аудит: `CashTransactionAutoRuleProvenanceResolver` восстанавливает провенанс скалярного поля из истории `AuditLog` и на коллекции не работает. На поле `splits` резолвер не расширяется. `source` меняется только вместе с категорией: правка суммы его не трогает, иначе редактирование суммы человеком помечало бы авто-категорию как ручную.
- Суммы строк валидируются на точность до двух знаков: bcmath со scale 2 усекает лишнее, а `NUMERIC(18,2)` округляет, поэтому без проверки «1.999» прошло бы инвариант как «1.99» и легло бы в БД как «2.00».
- Изменение состава строк пишет один aggregate-`AuditLog` на `CashTransaction` с `diff['splits'] = [before, after]`. Не аудируется только состав новой транзакции — он покрыт её CREATE-записью; первое назначение категории существующей транзакции (например пришедшей импортом) аудируется, потому что дифф скалярной колонки не содержит `source`. Во время работы автоправил запись подавляется, потому что план правила пишет собственный аудит.
- `app:cash:backfill-transaction-splits` переносит колонку в строки: батчами, идемпотентно (берёт только транзакции без строк), `source` определяется существующим провенанс-резолвером. Без `--execute` только считает объём. `app:cash:verify-transaction-splits` сверяет построчно — состав, суммы, company/категорию, orphan-строки — и печатает своё покрытие; ненулевой exit code при любом расхождении.

- Stage 3 разбивки ДДС: все читатели категории транзакции переведены на строки `cash_transaction_split` — отчёт ДДС, ведомость, тех. сверка ops, экспорт и агрегации `CashTransactionRepository`, candidate-query автоправил, prefiller, AI-агент, `PaymentPlanMatcher`, оба пути создания документа ОПиУ и шаблоны. Колонка `cash_transaction.cashflow_category_id` остаётся только на пути записи (dual-write) до отдельной contract-задачи.
- `CashFacade::serializeTransaction()` отдаёт массив `splits` (`categoryId`, `categoryName`, `amount`). Поле `category` сохранено для обратной совместимости и заполняется, только когда строка одна; при разбивке оно `null`, потому что одна категория из нескольких была бы ложью для интеграции.
- Транзакция без категории строк не имеет, поэтому все читатели используют `LEFT JOIN` и `COALESCE(split.amount, t.amount)` там, где такие транзакции обязаны учитываться. `INNER JOIN` молча выкинул бы их из ведомости, выгрузки и дашборда.
- Потоковый экспорт (`iterateByCompanyWithFilters`) переведён с `toIterable()` на `getArrayResult()`: Doctrine запрещает итерировать запрос с join коллекции, а одна строка выгрузки на строку разбивки без такого join не получается. Потолок зафиксирован в коде.

- Stage 4 разбивки ДДС: ручная разбивка сделана отдельным действием `/finance/cash-transactions/{id}/splits` (`CashTransactionSplitsController`), а не переделкой формы транзакции — обычная операция с одной статьёй пишется прежним путём. `SaveCashTransactionSplitsAction` собирает строки с `source = manual`, отдаёт проверку инвариантов агрегату, проецирует legacy-колонку и снимает устаревший `PaymentPlanMatch`.
- При разбивке колонка `cash_transaction.cashflow_category_id` проецируется в системную «Не распределено», а не в `NULL`: суммы отчёта по колонке остаются верными, и точкой невозврата остаётся только `DROP COLUMN`.
- Автоправила не трогают вручную разбитые операции: `CashTransactionAutoRuleService::getSkipReason()` возвращает `CashTransactionAutoRuleSkipReason::MANUAL_SPLIT`. Проверка стоит там же, где «удалено» и «закрытый период», а не в синхронизаторе строк — ранний выход в синхронизаторе оставил бы колонку и строки рассинхронизированными.
- `CashflowCategoryRepository::findOneByIdAndCompanyId()` — выбор статьи из формы строго в пределах компании.

### Cash: валютный контракт счетов и обычных транзакций

- `App\Cash\Enum\FiatCurrency` — единый список поддерживаемых валют Cash:
  `RUB`, `USD`, `EUR`, `KZT`; формы, DTO и import/write-paths не должны
  дублировать этот список строковыми массивами.
- Валюта `MoneyAccount` задаётся при создании и неизменяема после первого
  сохранения. Для уже сохранённого счёта смена валюты требует отдельной
  миграционной процедуры, а не обычного редактирования. `CRYPTO_WALLET`
  остаётся вне fiat-контракта и сохраняет свои существующие коды валют.
- Валюта `CashTransaction` является производной от выбранного счёта. Ручная
  запись, `CashFacade` и импорты отклоняют неподдерживаемую валюту и mismatch
  со счётом; при допустимой смене счёта транзакция получает его валюту.
- Пользовательские ссылки транзакции (счёт, контрагент, статья, проект и ЦФО)
  разрешаются только в пределах компании транзакции; переданный UUID другой
  компании не превращается в Doctrine reference.
- `PaymentPlan` пока не имеет собственной валюты, поэтому автоматический
  matcher рассматривает только RUB-транзакции. Существующие сохранённые связи
  остаются читаемыми независимо от валюты для обратной совместимости.

### Cash: агрегат перевода между денежными счетами (`cash_transfer`)

- `CashTransfer` связывает ровно две уникальные `CashTransaction`: исходящую
  ногу со счёта-источника и входящую на счёт-получатель. Суммы и валюты не
  дублируются в агрегате: источником истины остаются транзакции.
- Обе ноги принадлежат одной компании, разным активным не-криптовалютным
  счетам и имеют одну дату. Они помечены `isTransfer=true` и содержат по одной
  строке разбивки: `CF_TECH_OUT` для списания и `CF_TECH_IN` для поступления.
  Это обязательные активные системные дочерние категории корня `CF_TECH`.
- Для перевода в одной валюте фактические суммы должны совпадать, FX-поля
  остаются `NULL`. Кросс-валютный v1 разрешает только `RUB↔USD` и `RUB↔EUR`;
  пользователь передаёт обе фактические суммы, а агрегат хранит производный
  effective rate «валюта назначения за единицу валюты источника» со scale 18,
  датой операции и источником `manual_effective`. Float не используется.
- `CreateCashTransferAction` выполняет агрегат, ноги, разбивки, аудит и
  пересчёт обоих счетов в одной DB-транзакции. PostgreSQL advisory lock и
  unique `(company_id, idempotency_key)` сериализуют повторную команду;
  duplicate-result не повторяет side effects. Snapshot cache инвалидируется
  один раз после commit. Ключ идентифицирует первую принятую команду, не
  является dedupe hash её полей и должен повторно отправляться только с тем же
  payload; повтор всегда возвращает исходный агрегат.
- Технические ноги обходят VAT, `PaymentPlanMatcher` и автоправила. Комиссия
  банка является отдельной обычной исходящей операцией, а не третьей ногой.
- `CashTransferLifecycleAction` удаляет или восстанавливает агрегат и обе ноги
  только вместе, под company-scoped pessimistic lock и в одной DB-транзакции.
  Операция повторно проверяет состояние пары и открытость периода, пишет аудит
  агрегата и обеих ног, пересчитывает оба счёта в стабильном UUID-порядке и
  инвалидирует snapshot cache один раз после commit.
- Обычные edit/delete/restore, ручная разбивка и bulk-delete отвергают
  транзакцию, если она является ногой `cash_transfer`. Legacy-транзакции с
  `isTransfer=true`, не связанные с агрегатом, сохраняют прежнее поведение и
  не спариваются автоматически.
- ДДС-отчёт читает технические split-строки в исходной валюте каждой ноги:
  same-currency перевод даёт нулевой net, а cross-currency перевод остаётся
  двумя независимыми движениями без пересчёта и смешанного итога. Soft-delete
  агрегата исключает из отчёта обе ноги.
- UI агрегата расположен под `/finance/cash-transfers`: форма разрешает только
  company-scoped активные fiat-счета, hidden UUIDv7 служит idempotency key, а
  create/delete/restore делегируют финансовую семантику в `CashFacade`.
  Связанная нога ведёт на show агрегата и не предоставляет отдельные edit,
  split, delete, restore или bulk actions.
- Read-only команда `app:cash:verify-transfers` обрабатывает детальную область
  пакетами компаний и сверяет структуру пары, tenant/account/currency/direction,
  обязательные `CF_TECH_OUT`/`CF_TECH_IN` split-строки, суммы same-currency,
  effective-rate, pair deletion, idempotency и уникальное владение ногами.
  Две проверки глобальной уникальности (`company + idempotency key` и владение
  ногой между source/target ролями) выполняются отдельными aggregate scans,
  чтобы не скрыть нарушение на границе company batches.
  Вывод содержит только агрегированные счётчики; legacy `isTransfer=true` без
  агрегата — INFO, а не ошибка. Repair/execute режима у команды нет.

### Analytics dashboard: валюта Cash-виджетов

- `GET /api/dashboard/v1/snapshot` принимает `currency` из `FiatCurrency` и
  по умолчанию использует `RUB`. Ответ возвращает выбранную валюту в
  `context.cash_currency`; cache key, telemetry, warmup и Cash drilldowns
  содержат тот же код валюты.
- Free cash, зарезервированные фонды, inflow/outflow, CAPEX, cashflow split и
  top-cash фильтруются по валюте до `SUM`. Revenue, profit и top-P&L не зависят
  от Cash currency selector и сохраняют текущую семантику ОПиУ.
- Список операций и XLSX export используют единый `CashTransactionFilters` и
  поддерживают `currency`; company scope применяется независимо от фильтра,
  а пустой параметр сохраняет прежний список всех валют без их агрегации.
- Home и dashboard UI сохраняют выбранную RUB/USD/EUR/KZT валюту в URL и
  передают её в snapshot API. Server-rendered Home balance учитывает только
  активные счета выбранной валюты; URL без параметра намеренно означает RUB.

### Company: общий минимальный остаток

- `Company.minimumBalance` хранится как embedded shared `Money` и по умолчанию
  равен `0 RUB`. Значение не может быть отрицательным.
- Это общий порог для сводного графика компании. Существующий
  `MoneyAccount.minimumSafeBalance` остаётся отдельным account-level полем и
  автоматически в общий порог не агрегируется.
- Порог сравнивается с остатком только при совпадении валют; автоматической
  FX-конвертации нет.

### Legacy dashboard: динамика остатка

- `GET /api/finance/dashboard/balance-dynamics` принимает `period=30|60|90`
  (default `30`) и `currency=RUB|USD|EUR|KZT` (default `RUB`). Компания всегда
  берётся из authenticated active-company context.
- Остаток каждого дня — сумма активных счетов выбранной валюты на конец дня:
  последний snapshot не позже даты либо opening balance, если snapshot ещё не
  создан. До даты открытия счёт в сумму и проверку порога не входит. Fallback на
  opening balance нужен только до первого snapshot; актуальность snapshot-ов
  обеспечивает штатный cash balance recalculation.
- Если за весь период нет ни одного активного счёта выбранной валюты, endpoint
  возвращает `points: []`: это empty state графика, и линии потоков также не
  строятся.
- Потоки агрегируются по `CashTransactionSplit` со знаком transaction direction
  и тем же dashboard scope, что сверка ДДС: без transfer, deleted, technical и
  unallocated строк. Потоки сохраняют исторические операции неактивных счетов,
  как отчёт ДДС; фильтр active применяется только к линии текущих остатков. Все
  суммы API — decimal strings, без FX и float arithmetic.
- Legacy `/finance` монтирует React-виджет в
  `#finance-balance-dynamics-root` через отдельный Vite entry
  `finance_balance_dynamics`; app-mode `/finance` и `/dashboard` этот entry не
  подключают.
- Виджет имеет независимый от KPI период 30/60/90 дней, строит native SVG без
  chart-зависимости и подключает переиспользуемые стили
  `assets/styles/components/financial-chart.css`.

### `CashFacade` (`src/Cash/Facade/CashFacade.php`)
```php
// Создать ДДС-транзакцию из внешнего модуля (идемпотентно для внешних источников)
createTransaction(CreateCashTransactionCommand $command): CreateCashTransactionResult

// Атомарно создать перевод между двумя счетами (идемпотентно в пределах компании)
createTransfer(CreateCashTransferCommand $command): CreateCashTransferResult

// Атомарно soft-delete агрегат и обе ноги
deleteTransfer(string $companyId, string $transferId, ?string $actorUserId = null, ?string $reason = null): void

// Атомарно восстановить агрегат и обе ноги
restoreTransfer(string $companyId, string $transferId, ?string $actorUserId = null): void

// Чтение: постраничный список транзакций компании, per_page ≤ CashFacade::MAX_PER_PAGE (200)
listTransactions(string $companyId, array $filters = [], int $page = 1, int $perPage = 50): array

// Чтение: плоское дерево статей ДДС компании (id, name, level, parentId, status, flowKind, sort, isSystem)
listCashflowCategories(string $companyId): array

// Чтение: автоправила компании вместе с условиями
listAutoRules(string $companyId): array

// Запись: создать (без id) или изменить (с id) статью ДДС, возвращает id
upsertCashflowCategory(string $companyId, CashflowCategoryInput $input): string

// Запись: создать (без id) или изменить (с id) автоправило, возвращает id
upsertAutoRule(string $companyId, AutoRuleInput $input, ?string $actorUserId = null): string
```

Все методы принимают `companyId` и бросают `\DomainException`, если компания или
запрошенная сущность к ней не относится. Во входных DTO `null` означает «не менять»,
кроме tri-state поля `CashflowCategoryInput::parentId`: `parentIdProvided=false`
сохраняет текущего родителя, UUID меняет его, а явный `null` при
`parentIdProvided=true` переносит обычную категорию в root.

Обычные root-категории ДДС хранят собственный `flowKind`; дочерние наследуют
его от root. Обычные категории могут быть дочерними у `CF_OP`, `CF_FIN`, `CF_INV`
или обычной нетехнической категории. `CF_TECH`, `CF_TECH_IN`, `CF_TECH_OUT`, `CF_UNALLOC`
не принимают обычных потомков, а `TECHNICAL` зарезервирован для системных категорий.

**Назначение:** `CashFacade` — единственный публичный контракт Cash-модуля для чтения и записи данных ДДС из других модулей (в том числе из MCP-инструментов).

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
- `responsibilityCenterId` (`nullable`; accepted with `projectDirectionId` only, otherwise `null/null` resolves to the company system pair)

**DTO результата:** `CreateCashTransactionResult` (`src/Cash/Application/DTO/CreateCashTransactionResult.php`)
- `transactionId: string`
- `created: bool`
- `duplicate: bool`

**Side effects:** создание через `CashFacade::createTransaction()` сохраняет все side effects `CashTransactionService::add()`:
- VAT logic;
- project × ЦФО pair resolution through the company-scoped Cash resolver;
- `PaymentPlanMatcher`;
- `ApplyAutoRulesForTransaction`;
- `DailyBalanceRecalculator`;
- `SnapshotCacheInvalidator`.

#### Регистр ежедневных остатков

- `AccountBalanceService` — единственная реализация формулы `opening + inflow - outflow = closing`.
- `DailyBalanceRecalculator` только выбирает счета компании и делегирует пересчёт каноническому сервису.
- `MoneyAccount.openingBalanceDate` — начало расчётного учёта по счёту; более ранние операции остаются в реестре, но не входят в остатки.
- Будущая `openingBalanceDate` запрещена формой счёта и сервисом пересчёта.
- `openingBalance` является остатком на начало `openingBalanceDate`; далее `opening` каждого дня равен `closing` предыдущего дня.
- Soft-deleted операции исключаются. `isTransfer` не влияет на остаток: направление определяется только `INFLOW`/`OUTFLOW`.
- Денежная арифметика выполняется decimal-строками через BCMath; снапшоты создаются непрерывно по календарным дням.
- Методы чтения не запускают пересчёт: снапшоты создаются после изменения счёта, операций, импорта или явной команды пересчёта.
- Пересчёт одного счёта выполняется в DBAL-транзакции под PostgreSQL advisory lock, удаляет производные снапшоты до актуальной `openingBalanceDate` и сериализует конкурентные запуски.
- `MoneyAccount.currentBalance` пока сохраняется как совместимое поле и обновляется DBAL-запросом до последнего `closing` не позже текущей даты; источником истины остаётся `MoneyAccountDailyBalance`.

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

// Синхронно пересчитать дневные снапшоты заданных листингов в пределах периода.
// Публичная cross-module точка для каскадов после изменения себестоимости.
// @param list<string> $listingIds
recalculateSnapshotsForListings(string $companyId, array $listingIds, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, string $marketplace): void

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

### `ListingTagFacade` (`src/Marketplace/Facade/ListingTagFacade.php`)
```php
// Справочник тегов листингов компании (для фильтров/автоподсказки)
// @return list<ListingTagDTO> {id, name}
list(string $companyId): array

// Листинги компании, помеченные заданными тегами.
// matchAll=false — любой из тегов; matchAll=true — все теги сразу. Пустой $tagIds → [].
// @param list<string> $tagIds
// @return list<string> listingId
listingIdsByTags(string $companyId, array $tagIds, bool $matchAll = false): array

// Теги по набору листингов одним запросом (без N+1 в списочных таблицах)
// @param list<string> $listingIds
// @return array<string, list<ListingTagDTO>> ключ — listingId
tagsForListings(string $companyId, array $listingIds): array
```
Потребитель: `MarketplaceAnalytics` (фильтр `tags[]` + колонка тегов в расширенной юнит-экономике).

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

// Точное пакетное сопоставление вариантов маркетплейса с листингами.
// MarketplaceListing.marketplaceVariantId хранит chrtId для Wildberries.
// @param list<string> $marketplaceVariantIds
// @return array<string, array{id: string, parentSku: string, variantId: string, size: string}>
findListingsByMarketplaceVariantIds(string $companyId, string $marketplace, array $marketplaceVariantIds): array

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
getConnectionCredentials(string $companyId, MarketplaceType $marketplace, MarketplaceConnectionType $connectionType, ?string $connectionRef = null): ?array

// Безопасный публичный контракт активных Ozon SELLER-подключений
// (без apiKey / clientSecret / settings / credentials)
// @return list<array{connectionId: string, companyId: string, marketplace: string, connectionType: string, clientId: ?string}>
getActiveOzonSellerConnections(?string $companyId = null): array

// Безопасный публичный контракт активных Wildberries SELLER-подключений
// @return list<array{connectionId: string, companyId: string, marketplace: string, connectionType: string}>
getActiveWbSellerConnections(?string $companyId = null): array

// Пакетный резолв listingId → productId|null. Используется Inventory модулем
// для маппинга raw API ответов в StockSnapshot записи. IDOR-защита через
// WHERE company_id, чужие листинги отсутствуют в результате. Для orphan-
// листингов (product = null) возвращается null. Limit 5000 listingIds за вызов.
// @param  array<string>             $listingIds
// @return array<string, string|null> map listingId → productId|null
resolveListingsToProducts(string $companyId, array $listingIds): array

// Загружает активные и находящиеся в корзине WB Product Cards для SELLER-подключения
// и атомарно обновляет MarketplaceListing.marketplaceVariantId (chrtId), isActive и barcodes.
// Токен должен иметь категорию Content или Promotion.
refreshWbListingCatalog(string $companyId, string $connectionId): int
```

**Inventory mapping-контракт через Facade:**
- Ozon `sourceSku` → `MarketplaceFacade::findListingsByMarketplaceSkus(companyId, marketplace, sourceSkus)`;
- Wildberries `sourceSku` содержит `chrtId` размера и маппится через `MarketplaceFacade::findListingsByMarketplaceVariantIds(companyId, marketplace, marketplaceVariantIds)`;
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

// Идемпотентный lookup дневного импорта. source_key уникален в пределах
// (company_id, marketplace); NULL сохраняет возможность нескольких Ozon raw
// за один день.
findBySourceKey(
    string $companyId,
    string $marketplace,
    string $sourceKey,
): ?AdRawDocument

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

### `AdRawDocument.raw_payload` — поддерживаемые формы

`OzonAdRawDataParser` принимает две формы и возвращает одинаковый список `AdRawEntry`:

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

`WildberriesAdRawDataParser` принимает только версионированный дневной payload:

```json
{
  "schema": "wb-ad-daily-spend-v1",
  "expenses": [{"advertId": "1", "updSum": "100.00", "campName": "Campaign"}],
  "statistics": [{"advertId": "1", "days": [{"apps": [{"nms": [{"nmId": "2", "sum": "90.00"}]}]}]}]
}
```

Финансовый источник — `expenses[].updSum` из `GET /adv/v1/upd`.
`statistics[].days[].apps[].nms[].sum` из `GET /adv/v3/fullstats` используется
только как вес распределения по `nmId`; campaign/day/app totals не суммируются,
чтобы не задвоить аналитику. Расчёт выполняется без `float`; остаток копеек
добавляется SKU с максимальным весом. Если положительных весов нет, вся сумма
сохраняется как `parentSku=__unallocated__` без `AdDocumentLine`.

Для WB неизвестный в каталоге `nmId` всё равно создаёт `AdDocument`, поэтому
полный рекламный расход остаётся в totals. `AdDocumentLine` не создаётся, а
`AdRawDocument` остаётся в `DRAFT` до исправления маппинга. Намеренный
`__unallocated__` считается успешно обработанным и не требует листинга.

### Wildberries daily ad spend orchestration

`app:marketplace-ads:wb-daily-spend` is a locked, cron-driven command. By
default it loads yesterday in `Europe/Moscow`; `--date=Y-m-d` supports an
idempotent completed-day rerun, with optional company/connection UUID filters.

For every active WB SELLER connection:

```text
WildberriesAdClient
  -> GET /adv/v1/upd
  -> GET /adv/v3/fullstats (campaign IDs from upd, chunks <= 50, 20 s spacing)
  -> AdRawDocument(sourceKey=wb-ad-spend:<connectionId>:<date>)
  -> ProcessAdRawDocumentAction
  -> when persisted unmappedCount > 0:
       MarketplaceFacade::refreshWbListingCatalog once
       -> ProcessAdRawDocumentAction once for the same raw document
  -> AdDocument + attributed AdDocumentLine
```

The raw response is flushed before projection. A parser/projection failure
therefore leaves recoverable `DRAFT` bronze data. One connection failure does
not stop sibling connections; the command returns a non-zero exit code if any
connection failed. Live reruns, migration application, and cron activation are
Production Gate actions.

Catalog recovery is driven by the persisted reconciliation result, not by a
generic `DRAFT` status. It never refetches `/upd` or `/fullstats`. A failed
catalog refresh preserves the first projection; a successful refresh performs
exactly one idempotent reprojection and exposes its outcome in the load result.

Each individual Promotion API request has at most three total attempts for
HTTP 429/5xx. `Retry-After` supports integer seconds and HTTP-date; absent or
invalid values use a 2/4-second backoff. A supplied delay above 120 seconds
fails immediately instead of blocking cron. Authentication and other 4xx
responses are not retried.

After projection, `WbAdSpendReconciliationQuery` compares the `/upd`-derived
source total with persisted `AdDocument` and `AdDocumentLine` aggregates. It
also separates intentional `__unallocated__` expense from real `nmId`
documents without a line. A mismatch resets the raw document to `DRAFT` and
fails that connection; all comparisons use `Money` in RUB minor units.

---

## Query — MarketplaceAds

> Read-model агрегаты на DBAL (минуя ORM hydration). Используются напрямую из
> Controllers и не через Facade — это внутренний read-слой модуля.

### `WbAdSpendReconciliationQuery` (`src/MarketplaceAds/Infrastructure/Query/WbAdSpendReconciliationQuery.php`)

```php
// Tenant-scoped persisted reconciliation for one WB raw document.
// Returns Money totals for AdDocument, AdDocumentLine, all documents without
// lines, intentional __unallocated__, and real unmapped nmId, plus unmapped count.
get(string $companyId, string $rawDocumentId): WbAdSpendReconciliation
```

Exact invariants:

```text
/upd source total = AdDocument total
AdDocument total = AdDocumentLine total + documents-without-lines total
documents-without-lines total = __unallocated__ total + unmapped-nmId total
/upd-derived __unallocated__ total = persisted __unallocated__ total
```

An `__unallocated__` document with any `AdDocumentLine` fails reconciliation
by design. The normal projection never creates such a line.

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
- ровно два фильтра: `source` (через `MarketplaceType`) и `snapshotDate`;
- `findEffectiveSnapshotDate(companyId, source, date): ?\DateTimeImmutable` —
  `MAX(snapshot_date)` при `snapshot_date <= :date`, то есть семантика «остатки **на** дату»:
  в день без синхронизации берётся ближайший предыдущий снимок. `null` — снимков на эту дату и раньше нет;
- `getPage(companyId, page, perPage, source, snapshotDate)` — выборка одного дня
  (`snapshot_date = :snapshotDate`); на день по источнику существует ровно один срез
  за счёт уникального ключа `uniq_inventory_stock_snapshot_day_item` и `upsertDaySnapshot()`;
- pagination через Pagerfanta;
- `available_for_sale = quantity - reserved_quantity`;
- склад читается через company-scoped join с `inventory_locations`;
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

**Webhook URL (конфигурация):**
- Публичный URL вебхука задаётся через `.env` → `TELEGRAM_WEBHOOK_URL` (параметр `telegram.webhook_url`, bind `string $telegramWebhookUrl`).
- Используется в `TelegramBotController::webhookSet()` (вызов `setWebhook`) и проверке статуса.
- Прод-дефолт — шлюз `tg-gateway`: `https://tg.vashfindir.ru/telegram/webhook`. Хардкод URL запрещён.
- В проде значение передаётся через `docker-compose.prod.yml` (якорь `x-php-env`), а не через репозиторный `.env`.

**Webhook secret_token (аутентификация):**
- `TELEGRAM_WEBHOOK_SECRET` (параметр `telegram.webhook_secret`, bind `string $telegramWebhookSecret`).
- При `setWebhook` передаётся как `secret_token`; Telegram шлёт его в заголовке `X-Telegram-Bot-Api-Secret-Token`.
- `TelegramWebhookController` сверяет заголовок (`hash_equals`); несовпадение → HTTP 403, апдейт не обрабатывается.
- Пустой секрет = проверка выключена (для совместимости при rollout). В проде задать случайным значением и переустановить вебхук.

**Telegram Bot API base URL (исходящие вызовы):**
- `TELEGRAM_API_BASE_URL` (параметр `telegram.api_base_url`, bind `string $telegramApiBaseUrl`), без завершающего слэша.
- Используется во ВСЕХ исходящих вызовах: `sendMessage`/`editMessageText`/`getFile`/скачивание файла (`TelegramWebhookController`) и `setWebhook`/`getWebhookInfo` (`TelegramBotController`).
- Дефолт `https://api.telegram.org`. В проде app-сервер не имеет прямого доступа к Telegram → значение `https://tg.vashfindir.ru/bot-api` (reverse-proxy на шлюзе `tg-gateway`, `location /bot-api/`, доступ по IP app-сервера).
- Схема: вход — `tg.vashfindir.ru/telegram/webhook` → app; выход — app → `tg.vashfindir.ru/bot-api/` → `api.telegram.org`.

**Политика обработки ошибок вебхука:**
- `TelegramWebhookController` ВСЕГДА отвечает HTTP 200 (иначе Telegram ретраит апдейт).
- Ошибки не глушатся: непойманные исключения и сбои создания ДДС логируются через `LoggerInterface::error` (→ Sentry).
- Доменные ошибки создания операции (`\DomainException`, напр. закрытый период; `CurrencyMismatchException`) показываются пользователю текстом в чат; прочие сбои → сообщение «Не удалось сохранить операцию, попробуйте позже».
- Нулевая сумма (`0.00`) операцией не считается — пользователю возвращается подсказка формата.


## Enum — актуальные значения

> Используй **только** эти значения. Не придумывай новые без обновления файла.

### `src/Company/Security/Module.php`
```php
enum Module: string
{
    case FINANCE = 'finance';          // Cash, Finance, Balance, Report, Loan, Ai + Counterparty/ЦФО/ProjectDirection
    case MARKETPLACE = 'marketplace';  // Marketplace, MarketplaceAds, MarketplaceAnalytics, Inventory, Ingestion, MoySklad
    case DEALS = 'deals';
    case CATALOG = 'catalog';
    case ADMIN = 'admin';              // Company (прочее), Billing, Telegram (пользовательские интеграции)
}
```
Группы `system` нет: `Admin`, `Mcp`, `/admin/*` и debug-роуты в exempt-зоне под
`ROLE_ADMIN`/`ROLE_SUPER_ADMIN`. Модуль, который нельзя выдать шаблоном, в enum не нужен.

### `src/Company/Security/AccessLevel.php`
```php
enum AccessLevel: string
{
    case NONE = 'none';
    case READ = 'read';
    case WRITE = 'write';
}
```
`atLeast()` реализует `write ⊃ read`. `allows($module, NONE)` не грантит никому.

### `src/Shared/Enum/AuditLogAction.php`
```php
enum AuditLogAction: string
{
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case SOFT_DELETE = 'SOFT_DELETE';
    case DELETE = 'DELETE';
    case RESTORE = 'RESTORE';
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

### `src/Marketplace/Enum/DefaultSaleMappingPreviewStatus.php`
```php
enum DefaultSaleMappingPreviewStatus: string
{
    case WILL_CREATE = 'will_create';
    case SKIPPED_EXISTING = 'skipped_existing';
    case MISSING_PL_CATEGORY = 'missing_pl_category';
    case INVALID_TARGET_CATEGORY = 'invalid_target_category';
}
```
`MISSING_PL_CATEGORY` и `INVALID_TARGET_CATEGORY` блокируют применение целиком
(`DefaultSaleMappingPreviewResult::hasBlockingIssues()`).

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

### Shared Value Objects — `Money`

`App\Shared\Domain\ValueObject\Money` — `final readonly`, хранит сумму в минорных единицах
(`int $amountMinor`) и валюту ISO-4217. Вся арифметика — bcmath, без float.

```php
// Создание / конвертация
Money::fromMinor(int $amountMinor, string $currency): self
Money::fromString(string $decimal, string $currency): self   // '1 234,56' -> minor; scale по валюте (Intl)
$money->toDecimalString(): string                            // 12345 (RUB) -> '123.45'

// Арифметика (валюта обязана совпадать → MoneyMismatchException)
$money->add(self): self;  $money->subtract(self): self;  $money->negate(): self
$money->multiply(string $factor, RoundingMode = HALF_UP): self
$money->percentage(string $percent, RoundingMode = HALF_UP): self
$money->abs(): self

// Сравнение / предикаты
$money->compareTo(self): int;  $money->equals(self): bool
$money->isZero(): bool;  $money->isPositive(): bool;  $money->isNegative(): bool
$money->amountMinor(): int;  $money->currency(): string
```

- `App\Shared\Domain\ValueObject\RoundingMode` — enum `HALF_UP` (от нуля) / `HALF_EVEN` (банковское).
- Округление масштаба — по числу знаков валюты (`Intl\Currencies::getFractionDigits`, fallback 2).
- Диапазон: PHP `int` 64-бит ≈ ±9.2·10¹⁶ ₽ (минор). Выход за пределы (в `fromString`,
  `multiply`, `percentage`, `abs` при `PHP_INT_MIN`) бросает `MoneyOverflowException` —
  тихого int-wrap нет.

**Doctrine-маппинг (Embeddable):** `Money` помечен `#[ORM\Embeddable]` и встраивается в Entity
через `#[ORM\Embedded(class: Money::class)]` → две колонки (`*_amount_minor` bigint + `*_currency`).
Сумма мапится кастомным типом `App\Shared\Infrastructure\Doctrine\MoneyAmountType`
(`money_amount_minor`, extends `BigIntType`) — гидрирует bigint в PHP `int`, а не string.
Зарегистрирован в `config/packages/doctrine.yaml > dbal.types`; namespace VO покрыт маппингом
`SharedValueObject`. SQL-агрегация по сумме (`SUM`/`ORDER BY`) работает на отдельной колонке.

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
| `marketplace_ads.platform_client` | API-клиенты рекламных площадок. `OzonAdClient` работает с Ozon Performance API (OAuth2, async-репорты, CSV). `WildberriesAdClient` читает текущие `/adv/v1/upd` и `/adv/v3/fullstats` с точным JSON-number boundary |

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
Login throttling: 5 попыток / 15 мин (`security.yaml`, firewall `main`)

---

## Модульные роли доступа (`src/Company/Security/`)

Владелец компании раздаёт участникам доступ к группам модулей через **шаблоны ролей**
(`company_role`). Per-member override отсутствует по решению владельца: нестандартный
доступ = копия шаблона. Уровни — `none | read | write`, `write ⊃ read`.

| Класс | Роль |
|---|---|
| `Module` (enum) | 5 групп модулей — единственный источник списка |
| `AccessLevel` (enum) | `none/read/write` + `atLeast()` |
| `ModuleAccess` | Константы атрибутов `module.<group>.read|write` + `parse()` |
| `ModuleAccessMap` | Карта FQCN/namespace-префиксов контроллеров → `Module`; побеждает самый длинный префикс |
| `ModuleAccessVoter` | Голосует по `module.*`, делегирует резолверу |
| `ModuleAccessResolver` | Уровни текущего пользователя; мемоизация на запрос по паре (user, company), `ResetInterface` |
| `ModuleAccessSubscriber` | **Read-гейт fail-closed** на `kernel.controller`: неклассифицированный контроллер без `#[PublicAccess]` → 403 |
| `PublicAccess` (attribute) | Снимает модульный гейт с класса или метода |
| `SystemCompanyRoles` | 5 системных шаблонов с фиксированными UUID (те же значения вставляет миграция) |

**Write-гейты.** POST-only экшены — атрибутом `#[IsGranted(ModuleAccess::X_WRITE)]`;
смешанные `GET+POST` — `denyAccessUnlessGranted()` в теле, иначе атрибут гейтил бы и чтение.
Расставлены во всех пяти группах (Stage 3 — finance/deals/catalog/admin, Stage 4 — marketplace).
Инвариант держит `ModuleWriteGateCoverageTest` (integration): обход идёт по скомпилированной
RouteCollection, мутирующий маршрут без гейта своего модуля роняет тест. Правило fail-closed —
**роут без явного `methods:` считается мутирующим**, потому что роутер принимает на него и POST;
read-страница обязана объявлять `methods: ['GET']`. Маршруты, закрытые не модульным гейтом
(firewall админки, owner-проверка внутри Action, личные настройки, `#[PublicAccess]`),
перечислены в карте политик теста поимённо, и устаревшая запись в ней — тоже падение.

**`ROLE_COMPANY_OWNER` не заменяет модульный write-гейт.** Это глобальная роль из `role_hierarchy`,
её ставит `CompanyOwnerAccountCreator` при регистрации: она означает «пользователь зарегистрирован
как владелец компании», а не «владелец активной компании». Владелец компании A, будучи read-only
участником компании B, проходит такой гейт и пишет в компанию B. Пять контроллеров, стоявших только
под ней (Inventory snapshots, MarketplaceAds Ozon load/extract, MarketplaceAnalytics), получили
`MARKETPLACE_WRITE`; глобальная роль оставлена как дополнительный coarse-гейт.
Настоящая per-company проверка — `assertOwner($company)` или Action, сверяющий `$company->getUser()`.

**Owner-only, а не `admin.write`.** Управление шаблонами (`CompanyRoleController`) и
назначение шаблона участнику остаются под `assertOwner`. `module.admin.write` слабее:
участник с ним отредактировал бы свой шаблон и выдал себе `finance:write`. То же для
`ReportApiKeyController::generate/revoke`.

**Exempt-зоны** (свои гейты, модульным не покрыты): `App\Admin\`, `App\Mcp\`,
`App\Analytics\`, `App\Notification\`, `App\Telegram\Controller\Admin\`,
`App\Marketplace\Controller\Admin\`, `App\MarketplaceAds\Controller\Api\Admin\`,
плюс точечно `ProfileController`, `UiModeController`, `CompanyController`,
`HomeRedirectController`, `CounterpartySearchController`.

**Инвариант покрытия** проверяется тестом `ControllerAccessCoverageTest`: каждый routed-контроллер
в `src/` либо в карте, либо помечен `#[PublicAccess]`. Новый контроллер в новом неймспейсе
без записи в карте получит 403 — это by design, тест ловит это на CI.

**Лендинг.** `/` → `HomeRedirectController` (`app_home_index`) редиректит на первый доступный
модуль. Финансовый дашборд — `/finance` (`app_finance_index`), React-пилот — `/dashboard`
(`app_dashboard_index`).

**Меню.** `partials/_sidebar.html.twig` и вложенные `_sidebar_marketplace` / `_sidebar_report`
скрывают разделы по `is_granted('module.<group>.read')`. Однородные блоки закрыты целиком,
смешанные — на двух уровнях: блок показывается при доступе хотя бы к одному из своих модулей,
а пункты внутри гейтятся каждый под свой («Справочники» = finance + catalog, «Интеграции» =
admin + finance + marketplace). «Главная» не гейтится — за ней exempt-редирект.
Админка использует собственный `admin/partials/_sidebar.html.twig` и модульными гейтами
не затронута. Инвариант: `SidebarModuleVisibilityTest`.

**Actions** (`src/Company/Application/`): `SaveCompanyRoleAction` (создание/изменение +
проверка уникальности имени), `DeleteCompanyRoleAction`, `AssignCompanyMemberAccessRoleAction`.
`flush()` живёт только в них — `CompanyRoleRepository`/`CompanyMemberRepository` его не вызывают.

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
- `POST /inventory/snapshots/request` — ручной запуск raw-загрузки Ozon;
- `POST /inventory/snapshots/request/wildberries` — ручной запуск raw-загрузки Wildberries;
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

## Inventory — Wildberries FBW stock normalization

Orchestration: ночной cron `app:inventory:wb-daily-sync` (04:15 MSK) и ручной запуск
через `POST /inventory/snapshots/request/wildberries`. Обе точки входят в один Action.

```text
RequestWbInventorySnapshotAction
↓ SyncWbInventorySnapshotMessage (async_sync)
SyncWbInventorySnapshotHandler
↓ обязательный refreshWbListingCatalog()
WB Product Cards list + trash → MarketplaceListing.marketplaceVariantId/isActive
↓ POST /api/analytics/v1/stocks-report/wb-warehouses, limit/offset
InventoryRawSnapshot
↓ completed session
NormalizeInventorySnapshotMessage (async_pipeline)
↓
NormalizeInventorySnapshotAction → StockSnapshot
```

429, transport и 5xx до первой raw-страницы завершают сессию как `failed`,
после сохранённых страниц — как `partial`; такие сессии не нормализуются.
`failed`/`partial` терминальны, поэтому следующий ночной прогон заводит новую сессию.
Сессия, застрявшая в `pending`/`in_progress` (например, при смерти воркера посреди
выгрузки), наоборот блокирует все последующие: `findLatestActiveByCompanyAndSource()`
не ограничен возрастом. Ручной сброс такой сессии — единственный выход; то же
поведение у Ozon.
Подключение должно использовать токен с доступом к Content API и Analytics API.
Активные Product Cards сохраняются с `MarketplaceListing.isActive = true`, карточки
из `/content/v2/get/cards/trash` — с `isActive = false`. Оба набора участвуют в
точном маппинге остатков по `chrtId`; отсутствие карточки в обоих ответах само по
себе не деактивирует существующий листинг.

Нормализатор WB принимает raw-страницы ответа
`POST /api/analytics/v1/stocks-report/wb-warehouses` и агрегирует строки по
`chrtId + warehouseId`, не смешивая размеры одной карточки. Существующая схема
`StockSnapshot` используется без дополнительной миграции.

Семантика WB:
- `chrtId` → `StockSnapshot.sourceSku` и точный маппинг листинга через `MarketplaceFacade::findListingsByMarketplaceVariantIds()`;
- `nmId` → `StockSnapshot.sourceOfferId` для трассировки родительской карточки;
- `warehouseId` → `Location.externalId`, `warehouseName` → `Location.name`;
- WB является источником правды для своих складов: при нормализации обновляются `Location.code`, `name`, `metadata` и `isActive`;
- `quantity` → `StockStatus::Available`;
- `inWayToClient` → `StockStatus::InTransitToCustomer`;
- `inWayFromClient` → `StockStatus::InTransitFromCustomer`;
- для всех трёх строк `fulfillmentType = fbw`, `reservedQuantity = 0`;
- все страницы одной сессии используют единый `snapshotAt = session.startedAt`.

`StockQtyByListingOnDateQuery` выбирает последнюю целую snapshot-сессию отдельно
для каждого marketplace source, содержащую хотя бы один замапленный листинг,
и суммирует только `StockStatus::Available`.
Товары в пути в количественный остаток `InventoryFacade` не входят.

## Messenger routing — Inventory

- `App\Inventory\Message\SyncOzonInventorySnapshotMessage` → `async_sync`;
- `App\Inventory\Message\SyncWbInventorySnapshotMessage` → `async_sync`;
- `App\Inventory\Message\NormalizeInventorySnapshotMessage` → `async_pipeline`.

Объяснение:
- `SyncOzonInventorySnapshotMessage` выполняет внешний HTTP-запрос к Ozon;
- `SyncWbInventorySnapshotMessage` обновляет WB Product Cards и загружает raw-остатки;
- `NormalizeInventorySnapshotMessage` выполняет локальную DB-heavy обработку raw JSON.

## Messenger routing — Marketplace (WB financial report day)

- `App\Marketplace\Message\SyncWbFinancialReportDayMessage` → `async_sync`.

Назначение:
- загрузка WB financial report за один `businessDate` (date-based sync для initial / refresh_14d / missing сценариев).
- business date интерпретируется в timezone `Europe/Moscow` (дата WB-бизнеса, а не UTC).

Краткий pipeline:
- `WB API` → `MarketplaceRawDocument`;
- `MarketplaceRawDocument` → `ProcessDayReportMessage`;
- `ProcessDayReportMessage` → расчёт sales / returns / costs;
- после успешной обработки статуса дня фиксируется `FinancialReportSyncStatus::SUCCESS`.

Payload (только scalar):
- `companyId` — `string` UUID;
- `connectionId` — `string` UUID;
- `businessDate` — `string` в формате `YYYY-MM-DD`;
- `mode` — `string`, значение `FinancialReportSyncMode`;
- `forceRefresh` — `bool`.


Ограничения payload:
- message не содержит `apiKey`, `token`, `connection` entity или любые другие ORM-объекты.
- `empty day` (`FinancialReportSyncStatus::EMPTY`) не считается `missing day` и не должен попадать в missing-планирование.
- rate limit WB sync: не более **1 request/min** на пару `connection + endpoint`.

### State entities

- `MarketplaceFinancialReportSyncStatus` — дневной статус синхронизации по ключу `companyId + connectionId + businessDate + reportType`.
- `MarketplaceFinancialReportSyncError` — append-only история ошибок по `syncStatusId`.
- `MarketplaceRawDocument` — raw JSON WB financial report; связь с дневным статусом через `rawDocumentId` в `MarketplaceFinancialReportSyncStatus`.

### Status lifecycle

- `LOADING` — началась загрузка WB API.
- `EMPTY` — WB вернул пустой день; raw document не создаётся; missing-планирование этот день не включает.
- `RAW_LOADED` — raw document сохранён.
- `PROCESSING` — отправлен `ProcessDayReportMessage`.
- `SUCCESS` — обработка raw завершена успешно.
- `FAILED` — retryable-ошибка; день может попасть в missing/retry-due планирование.
- `FAILED_FINAL` — unrecoverable processing/API ошибка.
- `AUTH_FAILED` — ошибка авторизации WB API.
- `CONFLICT` — terminal-результат: in-flight raw блокирует запуск (`WbRawDocumentRefreshConflictException`) либо legacy reconcile. Частичная переобработка с сохранёнными linked rows этот статус не выставляет.

### Modes

- `daily` — вчерашний business day.
- `initial` — диапазон от start date до yesterday.
- `refresh14` — последние 14 дней, `forceRefresh=true`.
- `missing` — отсутствующие/retry-due дни; не трогает `EMPTY`/`SUCCESS`/in-flight.
- `manual` — ручной запуск.

### Refresh / safe replace contract

- `forceRefresh=true` передаётся в `ProcessDayReportMessage`.
- Перед reprocess WB `sales_report` удаляются только generated rows без связанного `document`.
- Связанные с `document` rows текущего raw-документа сохраняются, а его незакреплённые generated rows пересоздаются.
- Частичная переобработка — успех: шаг `succeeded`, документ `COMPLETED`, день `SUCCESS`. `ProcessMarketplaceRawDocumentAction` возвращает `ProcessRawDocumentResult{processedRows, preservedLinkedRows}`; при `preservedLinkedRows > 0` пишется warning, а сводка переобработки отдаёт `partial_steps` и `linked_rows_preserved` (flash в UI, warning в CLI).
- Ошибкой частичная переобработка не считается: строки закрытого документа неизменяемы by design, повторный прогон дал бы тот же результат, поэтому гейт остаётся достижимо зелёным.
- Linked row чужого raw-документа с тем же external ID сохраняется и не влияет на текущий raw-документ.
- Автоматические режимы не планируют `CONFLICT` повторно; такой день (in-flight raw) доступен только через ручной force-refresh.

### Planner rules

- Дни в `LOADING`/`PROCESSING` повторно не планируются.
- `daily` без force пропускает дни со статусами `SUCCESS`/`EMPTY`.
- `refresh14` планирует и `SUCCESS`, и `EMPTY` c `forceRefresh=true`.
- `missing` сначала выбирает retry-due дни в `FAILED`, затем отсутствующие дни, и ограничивается `maxDays`.



### Command: `app:marketplace:wb-financial-reports:reconcile-legacy`

Назначение:
- one-time reconciliation legacy WB-данных в дневные `MarketplaceFinancialReportSyncStatus` без WB API-вызовов;
- заполняет статус только при наличии доказуемых данных (`raw` или generated rows), неизвестные дни оставляет `missing`;
- отсутствие строк **не** конвертируется в `EMPTY`.

Правила:
- не удаляет и не пересчитывает существующие записи;
- не перезаписывает существующие sync statuses;
- для `raw`-дней переносит `apiEndpoint` из raw, чтобы не искажать исторический источник;
- после reconciliation следующий шаг: `app:marketplace:wb-financial-reports:sync --mode=missing --max-days=10`.
### Command: `app:marketplace:wb-financial-reports:sync`

Назначение:
- единая точка ручного/cron-планирования date-based WB financial report sync;
- команда только планирует задачи в Messenger и не делает прямых WB HTTP-вызовов.

Режимы:
- `all`, `initial`, `daily`, `refresh14`, `missing`.

Правила range/date опций:
- `--date` и `--from/--to` разрешены только для explicit mode (`initial`, `daily`, `refresh14`);
- `--mode=all` + `--date`/`--from`/`--to` запрещено;
- `--mode=missing` + `--date`/`--from`/`--to` запрещено, для missing используется `--max-days`.

Message/worker:
- Message: `App\Marketplace\Message\SyncWbFinancialReportDayMessage`;
- Worker: `App\Marketplace\MessageHandler\SyncWbFinancialReportDayHandler`.

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
| `app:inventory:wb-daily-sync` | `04:15 daily` | Диспатч загрузки Wildberries Inventory snapshot по активным WB SELLER подключениям |
| `app:marketplace:wb-financial-reports:sync --mode=daily` | `03:10 daily` | Ежедневное планирование WB financial sync за рабочий день (новая date-based команда) |
| `app:marketplace:wb-financial-reports:orchestrate --refresh-days-back=14` | `20 * * * *` | Hourly safe planner: current-month daily/retry/missing/empty recovery, then rolling refresh of the last 14 days; max one task per connection per run |
| `app:ingestion:ozon-performance:daily-load --window=month-to-date` | `07:25 daily` | Планирование Ozon Performance ingestion с начала месяца до вчерашнего дня; HTTP-загрузка выполняется `ingest_fetch` worker'ом |

The production PHP CLI image used by workers and the scheduler disables only
the dynamic-load entry for `opcache.so`, which is not needed in this runtime
and cannot resolve `zend_jit_status` on the current Alpine/musl build. The
image build fails if the entry is not disabled or the startup warning remains.
The production PHP-FPM image and its opcache configuration are unchanged.

**Правила для новых cron-команд:**
- Класс в `src/{Module}/Command/`, `final class`, `#[AsCommand]`
- `LockableTrait` обязателен если команда может идти дольше интервала запуска
- Нет `Request`/`Session`/`Security` — CLI-контекст, companyId из аргумента/итерации по БД
- Per-item try/catch: сбой одной компании / одной записи не прерывает весь запуск
- Exit code: `Command::SUCCESS` / `Command::FAILURE`
- Legacy команда `app:marketplace:wb-daily-sync` сохранена для backward compatibility, но её cron отключён после TASK-028-FIX (активный daily cron только `app:marketplace:wb-financial-reports:sync --mode=daily`).

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
| 1.86 | 2026-09-01 | Ingestion: уборщик зависших `SyncJob` — задача в `OPEN`/`RUNNING` без движения больше не блокирует ресурс навсегда |
| 1.85 | 2026-09-01 | Marketplace: ручной запуск загрузки каталога Ozon из UI, журнал прогонов `MarketplaceJobLog` и взаимное исключение прогонов по подключению |
| 1.84 | 2026-09-01 | Marketplace: загрузка каталога товаров Ozon в листинги — товары без продаж, наименование, дата создания на маркетплейсе; сопоставление по всему множеству `sources[].sku` |
| 1.83 | 2026-09-01 | Marketplace/Ingestion: в `MarketplaceListing` добавлены `marketplaceCreatedAt` и `lastSeenAt`; `RawStorageFacade::storeAndGetIds()` отдаёт скалярные id вместо сущностей через границу модуля |
| 1.82 | 2026-08-23 | MarketplaceAnalytics: добавлен facade-контракт пакетного пересчёта дневных снапшотов листингов для tenant-safe каскада после изменения себестоимости |
| 1.81 | 2026-08-21 | Finance: ручное удаление операций ОПиУ переведено на company-scoped soft delete с отдельной вкладкой удалённых, восстановлением и пересчётом связанных ДДС/ОПиУ агрегатов |
| 1.80 | 2026-08-20 | Finance: ДДС принимает tenant-safe мультифильтры Проекты/ЦФО при сохранении legacy UI, JSON и CSV contracts |
| 1.79 | 2026-08-11 | Company: меню скрывает разделы недоступных модулей — модульные роли доступа закрыты по всем этапам |
| 1.78 | 2026-08-11 | Company: write-гейты marketplace и статический инвариант покрытия мутирующих экшенов |
| 1.77 | 2026-08-11 | Company: модульные роли доступа — Module/AccessLevel enum, fail-closed read-гейт, write-гейты finance/deals/catalog/admin, owner-only управление шаблонами, новый лендинг `/` |
| 1.76 | 2026-08-10 | Cash/MCP: обычные root-категории ДДС, защищённые системные ветки и tri-state `parentId` для переноса в root |
| 1.75 | 2026-08-09 | Cash: tenant-safe UI агрегата перевода, selector валюты ДДС и read-only verifier целостности |
| 1.74 | 2026-08-09 | Cash: атомарный lifecycle пары перевода, защищённые generic mutations, currency-safe отчёт/дашборд/list/export |
| 1.73 | 2026-08-09 | Cash: атомарный агрегат перевода, две технические ноги, точный effective FX rate и company-scoped idempotency |
| 1.72 | 2026-08-09 | Cash: единый fiat-контракт RUB/USD/EUR/KZT, неизменяемая валюта счёта, company-scoped transaction writers, currency-safe imports и RUB-only PaymentPlan matching |
| 1.71 | 2026-08-04 | Company: `CompanyFacade::listAccessibleCompaniesForUser()` и `userHasAccess()` — доступ и список компаний пользователя (owned + активный CompanyMember), для межкомпанийных операций (Finance: импорт дерева ОПиУ между своими компаниями) |
| 1.70 | 2026-08-02 | Doc sync с кодом: legacy-зона (`src/Entity|Service|Repository|Controller`) отмечена пустой — сущности уже переехали в `Finance/Entity/` и `Company/Entity/`; `Catalog` перестал считаться полностью мигрированным (`Product` всё ещё на `Company $company`); в карту модулей добавлены `Mcp` и `Report` |
| 1.69 | 2026-07-28 | MCP/Company: read-only `company_find_by_name` глобально разрешает точное название компании без учёта регистра в единственный ID; отсутствие и дубли возвращаются как ожидаемая ошибка |
| 1.68 | 2026-07-24 | Infrastructure: production PHP CLI disables the broken Alpine/musl opcache dynamic load; production PHP-FPM remains unchanged |
| 1.67 | 2026-07-24 | MarketplaceAds: one-shot WB catalog recovery from persisted unmapped nmId, bounded 429/5xx retry, and one aggregated normal-channel alert for unresolved review |
| 1.66 | 2026-07-24 | MarketplaceAds: persisted `/upd` → `AdDocument` → `AdDocumentLine` reconciliation with intentional-unallocated and real-unmapped totals |
| 1.65 | 2026-07-24 | MarketplaceAds: locked WB daily ad-spend command, idempotent company/connection/day raw upsert, 06:15 MSK cron, per-connection isolation and operational totals |
| 1.64 | 2026-07-24 | MarketplaceAds: WB `/upd` actual expense распределяется по `fullstats` nmId-весам без float; raw `sourceKey` обеспечивает идемпотентность company/connection/day и сохраняет unallocated/unmapped суммы в totals |
| 1.62 | 2026-07-18 | Finance: Stage 7.7.3 переключает новые `pl_daily_totals` записи на Project×ЦФО aggregation key с partial expression unique indexes и безопасным merge при удалении P&L категории |
| 1.63 | 2026-07-18 | Finance: Stage 7.7.4 подключает P&L read-side к Project×ЦФО через optional `responsibilityCenterId` фильтр в preview/UI/JSON без перерасчёта истории |
| 1.61 | 2026-07-18 | Cash/Company: Stage 7.6.4 подключает file/1C/bank import writers к system Project×ЦФО pair для новых транзакций без изменения overwrite/preview/batch semantics |
| 1.60 | 2026-07-17 | Cash/Company: Stage 7.6.2 подключает core Cash create/update и `CashFacade` к валидируемому project × ЦФО contract; новые empty-pair транзакции получают системную пару |
| 1.59 | 2026-07-17 | Cash/Company: Stage 7.9.3 добавляет общий атомарный planner project × ЦФО, active-pair snapshot, preview labels/breakdown и per-field audit provenance |
| 1.58 | 2026-07-17 | Cash/Company: добавлены nullable scalar mapping ЦФО транзакции, read-контракт системной пары и невключённый в writers валидатор пар Stage 7.6.1 |
| 1.57 | 2026-07-17 | Company: добавлен защищённый Twig-интерфейс `Справочники → ЦФО` с company isolation, CSRF и optimistic locking |
| 1.56 | 2026-07-17 | Company: добавлены company-scoped Actions управления ЦФО и optimistic-lock настройка разрешённых проектов |
| 1.55 | 2026-07-16 | Company: добавлены плоский справочник ЦФО, стабильные системные коды проекта/ЦФО, разрешённые пары и атомарный bootstrap новой компании |
| 1.54 | 2026-07-13 | Marketplace/Inventory: WB Product Cards refresh дополнен карточками из корзины, которые сохраняются неактивными и участвуют в точном маппинге остатков по `chrtId` |
| 1.53 | 2026-07-13 | Inventory: добавлен ручной WB orchestration с обязательным Product Cards refresh, async raw-загрузкой, безопасной offset-пагинацией и отдельным POST endpoint |
| 1.53 | 2026-07-19 | Mcp: локальный stdio MCP-сервер `app:mcp:serve` (без внешних доступов); `CashFacade` расширен чтением транзакций и CRUD статей ДДС и автоправил, `CompanyFacade::findCounterpartyByIdAndCompany()` |
| 1.52 | 2026-07-13 | Inventory: добавлена нормализация WB FBW raw-остатков по `chrtId + warehouseId`, точный variant-маппинг, отдельные статусы движения и выбор последней полной сессии по каждому источнику |
| 1.51 | 2026-07-13 | Marketplace: добавлена атомарная синхронизация WB Product Cards → `MarketplaceListing.marketplaceVariantId` и barcodes |
| 1.50 | 2026-07-13 | Marketplace: в `MarketplaceListing` добавлен generic `marketplaceVariantId` (`chrtId` для WB) и точный batch-контракт facade |
| 1.49 | 2026-06-12 | Company: добавлен публичный контракт `CompanyFacade::createOwnerAccount()` для создания owner-аккаунта через фасад из Admin |
| 1.48 | 2026-05-22 | Marketplace: зафиксирован контракт WB financial sync (entities статуса/ошибок, enum mode/status, message `SyncWbFinancialReportDayMessage` на `async_sync`, команда `app:marketplace:wb-financial-reports:sync`, pipeline, TZ `Europe/Moscow`, правило empty day и rate limit 1 request/min) |
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
