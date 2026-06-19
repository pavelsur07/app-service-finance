# TASK — БЛОК 6: Ingestion · OzonSellerReportConnector (первый реальный источник)

## 0. Сводка

- **Бизнес-цель.** Подключить первый реальный источник — Ozon seller reports (ежедневный отчёт + месячная реализация) — к единому контракту `SourceConnectorInterface` из блока 5. Не переписывать HTTP-клиент с нуля, обернуть существующие Ozon-адаптеры из `Marketplace/Infrastructure/Api`. Первый шаг к гашению дублирующего legacy-пайплайна.
- **Модуль.** `App\Ingestion` (новый коннектор + маппер) + минимальное использование `App\Marketplace` (только чтение существующих API-клиентов через адаптер, **без модификации**).
- **Тип.** integration (новый коннектор поверх существующей инфраструктуры).
- **Ветка.** `feature/ingestion-06-ozon-seller-connector`.
- **Подзадачи.** B1 Адаптер к существующим Ozon-клиентам · B2 OzonSellerReportConnector · B3 OzonSellerReportMapper (ежедневный отчёт) · B4 OzonRealizationMapper (месячная реализация) · B5 Классификация ошибок · B6 Контрактные фикстуры · B7 Тесты.
- **Затрагивает другие модули.** Да, читает через адаптер: `App\Marketplace\Infrastructure\Api\Ozon\*` (API-клиенты), `App\Marketplace\Entity\MarketplaceConnection` (credentials через `CredentialFacade` из блока 2 — она уже умеет читать legacy connection).
- **Требует миграции БД.** Нет.
- **Меняет публичный API.** Нет.

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- Блоки 1-5 готовы. Есть `SourceConnectorInterface`, `SourceMapperInterface`, канон `FinancialTransaction`, `IngestionFacade::getTransactions`, `RunSyncChunkHandler` запускает `connector->pull()`.
- В legacy существуют Ozon API-клиенты: `Marketplace/Infrastructure/Api/Ozon/OzonSellerClient` (или эквивалент — точное имя класса уточнить разведкой Marketplace при старте блока), `Marketplace/Service/Integration/...`. Они работают, ходят на `api-seller.ozon.ru`, имеют логику пагинации, ретраев.
- Credentials лежат в `marketplace_connections.api_key/client_id/settings`; `CredentialFacade::read` уже умеет их вытащить через `LegacyMarketplaceCredentialReader` (блок 2).
- В Ozon API одни и те же операции приходят дважды: в ежедневном `transaction_list` (предварительные данные) и в месячной реализации (`finance/realization`, финальные данные). Реализация перезаписывает предварительные данные через тот же natural key.

### 1.2 Желаемое состояние

- `OzonSellerReportConnector implements SourceConnectorInterface` с `source() = IngestSource::OZON` и capability'ями `[CAN_DISCOVER_SHOPS, CAN_PULL]`. `CAN_PUSH` — нет.
- Метод `pull()` обрабатывает два resourceType: `ozon_seller_daily_report` и `ozon_seller_realization`.
- `OzonSellerReportMapper` маппит строки ежедневного отчёта в `MappedTransaction[]` с декомпозицией: одна строка `accruals_for_sale + sale_commission_amount + deliv_charge_amount + ...` → несколько канонических транзакций с общим `operationGroupId`.
- `OzonRealizationMapper` маппит строки месячной реализации в тот же канон с тем же natural key (тот же `externalId` и `type`) — что обеспечивает перезапись предварительных данных финальными через механизм upsert (блок 5).
- Чёткая классификация ошибок: 401/403/missing creds → `ConnectorAuthException`; 5xx/timeout/network → `ConnectorTransientException`; 429 → `ConnectorTransientException` с подсказкой backoff.
- Параметры коннектора декларативны: размер чанка для бэкфилла = 7 дней; hot rewind window = 14 дней; rate limit = соответствует Ozon (1000 req/min на endpoint, фактически — намного консервативнее: 60/min).

### 1.3 In scope

- Адаптер `LegacyOzonClientAdapter` — обёртка над существующими Ozon API-клиентами. Не модифицирует их. Закрывает Ingestion-модуль от знания о внутренностях `Marketplace/Infrastructure/Api`.
- `App\Ingestion\Application\Source\Ozon\OzonSellerReportConnector`.
- `App\Ingestion\Application\Source\Ozon\OzonSellerReportMapper` (resourceType=`ozon_seller_daily_report`).
- `App\Ingestion\Application\Source\Ozon\OzonRealizationMapper` (resourceType=`ozon_seller_realization`).
- Маппинг полей Ozon → канон, зафиксированный в коде маппера и в `docs/ingestion/ozon-mapping.md`.
- Контрактные фикстуры: реальные (анонимизированные) ответы Ozon в `tests/Fixtures/Ingestion/Ozon/*.json`.
- Регистрация коннектора и обоих мапперов через теги.

