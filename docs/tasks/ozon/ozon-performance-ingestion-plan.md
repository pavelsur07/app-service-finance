# План загрузки Ozon Performance через модуль Ingestion

Дата: 2026-06-27

## Цель

Развить модуль `Ingestion` так, чтобы рекламные данные Ozon Performance загружались через общий ingestion-пайплайн: `SyncJob` -> connector `pull()` -> `RawBatch` -> `IngestRawRecord`.

Фокус первого этапа: загрузка и хранение сырых данных. Нормализация в расходы, P&L, распределение по SKU/listing и UI-аналитика остаются отдельными этапами.

Денежное правило проекта: при будущей нормализации любые суммы рекламы/затрат должны проходить через `App\Shared\Domain\ValueObject\Money`. Raw-этап сохраняет payload как есть, но mapper не должен использовать `float` для денег.

## Исправленное архитектурное решение

Целевой модуль: `site/src/Ingestion`.

`Marketplace` используется только как источник подключений и credentials, по аналогии с текущей загрузкой финансовых отчетов.

`MarketplaceAds` не является целевым модулем для нового пайплайна. Его можно использовать как источник опыта по Ozon Performance API, batch/polling ограничениям и существующим клиентским методам, но новая загрузка должна жить в `Ingestion`.

## Что уже есть в Ingestion

Подходящие строительные блоки уже есть:

- `SourceConnectorInterface` - единый контракт источника.
- `PullRequest` / `PullResult` - модель загрузки чанка.
- `RawBatch` - контейнер сырого результата.
- `StoreRawBatchAction` - идемпотентное сохранение raw payload в объектное хранилище и `ingest_raw_records`.
- `SyncJob` - job для incremental/backfill.
- `SplitJobIntoChunksAction` - разбиение backfill на окна.
- `RunSyncChunkHandler` - запуск connector `pull()`, сохранение raw, retry/rate-limit flow.
- `ConnectorAuthException`, `ConnectorRateLimitedException`, `ConnectorTransientException` - уже существующая модель ошибок.
- `OzonResourceType` - место для новых resource type Ozon.
- `OzonSellerReportConnector` - пример Ozon connector для финансовых отчетов.

Вывод: не нужно создавать отдельную систему загрузки. Нужно добавить новые Ozon Performance resources в существующий ingestion-контур.

## Подключение к Performance API

### На старте

Новый OAuth через seller connection на старте не поддерживаем.

Используем отдельное Performance-подключение из модуля `Marketplace`:

```php
MarketplaceFacade::getConnectionCredentials(
    $companyId,
    MarketplaceType::OZON,
    MarketplaceConnectionType::PERFORMANCE,
)
```

Ожидаемый режим авторизации:

```text
POST https://api-performance.ozon.ru/api/client/token
grant_type=client_credentials
client_id=<performance client id>
client_secret=<performance secret>
```

Дальше все Performance API запросы идут с:

```text
Authorization: Bearer <access_token>
```

### Что нужно добавить в Marketplace facade

Сейчас в `MarketplaceFacade` есть публичный метод для активных Ozon seller-подключений. Для Ingestion лучше добавить аналогичный публичный метод:

```php
getActiveOzonPerformanceConnections(?string $companyId = null): array
```

Он должен возвращать безопасный список:

```text
connectionId
companyId
marketplace=ozon
connectionType=performance
clientId
```

Ingestion не должен читать секреты напрямую из таблиц.

## Новый connector

Добавить отдельный connector:

```text
App\Ingestion\Application\Source\Ozon\OzonPerformanceReportConnector
```

Он должен реализовать `SourceConnectorInterface`:

- `source()` -> `IngestSource::OZON`;
- `capabilities()` -> `CAN_DISCOVER_SHOPS`, `CAN_PULL`;
- `discoverShops()` -> один shop descriptor на Performance connection;
- `pull()` -> route по новым `OzonResourceType::*`.

Важно: не расширять бесконечно `OzonSellerReportConnector`. Финансы и реклама должны быть разными connector/service классами внутри `Ingestion\Application\Source\Ozon`.

## Новые resource types

Добавить в `OzonResourceType`:

```php
public const PERFORMANCE_CAMPAIGNS = 'ozon_performance_campaigns';
public const PERFORMANCE_SKU_CAMPAIGN_OBJECTS = 'ozon_performance_sku_campaign_objects';
public const PERFORMANCE_SEARCH_PROMO_PRODUCTS = 'ozon_performance_search_promo_products';
public const PERFORMANCE_SKU_PRODUCT_STATISTICS = 'ozon_performance_sku_product_statistics';
public const PERFORMANCE_SEARCH_PROMO_STATISTICS = 'ozon_performance_search_promo_statistics';
public const PERFORMANCE_EXPENSE_STATISTICS = 'ozon_performance_expense_statistics';
```

