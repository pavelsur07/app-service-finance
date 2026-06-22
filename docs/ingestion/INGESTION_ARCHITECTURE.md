# Модуль Ingestion — архитектурный справочник

## Назначение

`App\Ingestion` — единый пайплайн загрузки финансовых данных из внешних источников
(Ozon, WB, банки, МойСклад) в канонический формат.

Заменяет разрозненные legacy-пайплайны по принципу Strangler Fig:
один источник за раз, параллельно с legacy, без его отключения.

**Граница модуля:** Ingestion знает про источники и канон.
Он не знает про `PLDailyTotal`, `Document`, `CashTransaction`.
Только публикует события и отдаёт данные через Facade.

---

## Пайплайн загрузки

```
Внешний источник (Ozon API / WB / Банк / МойСклад)
        ↓
  [FETCH]
  RunSyncChunkHandler
  → OzonSellerReportConnector.pull()
  → RawStorageFacade.store()
        ↓
  Raw (S3 + IngestRawRecord, status=PENDING)
        ↓
  [NORMALIZE]
  NormalizeRawRecordHandler
  → OzonAccrualByDayMapper.map()
  → UpsertFinancialTransactionAction
  → IngestRawRecord status=DONE
        ↓
  Канон (FinancialTransaction)
  + NormalizationCompletedEvent опубликован
        ↓
  [ENRICH]
  EnrichCogsHandler
  → MarketplaceFacade.getCostPriceForListing()
  → EnrichmentTransaction (kind=COGS)
        ↓
  PLDirtyPeriod помечен (PENDING)
        ↓
  [REBUILD] — App\Finance
  RebuildPnlPeriodHandler
  → IngestionFacade.getTransactions() + getEnrichments()
  → PLDailyTotal / PLMonthlySnapshot перезаписаны
```

---

## Транспорты Messenger

| Transport | DSN (underlying) | Что обрабатывает |
|---|---|---|
| `ingest_fetch` | `async_sync` | `RunSyncChunkMessage` — HTTP к источникам |
| `ingest_normalize` | `async_pipeline` | `NormalizeRawRecordMessage`, `EnrichCogsMessage`, `MarkPnlPeriodDirtyMessage` |
| `pnl_rebuild` | `async_pipeline` | `RebuildPnlPeriodMessage` |

Prod-воркеры: `site-messenger-worker-sync`, `site-messenger-worker-pipeline`.
Отдельных воркеров для Ingestion не нужно — DSN совпадают с существующими.

---

## Сущности и ответственность

### Оркестрация

#### `IngestCursor`
Таблица: `ingest_cursors`

Где остановились по каждому ресурсу.

| Поле | Смысл |
|---|---|
| `companyId + connectionRef + resourceType + shopRef` | Natural key (unique) |
| `cursorValue` | Opaque — формат определяет коннектор |
| `lastSyncJobId` | Какой job обновил курсор |
| `lastFetchedAt` | Время последнего успешного fetch |

**Правила:**
- Курсор обновляется ТОЛЬКО после успешной записи raw. Никогда до.
- Отсутствие курсора = backfill не запускался. `run-incremental` пропускает такие подключения.
- Сброс курсора = следующий инкремент начнётся с нуля.

#### `SyncJob`
Таблица: `ingest_sync_jobs`

Единица выполнения загрузки.

| Поле | Смысл |
|---|---|
| `kind` | BACKFILL / INCREMENTAL / MANUAL |
| `status` | OPEN → RUNNING → DONE / FAILED / CANCELLED |
| `parentJobId` | Ссылка строкой на родительский job (nullable) |
| `progressTotal / progressDone` | Прогресс чанков |
| `lastError` | Причина последней ошибки |
| `cursorSnapshot` | Курсор на момент старта (для отката) |

**Правила:**
- Родительский job (backfill) → дочерние чанки по 7 дней.
- Родитель финализируется автоматически когда все дочерние терминальны.
- `MaybeFinalizeParentAction` вызывается при каждом завершении дочернего.

---

### Raw-слой (бронза)

#### `IngestRawRecord`
Таблица: `ingest_raw_records`

Метаданные одного чанка raw данных.

| Поле | Смысл |
|---|---|
| `source` | `IngestSource` enum (OZON / WILDBERRIES / ...) |
| `resourceType` | Строка — тип ресурса внутри источника |
| `externalId` | Идентификатор чанка в источнике |
| `storagePath` | Путь к файлу в S3 / var/storage |
| `hash` | SHA-256 uncompressed NDJSON (для дедупликации) |
| `normalizationStatus` | PENDING → DONE / FAILED |
| `shopRef` | Магазин (пустая строка если не применимо) |
| `syncJobId` | Ссылка строкой на SyncJob |