### 1.4 Out of scope

- Модификация существующих Ozon API-клиентов в `Marketplace/Infrastructure/Api`.
- Замена/гашение существующего Ozon-пайплайна — это блок 9 (shadow + переключение).
- Ozon Performance Ads — отдельный источник, отдельный коннектор позже.
- Ozon FBO/FBS остатки (Inventory) — отдельный коннектор позже.
- WB финансы — следующий источник после стабилизации Ozon.
- Push документов в Ozon — не поддерживается.
- HTTP API — блок 8.

### 1.5 Допущения и открытые вопросы

- Допущение: имена legacy Ozon-классов и их публичные методы будут уточнены первым шагом блока (разведка `Marketplace/Infrastructure/Api/Ozon/`). На этапе ТЗ называю их по семантике (`OzonSellerClient`, `getTransactionList`, `getRealization`). Адаптер их прячет — даже если имена другие, контракт Ingestion не меняется.
- Открытый вопрос: ширина hot rewind у Ozon (сколько дней назад данные могут меняться)? Принимаем 14 дней по умолчанию, при необходимости — параметр.

---

## 2. Доменная модель

### 2.1 Сущности

Новых Entity нет.

Используются существующие из блоков 3 и 5: `IngestRawRecord`, `FinancialTransaction`, `Counterparty`, `NormalizationIssue`.

### 2.2 Связи

N/A.

### 2.3 Enum

Используются существующие: `IngestSource::OZON`, `TransactionType`, `TransactionDirection`, `Capability`, `NormalizationIssueKind`.

### 2.4 Матрица переходов статусов

N/A.

### 2.5 Resource types

Строковые константы, объявить в классе-перечислении:

#### `App\Ingestion\Application\Source\Ozon\OzonResourceType`

```php
final class OzonResourceType
{
    public const DAILY_REPORT  = 'ozon_seller_daily_report';
    public const REALIZATION   = 'ozon_seller_realization';
}
```

(Не enum, поскольку resourceType — открытое множество для всех источников; в Ozon-неймспейсе — закрытое.)

### 2.6 Маппинг полей Ozon → канон

#### Ежедневный отчёт (`transaction_list`)

Каждая строка `transaction_list` декомпозируется в N канонических транзакций с одним `operationGroupId = UUID v7 от (companyId, source, externalId)`. `externalId` строки = `operation_id` Ozon.

Маппинг колонок (полный список — в `docs/ingestion/ozon-mapping.md`, здесь — список типов с условием маппинга):

| Поле Ozon (типовое) | TransactionType | Direction | Условие |
|---|---|---|---|
| `accruals_for_sale` | `SALE` | `IN` если > 0, `OUT` если < 0 | Всегда создаётся, если ≠ 0 |
| `sale_commission_amount` | `COMMISSION` | `OUT` | Всегда отрицательная сумма ozon, направление OUT |
| `deliv_charge_amount` | `LOGISTICS` | `OUT` | Если ≠ 0 |
| `return_delivery_charge_amount` | `LOGISTICS` | `OUT` | Если ≠ 0 |
| `services_amounts.MarketplaceServiceItemReturnAfterDelivToCustomer` | `LOGISTICS` | `OUT` | Если ≠ 0 |
| `services_amounts.MarketplaceServiceItemDelivToCustomer` | `LAST_MILE` | `OUT` | Если ≠ 0 |
| Все остальные `services_amounts` | `FEE` | `OUT` | Каждый сбор отдельной транзакцией с описанием = ключ сбора |
| `acquiring` (если присутствует) | `ACQUIRING` | `OUT` | Если ≠ 0 |
| `amount` (тип операции = `ClientReturnAgentOperation`) | `REFUND` | `OUT` | Если operation_type указывает на возврат |