Первый этап включает сразу:

- SKU CPC;
- Search Promo / CPO;
- expense statistics как контроль.

Media/banner/video можно не нормализовать в первом этапе, но campaign catalog должен сохранять типы кампаний, чтобы мы не потеряли их наличие.

## Какие методы грузить

По дополнительной проверке документации/публичных материалов Ozon for dev:

- Search Promo / CPO представлен отдельными методами, не тем же самым payload, что SKU CPC;
- для CPO есть как минимум два отчётных направления: отчёт по товарам и отчёт по заказам;
- поэтому вопрос про exact endpoints был не про то, грузить CPO или нет, а про источник истины для будущей нормализации CPO.

Решение для первого этапа: грузим оба CPO-отчёта в raw, а нормализацию выбираем позже на реальных payload.

### Campaign catalog

Метод:

```text
GET /api/client/campaign
```

Грузить минимум типы:

```text
SKU
SEARCH_PROMO
```

Raw resource:

```text
ozon_performance_campaigns
```

Назначение:

- campaign id;
- campaign name;
- state;
- advObjectType;
- период активности;
- связь для последующей статистики.

### SKU CPC promoted objects

Метод:

```text
GET /api/client/campaign/{campaignId}/objects
```

Raw resource:

```text
ozon_performance_sku_campaign_objects
```

Назначение:

- какие SKU участвуют в CPC campaign;
- mapping campaign -> SKU;
- контроль, что статистика не потеряла товар.

### Search Promo / CPO products

Метод:

```text
POST /api/client/campaign/search_promo/v2/products
```

Raw resource:

```text
ozon_performance_search_promo_products
```

Назначение:

- товары в продвижении с оплатой за заказ;
- mapping search promo campaign -> SKU/product;
- отдельный источник для CPO, не смешивать с CPC objects.

### SKU CPC statistics

Основной кандидат:

```text
GET /api/client/statistics/campaign/product/json
```

Fallback:

```text
POST /api/client/statistics
GET /api/client/statistics/{uuid}
GET /api/client/statistics/report?UUID=...
```

Raw resource:

```text
ozon_performance_sku_product_statistics
```

Назначение:

- расходы/показы/клики/заказы по SKU или товарной строке, если Ozon возвращает этот разрез;
- сырой payload без попытки сразу привести к финансовой модели.

### Search Promo / CPO statistics

Методы первого этапа:

```text
POST /api/client/statistic/products/generate
POST /api/client/statistic/orders/generate
```

Raw resource:

```text
ozon_performance_search_promo_statistics
```

Назначение:

- `products/generate` - основной raw-кандидат для будущих затрат/метрик по товарам в Search Promo/CPO;
- `orders/generate` - raw-кандидат для будущей проверки заказной атрибуции и CPO-событий;
- сохранить оба сырьевых отчёта, даже если структура отличается от CPC.

Важно: CPO нельзя маппить тем же parser'ом, что CPC. Это отдельный resource и отдельный будущий mapper.

### Expense control

Метод:

```text
GET /api/client/statistics/expense/json
```

Raw resource:

```text
ozon_performance_expense_statistics
```

Назначение:

- контрольная сумма по кампаниям;
- сверка с product/search-promo статистикой;
- диагностика расхождений.

Это не основной источник SKU-затрат.

## Raw idempotency

Используем существующую механику `StoreRawBatchAction`:

- один `RawBatch` -> один `IngestRawRecord`;
- `externalId + hash` защищает от дублей;
- если payload не изменился, запись просто `markSeen()`.

Для каждого resource нужен стабильный `externalId`.

Примеры:

```text
performance-campaigns:<date>
performance-sku-objects:<campaignId>:<date>
performance-search-promo-products:<campaignId>:<date>
performance-sku-stats:<from>:<to>:batch:<batchHash>
performance-search-promo-stats:<from>:<to>:batch:<batchHash>
performance-expense-stats:<from>:<to>
```

Если Ozon отдает данные в нестабильном порядке, connector должен сортировать строки перед созданием `RawBatch`, как это уже сделано для accrual rows.

## Cursor и pagination

`PullRequest::cursorValue` - строка, поэтому для Performance можно использовать компактный JSON cursor.

Пример:

```json
{
  "date": "2026-06-26",
  "resource": "sku_stats",
  "campaignOffset": 20,
  "page": 1
}
```