**Правила:**
- Payload хранится в S3, не в БД. В таблице только метаданные.
- Natural key дедупликации: `(companyId, source, externalId, hash)`.
- При повторной загрузке того же hash — файл не пишется, `lastSeenAt` обновляется.
- Формат payload: NDJSON + gzip. Один файл на чанк.
- Путь: `{companyId}/{source}/{shopRef}/{resourceType}/{yyyy}/{mm}/{dd}/{syncJobId}.ndjson.gz`

---

### Канон (серебро)

#### `FinancialTransaction`
Таблица: `ingest_financial_transactions`

Нормализованный факт от источника. Источник истины для P&L.

| Поле | Смысл |
|---|---|
| `source` | Откуда пришла операция |
| `externalId` | Идентификатор операции в источнике |
| `externalUpdatedAt` | Версия данных из источника |
| `operationGroupId` | Связывает декомпозированные транзакции одной операции |
| `type` | `TransactionType` (SALE / COMMISSION / LOGISTICS / ...) |
| `direction` | IN (приход) / OUT (расход) |
| `amountMinor` | Сумма в копейках, всегда >= 0 |
| `currency` | ISO 4217 |
| `occurredAt` | Дата операции в источнике (UTC) — используется для P&L периода |
| `listingId` | Ссылка на `MarketplaceListing.id` (nullable) |
| `counterpartyId` | Ссылка на `SystemCounterparty.id` (nullable) |
| `rawRecordId` | Ссылка на `IngestRawRecord.id` |
| `sourceData` | JSONB — исходная строка источника для аудита |
| `enrichmentStatus` | NOT_APPLICABLE / PENDING_LISTING / PENDING_COGS / DONE / SKIPPED |

**Правила:**
- Natural key для upsert: `(companyId, source, externalId, type)`.
- При более свежем `externalUpdatedAt` — перезаписать через `replaceFromNewerVersion`.
- При старом `externalUpdatedAt` — пропустить без ошибки.
- **Никогда не удалять.** Только upsert.
- `occurredAt` — дата из источника (не дата загрузки). Используется для определения P&L периода.
- `sourceData` содержит полную исходную строку — для аудита и отладки.

#### `EnrichmentTransaction`
Таблица: `ingest_enrichment_transactions`

Расчётные данные рядом с каноном (COGS и будущие виды обогащения).

| Поле | Смысл |
|---|---|
| `transactionId` | Ссылка строкой на `FinancialTransaction.id` |
| `kind` | `EnrichmentKind` (COGS / ...) |
| `amountMinor` | Сумма в копейках |
| `sourceRef` | Откуда взяли данные (ID записи источника) |

**Правила:**
- **Отдельная таблица** от `FinancialTransaction`. Факты источника и расчёты не смешиваются.
- Только append + `updateAmount`. Никогда DELETE.
- При повторном `rebuildPeriod` — COGS пересчитывается через `updateAmount`, не удаляется.
- `listingId=null` → `enrichmentStatus=PENDING_LISTING`, COGS не создаётся.
- Cron каждые 30 минут подбирает PENDING_COGS и повторяет попытку.

---

### Credentials

#### `IngestionCredential`
Таблица: `ingestion_credentials`

Хранилище API-ключей источников.

**Правила:**
- Через `SecretCodec` интерфейс. Сейчас `PlaintextSecretCodec` (keyVersion=0).
- Шов для будущего `SodiumSecretCodec` готов — переключить без правки бизнес-кода.
- Fallback: читает из legacy `MarketplaceConnection` если нет собственной записи.
- Credentials никогда не логируются и не попадают в ответы API.

---

### Системные справочники

#### `SystemCounterparty`
Таблица: `system_counterparties`

Глобальный справочник маркетплейсов как контрагентов.

| source | name | inn |
|---|---|---|
| `ozon` | «Ozon» | 7704217370 |
| `wildberries` | «Wildberries» | 7721546864 |

**Правила:**
- Без `companyId` — один для всех тенантов.
- Заполняется seed-миграцией. Не редактируется через UI.
- `FinancialTransaction.counterpartyId` ссылается строкой.

---

### P&L проекция

#### `PLDirtyPeriod`
Таблица: `pnl_dirty_periods`

Очередь периодов на пересчёт P&L. Живёт в `App\Ingestion`.

| Поле | Смысл |
|---|---|
| `periodYear + periodMonth + shopRef` | Какой период грязный |
| `status` | PENDING → REBUILDING → DONE / FAILED / BLOCKED_BY_CLOSE |
| `reason` | INGEST / MANUAL / REMAP / MONTH_CHANGE |
| `markedAt` | Когда помечен |
| `rebuiltAt` | Когда последний раз пересчитан |