`occurredAt` = `operation_date` (UTC из ответа Ozon, без конвертации; источник уже даёт UTC).
`externalUpdatedAt` = `operation_date` для ежедневного отчёта (Ozon в transaction_list не отдаёт явно updated_at, используем operation_date как приближение).
`sourceTz = 'Europe/Moscow'` (для UI-отображения).
`orderRef` = `posting_number` если есть.
`description` = `operation_type_name`.
`sourceData` = вся исходная строка (для UI/саппорта).
`counterpartyExternalKey` = `null` для ежедневного отчёта (Ozon как контрагент — не выделяем).

`MappedControlSum` = `(operationGroupId, currency='RUB', amountMinor = ABS(сумма всех начислений и удержаний по строке))` — для сверки.

#### Месячная реализация (`finance/realization`)

Тот же набор типов и natural key, что и для ежедневного. Ключевые отличия:
- `externalUpdatedAt` = `realization_report_period_end` или `report_date` (точнее в фикстурах).
- Поскольку `externalUpdatedAt` реализации > `externalUpdatedAt` ежедневного отчёта того же периода — `UpsertFinancialTransactionAction.replaceFromNewerVersion` корректно перезапишет предварительные данные финальными (блок 5).
- Если в реализации появляется новая операция, которой не было в ежедневном — она создаётся как новая транзакция.

---

## 3. Слой доступа к данным

N/A — новых Repository/Query нет. Используется существующее.

---

## 4. Слой приложения

### 4.1 Адаптер к legacy Ozon-клиентам

#### `App\Ingestion\Infrastructure\Api\Ozon\LegacyOzonClientAdapter`

Файл: `src/Ingestion/Infrastructure/Api/Ozon/LegacyOzonClientAdapter.php`. `final class`.

Назначение: единственный класс в Ingestion, который **знает имена** legacy Ozon-классов. Если завтра legacy переименует/переделает свои клиенты, страдает только этот файл.

Конструктор: принимает существующий Ozon API-клиент через интерфейс или через прямой импорт (определить разведкой первым шагом блока). Принимает также `CredentialFacade` (блок 2) — для получения credentials по `(companyId, connectionRef)`.

Методы:
- `fetchTransactionList(string $companyId, string $connectionRef, DateTimeImmutable $from, DateTimeImmutable $to, int $page, int $pageSize): OzonRawPage` — обёртка над legacy-методом, возвращает структурированный DTO.
- `fetchRealization(string $companyId, string $connectionRef, int $year, int $month): OzonRawPage`.
- `listClusters(string $companyId, string $connectionRef): list<OzonShopDescriptor>` — для `discoverShops`. У Ozon один аккаунт обычно = один shop_id, поэтому возможен один элемент.

`OzonRawPage` (`final readonly class`): `rows: array`, `hasMore: bool`, `nextPageToken: ?string`.
`OzonShopDescriptor` (`final readonly class`): `externalId: string`, `name: string`.

Адаптер ловит исключения legacy-клиента и **классифицирует**:
- HTTP 401, 403, отсутствие credentials → `ConnectorAuthException`.
- HTTP 5xx, timeout, network failure → `ConnectorTransientException`.
- HTTP 429 → `ConnectorTransientException` (Messenger ретраит).
- Любое другое → пробрасывает как `\RuntimeException` (handler `RunSyncChunkHandler` пометит job failed).

### 4.2 SourceConnector

#### `App\Ingestion\Application\Source\Ozon\OzonSellerReportConnector`

Файл: `src/Ingestion/Application/Source/Ozon/OzonSellerReportConnector.php`. `final class`, реализует `SourceConnectorInterface` (блок 5). Тег: `app.ingestion.connector`.

Конструктор: `LegacyOzonClientAdapter $client, LoggerInterface $logger`.

Методы:

- `source(): IngestSource` → `IngestSource::OZON`.
- `capabilities(): list<Capability>` → `[Capability::CAN_DISCOVER_SHOPS, Capability::CAN_PULL]`.
- `discoverShops(string $companyId, string $connectionRef): list<ShopDescriptor>`:
  1. `$adapter->listClusters($companyId, $connectionRef)`.
  2. Маппит `OzonShopDescriptor` → `ShopDescriptor` (currency='RUB' для Ozon RU; для KZ/BY — определяется по дальнейшему сигналу, в MVP только RU).
  3. Возврат списка.