Правила:

- cursor должен быть stable и backward compatible;
- при ошибке batch должен повториться с тем же cursor;
- cursor не должен содержать secrets или большие payload.

## Ежедневная загрузка

Команда-оркестратор в `Ingestion`:

```bash
php bin/console app:ingestion:ozon-performance:daily-load \
  --days-back=14 \
  --execute
```

Поведение:

1. Получить все активные Ozon Performance connections через `MarketplaceFacade`.
2. Для каждого подключения построить окно:

```text
from = today - daysBack
to = yesterday
```

3. Для каждого подключения запустить ingestion jobs по ресурсам:
   - campaigns;
   - SKU campaign objects;
   - Search Promo products;
   - SKU product statistics;
   - Search Promo/CPO statistics;
   - expense statistics.
4. Ошибка одного аккаунта или resource не должна останавливать остальные.
5. В конце вывести summary:
   - connections total/success/failed;
   - jobs created/skipped/failed;
   - raw records created/seen/failed;
   - failed resources;
   - auth/rate-limit/transient errors.

Для первого варианта можно сделать orchestration синхронной только на уровне создания jobs. Сами загрузки должны идти через существующий `RunSyncChunkMessage`.

## Отображение в разделе "Покрытие данных"

Все новые загрузки Ozon Performance должны отображаться в существующем разделе:

```text
/ingestion/coverage
```

Текущий `CoverageQuery` уже строит heatmap по `ingest_raw_records.resource_type`, `sync_job.window_from/window_to`, `shop_ref`, failed jobs и normalization issues.

Требования:

1. Каждый Performance resource должен сохраняться через `RawBatch` -> `IngestRawRecord`.
2. У каждого `SyncJob` должны быть корректные:
   - `company_id`;
   - `connection_ref`;
   - `shop_ref`;
   - `resource_type`;
   - `window_from`;
   - `window_to`.
3. Для raw-only этапа `tx_count` в покрытии будет `0` - это нормально.
4. Зеленое покрытие для raw-only ресурса означает `raw_count > 0` и `issue_count = 0`.
5. Failed jobs должны попадать в покрытие через текущий `failedJobIssueRows()`.
6. Для отсутствующего Performance-подключения не создавать failed coverage row; это не ошибка загрузки, а отсутствие источника. Такое состояние должно быть видно в отдельном status/health summary.

Дополнительно стоит улучшить читаемость UI:

- добавить человекочитаемые labels для новых resource types;
- сгруппировать ресурсы в покрытии по направлению:
  - `Ozon Performance / Campaigns`;
  - `Ozon Performance / SKU CPC`;
  - `Ozon Performance / Search Promo CPO`;
  - `Ozon Performance / Expense control`;
- оставить технический `resource_type` в tooltip.

Если UI-группировку делать не сразу, минимально достаточно появления строк с новыми `resource_type` в текущей heatmap.

## Backfill при подключении клиента

По умолчанию - с начала текущего месяца, как финансовые отчеты.

Команда:

```bash
php bin/console app:ingestion:ozon-performance:backfill \
  --company-id=<uuid> \
  --from=2026-06-01 \
  --to=2026-06-26 \
  --execute
```

Если клиент подключился в середине квартала и нужна история квартала, это должен быть явный запуск:

```bash
php bin/console app:ingestion:ozon-performance:backfill \
  --company-id=<uuid> \
  --from=2026-04-01 \
  --to=2026-06-26 \
  --execute
```

Автоматически грузить год назад не нужно.

## Батчинг

Рекомендуемые ограничения:

```text
campaignIds per stats request: 10
daily rewind: 14 дней
backfill chunk: 7 дней
max async range guard: 62 дня
active async statistics report per account: 1
```

Почему:

- в текущем Ozon Performance опыте уже зафиксирован лимит 10 campaignIds;
- async отчеты Ozon могут зависать в очереди;
- при 1000 кабинетах важнее продолжение и retry, чем скорость одного кабинета.

## Ошибки и retry

Использовать существующие ingestion exceptions:

- `ConnectorAuthException` - 401/403, неверные credentials, нет прав;
- `ConnectorRateLimitedException` - 429, есть retry-after или расчетная задержка;
- `ConnectorTransientException` - 5xx/network/timeouts;
- unexpected exception - логировать и дать Messenger retry.

Правила:

- 401: сброс token cache, один retry, затем auth failure;
- 403: permanent auth/permission failure;
- 429: continuation/retry delay;
- 5xx/network: retry;
- ошибка одного child job не должна ломать остальные company/resource jobs;
- оркестратор должен завершаться с понятным summary.