**Правила:**
- Помечается по `occurredAt` транзакции, не по дате загрузки.
- При смене периода операции (Ozon передатировал) — помечаются оба: старый и новый.
- Закрытый период (`MarketplaceMonthClose`, `financeLockBefore`) → `BLOCKED_BY_CLOSE` + уведомление. Автопересчёт запрещён.
- `rebuildPeriod` идемпотентен: 1 запуск = 5 запусков по результату.
- Redis Lock защищает от конкурентных пересчётов одного периода.

---

## Контракты между модулями

### На выход из Ingestion (события)
```
NormalizationCompletedEvent(
    companyId: string,
    rawRecordId: string,
    affectedPeriods: list<AffectedPeriod>  // occurredAt старый и новый
)
```
Публикуется после нормализации. Подписчики: Finance (PLDirtyPeriod), Ingestion (EnrichCogs).

### На вход в Ingestion (Facade)
```php
IngestionFacade::getTransactions(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $shopRef): iterable<FinancialTransaction>
IngestionFacade::getEnrichments(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to): iterable<EnrichmentTransaction>
IngestionFacade::countOpenIssues(string $companyId): int
IngestionFacade::storeEnrichment(...): void  // для Finance при создании COGS
```

### Ссылки между модулями — только строки
```
FinancialTransaction.listingId      → MarketplaceListing.id (string)
FinancialTransaction.counterpartyId → SystemCounterparty.id (string)
EnrichmentTransaction.transactionId → FinancialTransaction.id (string)
PLDirtyPeriod                       → через PnlFacade в Finance
```

---

## Коннекторы

### SourceConnectorInterface
```php
source(): IngestSource
capabilities(): list<Capability>
discoverShops(string $companyId, string $connectionRef): list<ShopDescriptor>
pull(PullRequest $request): PullResult
push(PushRequest $request): PushResult
```

Регистрация: тег `app.ingestion.connector`.

Текущие реализации:
- `OzonSellerReportConnector` — Ozon accrual API (`ozon_finance_accrual_by_day`; legacy cursor rows используются только для seed).

### SourceMapperInterface
```php
source(): IngestSource
resourceTypes(): list<string>
map(IngestRawRecord $rawRecord, iterable $rows): list<MappedTransaction>
controlSum(iterable $rows): list<MappedControlSum>
```

Регистрация: тег `app.ingestion.mapper`.

**Маппер — чистая функция.** Без БД, без HTTP, без состояния.

Текущие реализации:
- `OzonAccrualByDayMapper` — ресурс `ozon_finance_accrual_by_day`.
- `OzonAccrualShadowMapper` — shadow-ресурсы `ozon_finance_accrual_postings`, `ozon_finance_accrual_types` без записи в канон.

### OzonCostCategory — единственный источник правды для Ozon

`App\Marketplace\Domain\OzonCostCategory` — справочник всех категорий Ozon.

**Правило:** добавить новый тип операции Ozon = добавить ТОЛЬКО в `OzonCostCategory`.
Маппер использует `OzonCostCategory::findByServiceName()` и `OzonCostCategory::findByOperationType()`.

### ListingResolverInterface
```php
supports(IngestSource $source): bool
resolve(string $companyId, array $sourceData): ?string  // MarketplaceListing.id
```

Регистрация: тег `app.ingestion.listing_resolver`.

Текущие реализации:
- `OzonListingResolver` — по `supplierSku` (offer_id).
- `WbListingResolver` — заглушка (через баркод, не реализовано).

---

## CLI команды

| Команда | Назначение | Cron |
|---|---|---|
| `app:ingestion:start-backfill` | Ручной запуск первичной загрузки для одной компании | нет |
| `app:ingestion:run-incremental` | Регулярный инкремент по всем активным подключениям | `0 3 * * *` |
| `app:ingestion:normalize-pending` | Страховка: повторная нормализация зависших PENDING | `*/10 * * * *` |
| `app:ingestion:enrich-pending-cogs` | Страховка: повторное обогащение COGS | `*/30 * * * *` |

---

## Правила модуля (обязательные)

### Изоляция тенантов

1. Каждая Entity реализует `TenantOwnedInterface` → покрывается `CompanyFilter` автоматически.
2. Каждый Repository-метод принимает `string $companyId` — даже если фильтр включён. Двойная защита.
3. Системные методы (cron по всем тенантам) явно помечаются в комментарии. Таких методов минимум.
4. Message реализует `CompanyAwareMessage` → middleware включает фильтр в Messenger.
5. Tenant-leak тест обязателен на каждую read-операцию перед merge.

### Границы модуля