- `pull(PullRequest $request): PullResult`:
  - Если `$request->resourceType === OzonResourceType::DAILY_REPORT`:
    1. Определить `(from, to)`: из `cursorValue` (если null — `windowFrom`); до `min(windowTo, cursorValue + 7 дней)`.
    2. Постранично вызвать `$adapter->fetchTransactionList(...)` с пагинацией; собрать строки в `iterable`.
    3. Сформировать `RawBatch` с `source=OZON, resourceType=DAILY_REPORT, externalId=date_range_token`.
    4. `nextCursorValue` = ISO дата `to` (следующий чанк стартует от `to+1d`); `hasMore = to < windowTo`.
  - Если `$request->resourceType === OzonResourceType::REALIZATION`:
    1. Из `windowFrom/windowTo` определить `(year, month)`. Для каждого месяца отдельный pull (caller — `RunSyncChunkHandler` — может разбить по месяцам через настройку chunkSizeInDays блока 4, либо коннектор сам делит и итерирует).
    2. `$adapter->fetchRealization($companyId, $connectionRef, $year, $month)`.
    3. `RawBatch` с `externalId=YYYY-MM`.
    4. `nextCursorValue` = следующий месяц; `hasMore` определяется по `windowTo`.
- `push(PushRequest $request): PushResult` → `UnsupportedCapabilityException`.

Параметры коннектора (декларативно, в `services.yaml` через arguments):
- `chunk_size_days`: 7 (для DAILY_REPORT).
- `hot_rewind_days`: 14 (используется блоком 4 / future cron).
- `rate_limit_rpm`: 60 (через `IngestRateLimitGuard`).

### 4.3 Мапперы

#### `App\Ingestion\Application\Source\Ozon\OzonSellerReportMapper`

Файл: `src/Ingestion/Application/Source/Ozon/OzonSellerReportMapper.php`. `final class`, реализует `SourceMapperInterface`. Тег: `app.ingestion.mapper`.

Чистая функция (без БД/HTTP).

Методы:
- `source(): IngestSource` → `IngestSource::OZON`.
- `resourceTypes(): list<string>` → `[OzonResourceType::DAILY_REPORT]`.
- `map(IngestRawRecord $rawRecord, iterable $rows): list<MappedTransaction>` — для каждой строки `transaction_list` создаёт декомпозированный набор `MappedTransaction` по таблице из §2.6.
- `controlSum(iterable $rows): list<MappedControlSum>` — для каждой строки одна MappedControlSum с суммой `|accruals_for_sale|+|sale_commission_amount|+|deliv_charge_amount|+...`.

Маппер ловит «неизвестное значение operation_type» и возвращает соответствующий `MappedTransaction` с типом `OTHER` + `sourceData` (всё исходное). НЕ бросает — пусть нормализатор сам решит создавать ли issue. (Альтернатива: бросать — но тогда теряется одна строка; лучше fallback в OTHER + лог).

#### `App\Ingestion\Application\Source\Ozon\OzonRealizationMapper`

Файл: `src/Ingestion/Application/Source/Ozon/OzonRealizationMapper.php`. `final class`.

Методы:
- `source()` → `IngestSource::OZON`.
- `resourceTypes()` → `[OzonResourceType::REALIZATION]`.
- `map(...)` — структура реализации отличается (там не `transaction_list`, а агрегированный `report`), но финальный канон тот же. Декомпозиция аналогична: одна строка реализации = N канонических транзакций с тем же `externalId` и `operationGroupId`, что в ежедневке (через тот же алгоритм построения id).
- `controlSum(...)`.

### 4.4 Алгоритм генерации natural key

Чтобы `OzonRealizationMapper` корректно перезаписал предварительные данные `OzonSellerReportMapper`, оба маппера должны генерировать **одинаковый** `externalId` для одной и той же бизнес-операции.