Sentry/log context:

```text
companyId
connectionRef
resourceType
syncJobId
windowFrom/windowTo
cursorValue
campaignIds count/hash
endpoint
httpStatus
ozonReportUuid
```

Запрещено логировать:

```text
client_secret
api_key
access_token
refresh_token
```

## Нормализация

В первом этапе отключить нормализацию:

```php
new PullResult(
    rawBatch: $batch,
    nextCursorValue: $nextCursor,
    hasMore: $hasMore,
    normalizeRawRecords: false,
)
```

Причина:

- сначала нужен надежный raw слой;
- CPC и CPO имеют разную семантику;
- expense statistics - контрольный источник, а не транзакционная строка;
- нормализация рекламных расходов должна быть отдельным reviewable stage.

## Деньги и Money

Правило из `ARCHITECTURE.md`: суммы хранятся в minor units, shared `Money` signed и контролирует ISO-4217 валюту.

Для первого raw-only этапа:

- сохраняем денежные поля Ozon в сыром payload без преобразования;
- не округляем;
- не приводим к `float`;
- не пытаемся заранее угадать знак расхода.

Для будущей нормализации:

- все money-like поля нужно парсить в minor units;
- создавать `Money::fromMinor($amountMinor, $currency)`;
- направление расхода задавать через `TransactionDirection`, а не только через знак;
- CPC и CPO нормализовать отдельными mapper'ами;
- сверять суммы с `ozon_performance_expense_statistics`;
- при несовпадении валюты или невозможности разобрать сумму создавать `NormalizationIssue`, а не молча пропускать строку.

Кодовые ориентиры:

- `App\Shared\Domain\ValueObject\Money`;
- `Ingestion\Application\DTO\MappedTransaction`;
- `Ingestion\Entity\FinancialTransaction`;
- `OzonMoneyParser` как пример decimal -> minor parsing для Ozon.

## Что не делать

- Не развивать новый пайплайн в `MarketplaceAds`.
- Не смешивать рекламную загрузку с финансовым `OzonSellerReportConnector`.
- Не поддерживать новый OAuth через seller connection в первом этапе.
- Не маппить CPC и CPO одним parser'ом.
- Не превращать один connector в огромный класс со всей логикой; API client, cursor planner и resource pullers должны быть разделены.
- Не делать автоматический годовой backfill при подключении клиента.

## Этапы реализации

### Этап 1. Contracts and resource skeleton

Риск: medium.

Действия:

- добавить Ozon Performance resource types;
- добавить `OzonPerformanceReportConnector`;
- добавить Performance API client/resolver внутри `Ingestion`;
- подключение credentials брать через `MarketplaceFacade` и `MarketplaceConnectionType::PERFORMANCE`;
- нормализацию выключить.

Результат:

- Ingestion знает новые Ozon Performance resources, но еще не делает тяжелой бизнес-нормализации.

### Этап 2. Campaign catalog + objects

Риск: medium.

Действия:

- `GET /api/client/campaign`;
- `GET /api/client/campaign/{campaignId}/objects` для SKU CPC;
- `POST /api/client/campaign/search_promo/v2/products` для Search Promo/CPO;
- raw external ids и стабильная сортировка.

Результат:

- есть сырая карта campaigns -> SKU/products для CPC и CPO.

### Этап 3. Statistics raw loading

Риск: medium/high.

Действия:

- SKU CPC product statistics;
- Search Promo/CPO reports;
- expense statistics;
- batching по 10 campaign ids;
- cursor/pagination;
- auth/rate-limit/transient exceptions.

Результат:

- сырые расходы и метрики рекламы лежат в `ingest_raw_records`.

### Этап 4. Coverage integration

Риск: medium.

Действия:

- убедиться, что все Performance raw jobs имеют корректные `window_from/window_to`;
- проверить, что raw-only resources появляются в `/ingestion/coverage`;
- добавить labels/grouping для новых Ozon Performance resource types;
- failed jobs должны давать issue rows в покрытии;
- отсутствие Performance-подключения не должно считаться failed coverage.

Результат:

- оператор видит рекламные загрузки в "Покрытии данных" вместе с финансовыми ingestion-ресурсами.

### Этап 5. Daily/backfill orchestrators

Риск: high, потому что operational behavior.

Действия:

- `app:ingestion:ozon-performance:daily-load --days-back=14 --execute`;
- `app:ingestion:ozon-performance:backfill --company-id --from --to --execute`;
- создание `SyncJob`/child chunks через существующие actions;
- summary;
- partial failure behavior.