6. Ingestion не знает про `PLDailyTotal`, `PLMonthlySnapshot`, `Document`, `CashTransaction`.
7. Ingestion не читает из других модулей напрямую — только через их Facade.
8. Другие модули не пишут в таблицы Ingestion напрямую — только через `IngestionFacade`.
9. Ссылки между модулями — только `string $entityId`. Никаких `#[ManyToOne]` через границы.

### Entity

10. UUID v7 в конструкторе.
11. `string $companyId` (scalar), не `#[ManyToOne] Company`.
12. `DateTimeImmutable` везде.
13. Инварианты в конструкторе через `Assert`.
14. `FinancialTransaction` — только upsert, никогда DELETE.
15. `EnrichmentTransaction` — только append + updateAmount, никогда DELETE.
16. Курсор `IngestCursor` обновляется только после успешной записи raw.

### Action / Repository

17. `flush()` только в Action, никогда в Repository.
18. Repository не принимает Entity из других модулей.
19. Action не делает HTTP-запросов — только через Connector.

### Коннекторы и маппинг

20. Маппер — чистая функция. Без БД, без HTTP, без глобального состояния.
21. `OzonCostCategory` — единственный источник правды для маппинга Ozon. Не хардкодить в маппере.
22. Добавить новый источник = новый класс Connector + Mapper + тег. Существующий код не трогать.
23. Fallback для неизвестного `operation_type` → `TransactionType::OTHER` + `NormalizationIssue(UNKNOWN_FIELD)`. Никогда не падать при неизвестном типе.

### Надёжность

24. Каждый Handler использует `IdempotentHandlerTrait` — повторная обработка безопасна.
25. Cron `normalize-pending` — страховка для потерянных сообщений. Не убирать.
26. `ConnectorAuthException` → `UnrecoverableMessageHandlingException` (не ретраить).
27. `ConnectorTransientException` → бросить наружу (Messenger ретраит по расписанию).
28. Логировать: `companyId`, `rawRecordId`, `source`, статус. Credentials и payload не логировать никогда.

### Развитие модуля

29. Новые источники добавляются без изменения ядра pipeline (Action, Event, Handler).
30. Новые виды обогащения (`EnrichmentKind`) — новые классы, не изменение существующих.
31. При добавлении нового `resourceType` Ozon — добавить в `OzonCostCategory`.
32. `ARCHITECTURE.md` обновляется при каждом изменении Facade, Enum, новом коннекторе.
33. Перед merge: tenant-leak тест зелёный, `make site-cs-check` зелёный.

---

## Структура файлов модуля

```
src/Ingestion/
  Application/
    Action/          # Use cases: StartBackfillAction, NormalizeRawRecordAction, ...
    Command/         # CLI команды
    DTO/             # View/Result DTO
    Service/         # SystemCounterpartyResolver, IssueDescriptionFormatter, ...
    Source/
      Ozon/          # OzonSellerReportConnector, OzonAccrualByDayMapper, ...
  Domain/
    Contract/        # SourceConnectorInterface, SourceMapperInterface, ...
    Event/           # NormalizationCompletedEvent, AffectedPeriod
    ValueObject/     # Money
  Entity/            # IngestRawRecord, FinancialTransaction, SyncJob, ...
  Enum/              # IngestSource, TransactionType, SyncJobStatus, ...
  Exception/         # ConnectorAuthException, ConnectorTransientException, ...
  Facade/            # IngestionFacade, RawStorageFacade, CredentialFacade, SyncFacade
  Infrastructure/
    Api/Ozon/        # LegacyOzonClientAdapter
    Doctrine/        # CompanyFilter, EncryptedJsonType
    Http/            # CompanyFilterRequestSubscriber, IngestionExceptionListener
    Messenger/       # CompanyFilterMiddleware
    Query/           # CoverageQuery, ReconciliationQuery, ...
    Storage/         # (в App\Shared\Service\Storage)
  Message/           # RunSyncChunkMessage, NormalizeRawRecordMessage, ...
  MessageHandler/    # RunSyncChunkHandler, NormalizeRawRecordHandler, ...
  Repository/        # IngestRawRecordRepository, FinancialTransactionRepository, ...

src/Shared/Service/Storage/
  ObjectStorageInterface    # Единый интерфейс хранилища для всего проекта
  LocalObjectStorage        # Делегирует в StorageService (var/storage)
  FlysystemS3ObjectStorage  # S3 (включить через APP_OBJECT_STORAGE_DRIVER=s3)
  ObjectStorageFactory      # Выбирает driver по ENV
```

---

## Связанная документация

- `ozon-mapping.md` — маппинг полей Ozon API → канон
- `runbook.md` — инструкция саппорту
- `ARCHITECTURE.md` — общая архитектура проекта