Правило для Ingestion-Ozon:
- `externalId` транзакции = строка `ozon:operation:{operation_id}` (Ozon `operation_id` уникален в рамках tenant'а).
- `operationGroupId` = UUID v5 от строки `{companyId}:{externalId}` (детерминированный, одинаков в обоих мапперах).
- `type` = строго один из `TransactionType` (один тип = одна строка в каноне).

Это гарантирует, что upsert в `UpsertFinancialTransactionAction` (блок 5) перезапишет ежедневный отчёт реализацией.

Если в реализации `operation_id` отсутствует — fallback: `externalId = ozon:realization:{posting_number}:{type}:{date}`. Документировать в `ozon-mapping.md`.

### 4.5 Action

В блоке 6 новые Action не вводим. Используются `NormalizeRawRecordAction` и `UpsertFinancialTransactionAction` из блока 5.

### 4.6 DTO

Новые: `OzonRawPage`, `OzonShopDescriptor` (см. §4.1).

### 4.7 Регистрация в DI

`config/services.yaml`:
```yaml
services:
  App\Ingestion\Application\Source\Ozon\OzonSellerReportConnector:
    arguments:
      $chunkSizeDays: 7
      $hotRewindDays: 14
      $rateLimitRpm: 60
    tags: ['app.ingestion.connector']

  App\Ingestion\Application\Source\Ozon\OzonSellerReportMapper:
    tags: ['app.ingestion.mapper']

  App\Ingestion\Application\Source\Ozon\OzonRealizationMapper:
    tags: ['app.ingestion.mapper']
```

---

## 5. Асинхронность (Messenger)

Новых Message/Handler нет — используется `RunSyncChunkHandler` из блока 5, который вызовет `OzonSellerReportConnector::pull()` через `ConnectorRegistry`.

---

## 6. Обработка ошибок

Все исключения уже определены в блоке 5. Здесь — правила, когда какое:

| Ситуация | Исключение | Действие handler'а |
|---|---|---|
| 401/403 от Ozon, отсутствие credentials | `ConnectorAuthException` | `markJobFailed("auth")`, `UnrecoverableMessageHandlingException` |
| 429 от Ozon | `ConnectorTransientException` | rethrow → Messenger ретраит (10s/20s/40s) |
| 5xx, timeout, network | `ConnectorTransientException` | rethrow → ретрай |
| Невалидный ответ Ozon (parse error) | `\RuntimeException` | `markJobFailed(reason)`, rethrow |
| Запрос > 90 дней (Ozon ограничение) | `\InvalidArgumentException` в `pull()` | `markJobFailed` |

Логирование: каждый внешний вызов Ozon — `INFO` лог с полями `companyId`, `connectionRef`, `endpoint`, `httpStatus`, `durationMs`, **без тела ответа** (CLAUDE.md §«Логирование»).

---

## 7. HTTP API (Controller)

N/A.

---

## 8. Разбивка на подзадачи

| Этап | Что | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | Разведка legacy Ozon-клиентов: имена классов, публичные методы, формат ответов | блоки 1-5 | 🟢 | — |
| B2 | `LegacyOzonClientAdapter` + классификация ошибок + `OzonRawPage`/`OzonShopDescriptor` | B1 | 🔴 (касается границ legacy) | unit на классификацию ошибок (mock legacy-клиента) |
| B3 | `OzonResourceType` + `OzonSellerReportConnector` | B2 | 🟡 | unit на `capabilities`, `push throws`; integration на `pull` через `LegacyOzonClientAdapter` mock |
| B4 | `OzonSellerReportMapper` + фикстуры ежедневного отчёта | B3 | 🟡 | контрактные unit-тесты на фикстурах (10+ кейсов: продажа, возврат, штраф, ...) |
| B5 | `OzonRealizationMapper` + фикстуры реализации | B4 | 🟡 | контрактные unit-тесты + тест перезаписи ежедневки реализацией через тот же natural key |
| B6 | Регистрация в DI + tagged services | B3, B4, B5 | 🟢 | integration: `ConnectorRegistry::get(OZON)` возвращает наш коннектор; оба маппера видны через `MapperRegistry` |
| B7 | end-to-end интеграционный тест: `SyncFacade.startBackfill` → `RunSyncChunkHandler` → `OzonSellerReportConnector` (mock LegacyAdapter) → raw в S3 → `NormalizeRawRecordHandler` → канон | все | 🔴 | один большой test с in-memory transport |
| B8 | `docs/ingestion/ozon-mapping.md` — таблица маппинга, fallback'и, правила natural key | B4, B5 | 🟢 | — |
| B9 | `ARCHITECTURE.md` обновить | все | 🟢 | — |

Детализация B1 (разведка legacy):

В рамках первого шага блока — task для Claude Code:

```
Прочитай site/src/Marketplace/Infrastructure/Api/Ozon/ и site/src/Marketplace/Service/Integration/.
Выдай отчёт:
1. Список классов Ozon API-клиентов с public-методами и их сигнатурами.
2. Какие из них дёргают /v3/finance/transaction/list (или аналог) и /v1/finance/realization.
3. Какие исключения они бросают.
4. Где и как они читают credentials (через connection? через service?).
Код не меняй.
```

Результат — input для B2.

---

## 9. Ограничения и запреты

- Не модифицировать `Marketplace/Infrastructure/Api/Ozon/*` (legacy-клиенты). `LegacyOzonClientAdapter` — единственный мост.
- Не вводить ManyToOne связи с `MarketplaceConnection`. Credentials берутся через `CredentialFacade` строкой `connectionRef` (это либо UUID существующего `MarketplaceConnection`, либо собственный id будущего нового подключения).
- Не трогать существующий cron `app:marketplace:ozon-daily-sync` — он продолжает работать параллельно. Гашение — блок 9.
- Не вводить HTTP-эндпоинты. Pull инициируется через `SyncFacade.startBackfill` или через будущий cron (блок 9).
- Performance: для одного pull-чанка (7 дней) не делать > 10 страниц Ozon API; если упёрлись — увеличить `pageSize` или уменьшить `chunkSizeDays`.

---

## 10. Критерии приёмки

Функциональные:
- [ ] `OzonSellerReportConnector.discoverShops` возвращает список ShopDescriptor.
- [ ] `OzonSellerReportConnector.pull(DAILY_REPORT)` для 7-дневного окна делает корректный вызов адаптера с правильными датами.
- [ ] `OzonSellerReportMapper.map` на эталонной фикстуре `transaction_list_with_sale_and_commission.json` производит правильный набор `MappedTransaction` (продажа IN + комиссия OUT + логистика OUT, общий `operationGroupId`, согласованный `externalId`).
- [ ] `OzonRealizationMapper.map` на эталонной фикстуре `realization_february_2026.json` производит набор с тем же `externalId`, что и ежедневный отчёт за тот же период.
- [ ] End-to-end тест: запуск backfill на 7 дней → `RunSyncChunkHandler` через mock LegacyAdapter → raw в S3 + IngestRawRecord → `NormalizeRawRecordHandler` → канон в `FinancialTransaction` со всеми правильными суммами.
- [ ] Перезапись: запуск ежедневки → есть транзакции с `externalUpdatedAt = D1`; запуск реализации с `externalUpdatedAt = D2 > D1` → те же транзакции обновлены, новых дубликатов нет.
- [ ] 401 от Ozon → `ConnectorAuthException` → job статус FAILED, `lastError = "auth"`, Messenger не ретраит.
- [ ] 5xx от Ozon → `ConnectorTransientException` → Messenger ретраит.
- [ ] 429 от Ozon → ретрай с задержкой.
- [ ] Push операция (любая) → `UnsupportedCapabilityException`.
- [ ] `controlSum` корректно считается; искусственное расхождение → `NormalizationIssue(SUM_MISMATCH)`.

Технические:
- [ ] `make site-cs-check` + PHPStan зелёные.
- [ ] `make site-test-unit` + `make site-test-integration` зелёные.
- [ ] Tenant-leak: коннектор компании A не возвращает данные B (через mock LegacyAdapter, который возвращает разные ответы по companyId).
- [ ] Логирование внешних вызовов: запись присутствует, тело ответа НЕ логируется (тест по фильтру в логах).
- [ ] `docs/ingestion/ozon-mapping.md` существует и содержит таблицу маппинга.
- [ ] `ARCHITECTURE.md` обновлён: добавлен раздел про OzonSellerReportConnector + два resourceType.

---

## 11. План отката

- Удалить три новых сервиса из DI (`OzonSellerReportConnector`, `OzonSellerReportMapper`, `OzonRealizationMapper`) → `ConnectorRegistry::get(OZON)` бросит `ConnectorNotFoundException`. Любой запущенный `RunSyncChunkMessage` для OZON упадёт, но не приведёт к порче данных (raw не пишется, канон не меняется).
- Legacy-пайплайн продолжает работать → данные клиента видны.
- Адаптер `LegacyOzonClientAdapter` после удаления коннектора становится мёртвым кодом, удаляется отдельным коммитом.
- Зависимости вниз: блок 7 использует канон. Если канон Ozon ещё не наполнен (откатили блок 6) — блок 7 просто работает на пустых данных, без ошибок.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь для каждого класса.
- [x] Маппинг полей Ozon → канон в таблице.
- [x] Правило natural key зафиксировано (`ozon:operation:{operation_id}`).
- [x] Classification ошибок в таблице.
- [x] Параметры коннектора декларативны (через `services.yaml`).
- [x] DI-конфигурация прописана.
- [x] HTTP API — N/A.
- [x] Out of scope зафиксирован (WB, Ads, Inventory, push).
- [x] Адаптер прячет legacy — единственная точка зависимости.
- [x] Контрактные фикстуры обязательны для DoD.
- [x] План отката не порождает потери данных.
- [x] Разведка legacy указана как B1 — input для B2.