Результат:

- одна команда запускает все компании/resources без ручного UUID-by-UUID цикла.

### Этап 6. Health/status

Риск: low.

Действия:

- команда статуса:
  - активные Performance connections;
  - last successful raw by resource;
  - failed jobs;
  - missing days;
  - rate-limit/auth errors;
  - expense reconciliation diff, если достаточно данных.

Результат:

- проблему видно без SQL руками.

### Этап 7. Нормализация

Риск: high, отдельно от этого плана.

Действия:

- отдельно спроектировать mapper для CPC;
- отдельно mapper для Search Promo/CPO;
- отдельно reconciliation с expense stats;
- отдельно влияние на P&L и existing advertising reports.
- использовать `Money::fromMinor(...)` для всех денежных значений;
- запретить `float` в money-path;
- фиксировать currency/rounding/sign правила тестами.

Результат:

- raw данные превращаются в финансовую модель только после отдельного review.

## Вопрос -> предложение

| Вопрос | Предложение |
| --- | --- |
| Где живет новый pipeline? | В `Ingestion`. |
| Где брать credentials? | Через `MarketplaceFacade`, `MarketplaceConnectionType::PERFORMANCE`. |
| Поддерживать новый OAuth через seller connection? | Нет на старте. Только отдельное Performance-подключение. |
| Что включать в первый этап? | SKU CPC + Search Promo/CPO + expense control raw. |
| Использовать `MarketplaceAds`? | Не как целевой модуль. Только как reference для Ozon Performance API ограничений. |
| Нужна ли нормализация сразу? | Нет. `normalizeRawRecords=false`, нормализация отдельным этапом. |
| Как делать ежедневную загрузку? | Rolling 14 дней по всем активным Performance connections. |
| Как делать подключение клиента в середине месяца? | Backfill с начала текущего месяца. Квартал - только явной командой. |
| Как не получить мусорку? | Resource-specific pullers: campaigns, sku objects, search promo products, sku stats, cpo stats, expenses. |
| Как пережить 1000 кабинетов? | Jobs/chunks/cursors, rate-limit lock, partial failures, retry через Messenger. |
| Какой endpoint для CPO? | В raw грузить оба: `POST /api/client/statistic/products/generate` и `POST /api/client/statistic/orders/generate`. Для будущей нормализации primary candidate - products report, orders report - контроль/атрибуция. |
| Должно ли это быть видно в "Покрытии"? | Да. Все Performance resources должны идти через `ingest_raw_records` и появляться в `/ingestion/coverage`; raw-only ресурсы имеют `tx_count=0`. |
| Как работать с денежными полями? | В raw сохранять как пришло от Ozon; при нормализации парсить в minor units и использовать `Money::fromMinor(...)`, без `float`. |

## Закрытие ранее открытых вопросов

1. Performance-подключение: считаем отдельное `performance` подключение обязательным на старте. Если его нет - аккаунт пропускается без failed job.
2. CPO endpoints: грузим оба raw отчета: `products/generate` и `orders/generate`.
3. Rolling window: стартовое значение 14 дней. Если на live CPO покажет позднюю атрибуцию глубже 14 дней - поднять до 30 дней отдельным config change.
4. `MarketplaceAds`: не переносить исторические данные в первом этапе. Новый pipeline стартует в `Ingestion`; legacy данные остаются как есть до отдельного решения.
5. Финансовая модель: не решать в raw-loading PR. После накопления raw sample сделать отдельный план нормализации CPC/CPO и влияния на P&L.
6. Покрытие: обязательно включить новые resources в `/ingestion/coverage` на этапе raw-loading.

## Оставшиеся проверки перед кодом

1. На одном тестовом Performance-подключении снять live sample по:
   - SKU CPC product statistics;
   - Search Promo products;
   - Search Promo products report;
   - Search Promo orders report;
   - expense stats.
2. Зафиксировать реальные поля payload и pagination/cursor.
3. Проверить, какие CPO отчёты возвращают денежные поля, а какие только атрибуцию заказов.
4. Проверить лимиты Ozon по датам и campaignIds на текущем кабинете.

## Рекомендация

Делать первый релиз как raw-only ingestion:

1. Performance credentials через `Marketplace`.
2. Новый `OzonPerformanceReportConnector` в `Ingestion`.
3. Все основные рекламные источники первого этапа: SKU CPC + Search Promo/CPO + expense control.
4. Daily/backfill команды только создают и ведут ingestion jobs.
5. Нормализация и влияние на отчеты - отдельный план и отдельный PR.
