# inventory-stocks-fbs: переключатель FBO / FBS в отчёте «Остатки» — план

> **Для агентов-исполнителей:** ОБЯЗАТЕЛЬНЫЙ ПОД-СКИЛЛ: superpowers:subagent-driven-development
> (рекомендуется) или superpowers:executing-plans — задача за задачей. Шаги с `- [ ]` для отметок.

**Цель:** в отчёте `/inventory/stocks` один переключатель FBO / FBS с одинаковым смыслом для
всех источников; для WB начать забирать остатки склада продавца.

**Архитектура:** схема вычисляется из существующего `fulfillment_type` одним SQL-выражением
(`fbs`/`rfbs` → FBS, остальное → FBO), и все три метода `InventoryStockReportQuery` получают
параметр схемы. WB FBS грузится новой фазой внутри `SyncWbInventorySnapshotHandler` (та же
сессия, тот же cron), raw-страницы нормализует новый `WbSellerWarehouseStocksRawNormalizer`,
баркоды и маппинг на листинги даёт новый метод `MarketplaceFacade::getListingBarcodes()`.

**Стек:** PHP 8.4, Symfony, Doctrine DBAL/ORM 3, Pagerfanta, Twig (легаси-слой, Tabler), PHPUnit.

**Спека:** `docs/tasks/inventory-stocks-fbs/TASK.md` — читать вместе с планом.

**Класс задачи:** Large (Messenger-обработчик, внешняя интеграция). Ветка
`feat/inventory-stocks-fbs-scheme`, один Draft PR. Коммиты — только с pathspec.

Baseline (снять до первого изменения, записать в ledger):
`docker compose run --rm -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Functional/Inventory/Controller/StockReportControllerTest.php tests/Integration/Inventory/MessageHandler/SyncWbInventorySnapshotHandlerTest.php tests/Unit/Inventory`
(перед functional/integration один раз `make site-test-prepare`).

## Глобальные ограничения

- `declare(strict_types=1)` в каждом файле; `private readonly`, только constructor injection.
- Query/Repository — всегда `string $companyId`; `Assert::uuid`.
- `flush()` — только в Action/Handler/Command (нормализатор и Query не пишут).
- Границы модулей: Inventory ходит в Marketplace только через `MarketplaceFacade`.
- Ответ WB в логи не писать; токен не логировать. Ошибки FBS-фазы — `warning`, не `error`.
- Параметр URL `scheme`, значения ровно `fbo` | `fbs`, по умолчанию `fbo`.
- Нормализованные FBS-строки WB: `fulfillment_type = 'fbs'`, `status = available`,
  `reserved_quantity = '0.000'`, `source_sku = баркод`, `location.external_id = 'seller:<warehouseId>'`,
  `location.code = 'WB-FBS-<warehouseId>'`, `location.type = seller_warehouse`.
- Контракт WB marketplace-api (проверить по OpenAPI при реализации):
  `GET https://marketplace-api.wildberries.ru/api/v3/warehouses` → `[{"id":int,"name":string,...}]`;
  `POST https://marketplace-api.wildberries.ru/api/v3/stocks/{warehouseId}`, тело `{"skus":[string]}`
  (≤ 1000), ответ `{"stocks":[{"sku":string,"amount":int}]}`; заголовок `Authorization: <api_key>`.
- Без миграций: новое значение `LocationType` хранится как строка без CHECK в БД.
- Коммиты через `git add <файлы>`; сообщение заканчивается строкой
  `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

## Фокус ревью

Случаи, которые спека подразумевает, но ни один тест стадии не покрывал бы без явного добавления
(тест на каждый добавлен в задачу-владельца):

1. Ozon-строка без `fulfillment_type` (NULL или пустая) — должна остаться в FBO, не пропасть
   из обоих режимов → Stage 1, Task 2.
2. У WB-токена нет доступа к «Маркетплейсу» (401/403 на `/api/v3/warehouses`) — сессия всё равно
   `completed`, FBW-строки нормализуются, в логе один `warning` → Stage 3, Task 6.
3. Повторная доставка сообщения после сбоя посреди FBS-фазы — FBW-страницы не перезапрашиваются,
   FBS-фаза не дублирует raw → Stage 3, Task 6.
4. У компании нет баркодов WB — ни одного запроса в marketplace-api → Stage 3, Task 6.
5. Эффективная дата внутри схемы: за сегодня есть только FBO-строки, вчера были FBS — режим FBS
   показывает вчера с подписью «показан ближайший» → Stage 1, Task 2.

---

## Stage 1: схема в Query и контроллере (backend)

Risk: MEDIUM
stage_base_commit: <записать перед 1.1>
Definition of Done:
- `GET /inventory/stocks?scheme=fbs` отдаёт только `fbs`/`rfbs`-строки, `scheme=fbo` (и без
  параметра) — остальное; эффективная дата и разбивка считаются внутри схемы.
- Разбивка возвращается пустой, когда внутри схемы один тип.
- Тесты контроллера зелёные, включая новые из этого Stage.
- Исключено: вёрстка переключателя (Stage 2), данные WB FBS (Stage 3).

### Task 1 (1.1): Enum `FulfillmentScheme`

**Файлы:**
- Создать: `site/src/Inventory/Enum/FulfillmentScheme.php`
- Тест: `site/tests/Unit/Inventory/Enum/InventoryEnumsTest.php`

**Интерфейсы:**
- Производит:
  ```php
  enum FulfillmentScheme: string
  {
      case Fbo = 'fbo';
      case Fbs = 'fbs';
      /** Типы склада, которые относятся к складу продавца. */
      public const FBS_TYPES = ['fbs', 'rfbs'];
      public function label(): string;              // 'FBO' | 'FBS'
      public static function fromFulfillmentType(?string $fulfillmentType): self;
  }
  ```
  `fromFulfillmentType`: `trim`+`mb_strtolower`, вход из `FBS_TYPES` → `Fbs`, всё остальное
  (включая `null`, `''`, `fbo`, `fbw`, `unknown`) → `Fbo`.

- [ ] **Шаг 1: тест**

```php
public function testFulfillmentSchemeFromFulfillmentType(): void
{
    self::assertSame(FulfillmentScheme::Fbs, FulfillmentScheme::fromFulfillmentType('fbs'));
    self::assertSame(FulfillmentScheme::Fbs, FulfillmentScheme::fromFulfillmentType(' RFBS '));
    self::assertSame(FulfillmentScheme::Fbo, FulfillmentScheme::fromFulfillmentType('fbo'));
    self::assertSame(FulfillmentScheme::Fbo, FulfillmentScheme::fromFulfillmentType('fbw'));
    self::assertSame(FulfillmentScheme::Fbo, FulfillmentScheme::fromFulfillmentType(null));
    self::assertSame(FulfillmentScheme::Fbo, FulfillmentScheme::fromFulfillmentType(''));
    self::assertSame(['fbo', 'fbs'], array_map(static fn (FulfillmentScheme $c): string => $c->value, FulfillmentScheme::cases()));
}
```

- [ ] **Шаг 2:** `docker compose run --rm -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Unit/Inventory/Enum/InventoryEnumsTest.php` — FAIL: класс не найден.
- [ ] **Шаг 3:** реализовать enum по интерфейсу выше.
- [ ] **Шаг 4:** тот же запуск — PASS.
- [ ] **Шаг 5:** `git add site/src/Inventory/Enum/FulfillmentScheme.php site/tests/Unit/Inventory/Enum/InventoryEnumsTest.php && git commit -m "feat(inventory): enum FulfillmentScheme — FBO/FBS из fulfillment_type"`

### Task 2 (1.2): схема в `InventoryStockReportQuery` и `StockReportController`

**Файлы:**
- Изменить: `site/src/Inventory/Infrastructure/Query/InventoryStockReportQuery.php`
- Изменить: `site/src/Inventory/Controller/StockReportController.php`
- Шаблон в этом Stage не трогать: параметр `scheme` живёт только в URL, переключатель — Stage 2.
- Тест: `site/tests/Functional/Inventory/Controller/StockReportControllerTest.php`

**Интерфейсы:**
- Потребляет: `FulfillmentScheme` (Task 1).
- Производит (сигнатуры, на которые опираются Stage 2 и ARCHITECTURE.md):
  ```php
  public function findEffectiveSnapshotDate(string $companyId, MarketplaceType $source, FulfillmentScheme $scheme, \DateTimeImmutable $date): ?\DateTimeImmutable;
  /** @return array<string, BreakdownColumn> — [] если внутри схемы ≤ 1 типа */
  public function getBreakdownColumns(string $companyId, MarketplaceType $source, FulfillmentScheme $scheme, \DateTimeImmutable $snapshotDate): array;
  public function getPage(string $companyId, int $page, int $perPage, MarketplaceType $source, FulfillmentScheme $scheme, \DateTimeImmutable $snapshotDate, array $breakdownColumns = []): Pagerfanta;
  ```
  `baseQueryBuilder(...)` и `findEffectiveSnapshotDate` добавляют условие
  ```sql
  (CASE WHEN LOWER(TRIM(COALESCE(s.fulfillment_type, ''))) IN (:fbsTypes) THEN 'fbs' ELSE 'fbo' END) = :scheme
  ```
  (`:fbsTypes` — `FulfillmentScheme::FBS_TYPES`, `ArrayParameterType::STRING`; `:scheme` — `$scheme->value`).
  Выражение вынести в один private-метод, чтобы оба места использовали одно и то же.
  Для Ozon в режиме FBS `breakdownLabel` даёт `FBS` / `RFBS` (уже так: `mb_strtoupper`).
  Контроллер: `parseScheme(mixed $raw): FulfillmentScheme` — строка из `FulfillmentScheme::tryFrom`,
  иначе `Fbo`; в `render()` добавить `'scheme' => $scheme, 'schemes' => FulfillmentScheme::cases()`.

- [ ] **Шаг 1: тесты.** Изменить `testRowsAreGroupedBySkuWithFulfillmentTypeBreakdown`: без `scheme`
  ожидается одна строка `SKU-ROLLUP` с `10.000 / 3.000 / 7.000` и заголовок **без** колонок
  `FBO`/`FBS` (внутри FBO один тип). Добавить:

```php
public function testFbsSchemeShowsSellerWarehouseTypesWithBreakdown(): void
{
    // Сид: один SKU, три строки одного дня: fbo 10/3, fbs 5/1, rfbs 2/0.
    // GET /inventory/stocks?scheme=fbs&date=<день>
    // thead: [..., 'Доступно расчётно', 'FBS', 'RFBS', 'mappingStatus']
    // одна строка: Остаток 7.000, Зарезервировано 1.000, Доступно 6.000, FBS 4.000, RFBS 2.000
}

public function testRowWithoutFulfillmentTypeStaysInFboScheme(): void
{
    // Сид: 'SKU-NOTYPE' с ->withFulfillmentType(null).
    // scheme=fbo → содержит 'SKU-NOTYPE'; scheme=fbs → не содержит и показывает «Нет остатков на выбранную дату».
}

public function testEffectiveDateIsResolvedWithinScheme(): void
{
    // Сид: daysAgo(1) только fbo 'SKU-FBO-TODAY'; daysAgo(2) fbs 'SKU-FBS-YESTERDAY'.
    // GET ?scheme=fbs → содержит 'SKU-FBS-YESTERDAY', не содержит 'SKU-FBO-TODAY',
    // содержит 'показан ближайший на '.daysAgo(2)->format('d.m.Y').
}
```
  В `brokenQueryProvider` добавить `yield 'unsupported scheme' => ['scheme=dbs'];` и
  `yield 'array scheme' => ['scheme[]=fbs'];` (ожидание — страница 200 и `SKU-LATEST` виден).

- [ ] **Шаг 2:** `docker compose run --rm -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Functional/Inventory/Controller/StockReportControllerTest.php` — FAIL (новые тесты и изменённый rollup).
- [ ] **Шаг 3:** реализовать сигнатуры и SQL выше; `getBreakdownColumns` возвращает `[]`, если
  `count($values) <= 1`.
- [ ] **Шаг 4:** тот же запуск — PASS. Также
  `docker compose run --rm site-php-cli vendor/bin/phpstan analyse src/Inventory/Infrastructure/Query/InventoryStockReportQuery.php src/Inventory/Controller/StockReportController.php` — без новых ошибок (кэш PHPStan бывает root-owned, см. память `phpstan-cache-root-owned`).
- [ ] **Шаг 5:** `git add site/src/Inventory/Infrastructure/Query/InventoryStockReportQuery.php site/src/Inventory/Controller/StockReportController.php site/tests/Functional/Inventory/Controller/StockReportControllerTest.php && git commit -m "feat(inventory): отчёт остатков фильтруется по схеме FBO/FBS (scheme=)"`

Stage checks: тесты 1.1–1.2; `make site-cs-check` по изменённым файлам.
Reviewer focus: строка без типа ни в одном режиме не теряется и ни в одном не дублируется;
`COUNT(DISTINCT …)` пагинации считается с фильтром схемы; IDOR-фильтр компании на месте.

## Stage 2: переключатель в шаблоне (frontend, Twig)

Risk: LOW
Definition of Done:
- В форме фильтров сегментированный переключатель «Схема» с двумя положениями, активное
  положение берётся из `scheme`; `Применить` отправляет его GET-формой; пагинация сохраняет
  `scheme` (партиал уже берёт `app.request.query.all`).
- Колонки разбивки выводятся только когда `breakdownColumns` непустой (из Stage 1 это уже так,
  шаблон менять не нужно — проверить).
- Исключено: смена вёрстки таблицы.

### Task 3 (2.1): переключатель FBO / FBS

**Файлы:**
- Изменить: `site/templates/inventory/stocks/index.html.twig`
- Тест: `site/tests/Functional/Inventory/Controller/StockReportControllerTest.php`

**Интерфейсы:** потребляет `scheme` (`FulfillmentScheme`) и `schemes` (`list<FulfillmentScheme>`) из контроллера (Task 2).

- [ ] **Шаг 1: тест**

```php
public function testSchemeToggleMarksActiveOptionAndKeepsItInPagination(): void
{
    // Сид: 31+ fbs-строк разных SKU за daysAgo(1), чтобы было 2 страницы (PER_PAGE = 30).
    // GET /inventory/stocks?scheme=fbs&date=<день>
    // input[name="scheme"][value="fbs"] имеет атрибут checked; [value="fbo"] — нет.
    // Ссылка на страницу 2 в .card-footer содержит 'scheme=fbs'.
}
```

- [ ] **Шаг 2:** запуск файла теста — FAIL (нет input[name=scheme]).
- [ ] **Шаг 3:** в карточке фильтров между «Источник» и «Остатки на дату» добавить колонку
  `col-md-3` с `<label class="form-label">Схема</label>` и Tabler-группой:
  ```twig
  <div class="btn-group w-100" role="group" aria-label="Схема">
      {% for item in schemes %}
          <input type="radio" class="btn-check" name="scheme" id="scheme-{{ item.value }}" value="{{ item.value }}" {{ scheme.value == item.value ? 'checked' : '' }}>
          <label class="btn" for="scheme-{{ item.value }}">{{ item.label() }}</label>
      {% endfor %}
  </div>
  ```
  Под переключателем одной строкой `text-muted small`: «FBO — склад маркетплейса, FBS — склад продавца».
- [ ] **Шаг 4:** запуск файла теста — PASS. Визуально: стенд по `local-visual-check` (память),
  скриншот обоих положений — в описание PR.
- [ ] **Шаг 5:** `git add site/templates/inventory/stocks/index.html.twig site/tests/Functional/Inventory/Controller/StockReportControllerTest.php && git commit -m "feat(inventory): переключатель FBO/FBS в фильтрах отчёта остатков"`

Stage checks: тест 2.1; twig lint `docker compose run --rm site-php-cli php bin/console lint:twig templates/inventory/stocks`.
Reviewer focus: переключатель не ломает ширину формы на телефоне; `Сбросить` возвращает FBO.

## Stage 3: WB — остатки склада продавца (FBS) в ночном синке

Risk: HIGH-LOCAL (внешняя интеграция, Messenger-обработчик; routing не меняется)
Definition of Done:
- Ночной прогон WB после FBW-страниц запрашивает склады продавца и остатки по баркодам,
  сохраняет raw, сессия `completed`; нормализация даёт строки `fbs` с локациями
  `seller_warehouse` и маппингом на листинг по баркоду.
- Сбой FBS-фазы (401/403/429/5xx/transport) не трогает FBW-результат: `warning`, `completed`.
- Повторная доставка не дублирует страницы; без баркодов — без запросов в marketplace-api.
- Тесты: unit клиента и нормализатора, интеграционные обработчика (3 новых), существующие
  обработчика обновлены.
- Исключено: новый cron, новое сообщение, ретраи FBS-фазы внутри сессии.

### Task 4 (3.1): клиент — склады продавца и остатки по баркодам

**Файлы:**
- Изменить: `site/src/Inventory/Infrastructure/Api/Wildberries/WbInventoryClient.php`
- Тест: `site/tests/Unit/Inventory/Infrastructure/Api/Wildberries/WbInventoryClientTest.php`

**Интерфейсы (производит):**
```php
public const SELLER_WAREHOUSES_ENDPOINT = '/api/v3/warehouses';
public const SELLER_STOCKS_ENDPOINT = '/api/v3/stocks';      // + '/{warehouseId}'
public const SELLER_STOCKS_CHUNK = 1000;
private const MARKETPLACE_BASE_URL = 'https://marketplace-api.wildberries.ru';

/** @return list<array{id: int, name: string}> — только элементы с числовым id; name пустой → 'WB seller warehouse <id>' */
public function fetchSellerWarehouses(string $apiKey): array;

/** @param list<string> $barcodes 1..1000  @return array<string, mixed> сырой ответ (ключ `stocks`) */
public function fetchSellerWarehouseStocks(string $apiKey, int $warehouseId, array $barcodes): array;
```
Исключения те же, что у `fetchStocks` (429 → `WbInventoryRateLimitException`, 400–403 →
`WbInventoryApiException`, 5xx/transport → `WbInventoryTemporaryApiException`); классификацию
статуса вынести в один private-метод и использовать из всех трёх публичных.
`$barcodes` пустой или > 1000 → `\InvalidArgumentException`.

- [ ] **Шаг 1: тесты** (стиль файла — `MockHttpClient` с callback):

```php
public function testFetchesSellerWarehousesWithExpectedRequest(): void
// GET https://marketplace-api.wildberries.ru/api/v3/warehouses, Authorization: test-token;
// ответ '[{"id":1,"name":"Склад А","officeId":15},{"id":2,"name":""},{"name":"без id"}]'
// → [['id'=>1,'name'=>'Склад А'], ['id'=>2,'name'=>'WB seller warehouse 2']]

public function testFetchesSellerWarehouseStocksWithExpectedRequest(): void
// POST https://marketplace-api.wildberries.ru/api/v3/stocks/7, тело {"skus":["b1","b2"]};
// ответ '{"stocks":[{"sku":"b1","amount":3}]}' → возвращается декодированный массив с ключом stocks

public function testSellerWarehouseStocksRejectsEmptyAndOversizedBarcodeLists(): void
// [] и array_fill(0, 1001, 'b') → \InvalidArgumentException, HTTP-запросов нет

#[DataProvider('permanentErrorProvider')] public function testSellerEndpointsPermanentHttpErrors(int $status): void
// 403 на fetchSellerWarehouses → WbInventoryApiException
```

- [ ] **Шаг 2:** `docker compose run --rm -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Unit/Inventory/Infrastructure/Api/Wildberries/WbInventoryClientTest.php` — FAIL.
- [ ] **Шаг 3:** реализовать; таймаут 120 с как у `fetchStocks`.
- [ ] **Шаг 4:** тот же запуск — PASS.
- [ ] **Шаг 5:** `git add site/src/Inventory/Infrastructure/Api/Wildberries/WbInventoryClient.php site/tests/Unit/Inventory/Infrastructure/Api/Wildberries/WbInventoryClientTest.php && git commit -m "feat(inventory): WbInventoryClient — склады продавца и остатки по баркодам"`

### Task 5 (3.2): `MarketplaceFacade::getListingBarcodes()`, `LocationType::SellerWarehouse`, нормализатор FBS

**Файлы:**
- Изменить: `site/src/Marketplace/Facade/MarketplaceFacade.php`
- Изменить: `site/src/Inventory/Enum/LocationType.php`
- Создать: `site/src/Inventory/Infrastructure/Normalizer/WbSellerWarehouseStocksRawNormalizer.php`
- Тесты: `site/tests/Unit/Inventory/Infrastructure/Normalizer/WbSellerWarehouseStocksRawNormalizerTest.php`,
  `site/tests/Unit/Inventory/Enum/InventoryEnumsTest.php`,
  `site/tests/Integration/Inventory/MessageHandler/SyncWbInventorySnapshotHandlerTest.php` (маппинг по баркоду проверяется там, Task 6)

**Интерфейсы (производит):**
```php
// MarketplaceFacade — DBAL через $this->connection, как findListingsByMarketplaceVariantIds
/**
 * Баркоды листингов компании по маркетплейсу, ключ — баркод.
 * @return array<string, array{listingId: string, marketplaceSku: string, marketplaceVariantId: ?string}>
 */
public function getListingBarcodes(string $companyId, string $marketplace): array;
// SELECT b.barcode, l.id, l.marketplace_sku, l.marketplace_variant_id
// FROM marketplace_listing_barcodes b JOIN marketplace_listings l ON l.id = b.listing_id
// WHERE b.company_id = :companyId AND l.marketplace = :marketplace

// LocationType
case SellerWarehouse = 'seller_warehouse';

// Нормализатор
final readonly class WbSellerWarehouseStocksRawNormalizer
{
    public const ENDPOINT_PREFIX = WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/';
    /** @param list<InventoryRawSnapshot> $rawSnapshots  @return list<NormalizedStockRow> */
    public function normalize(array $rawSnapshots): array;
}
```
Нормализатор берёт только raw с `str_starts_with(getSourceEndpoint(), ENDPOINT_PREFIX)`;
`warehouseId` и `warehouseName` — из `getRequestParams()`; по каждому `stocks[]` с непустым `sku`:
`NormalizedStockRow(source: WILDBERRIES, sourceSku: sku, sourceOfferId: null, fulfillmentType: 'fbs',
status: Available, quantity: number_format(max(0, amount), 3, '.', ''), reservedQuantity: '0.000',
rawSnapshotId, locationExternalId: 'seller:'.warehouseId, locationCode: 'WB-FBS-'.warehouseId,
locationName: warehouseName)`. Элемент без `sku` или raw без `warehouseId` пропускается.

- [ ] **Шаг 1: тесты**

```php
// WbSellerWarehouseStocksRawNormalizerTest
public function testBuildsFbsRowsFromSellerStockPagesAndSkipsOtherEndpoints(): void
// raw A: endpoint '/api/v3/stocks/7', requestParams ['warehouseId'=>7,'warehouseName'=>'Мой склад'],
//        body ['stocks'=>[['sku'=>'b1','amount'=>3],['sku'=>'b2','amount'=>0],['amount'=>9]]]
// raw B: endpoint WbInventoryClient::ENDPOINT, body ['data'=>['items'=>[...]]]
// → 2 строки; b1: '3.000', 'fbs', Available, 'seller:7', 'WB-FBS-7', 'Мой склад', reserved '0.000', sourceOfferId null

// InventoryEnumsTest::testLocationTypeHasExpectedValuesCount — добавить 'seller_warehouse' в конец списка
```

- [ ] **Шаг 2:** запуск обоих unit-файлов — FAIL.
- [ ] **Шаг 3:** реализовать три единицы выше.
- [ ] **Шаг 4:** запуск — PASS.
- [ ] **Шаг 5:** `git add site/src/Marketplace/Facade/MarketplaceFacade.php site/src/Inventory/Enum/LocationType.php site/src/Inventory/Infrastructure/Normalizer/WbSellerWarehouseStocksRawNormalizer.php site/tests/Unit/Inventory/Infrastructure/Normalizer/WbSellerWarehouseStocksRawNormalizerTest.php site/tests/Unit/Inventory/Enum/InventoryEnumsTest.php && git commit -m "feat(inventory): нормализатор остатков склада продавца WB, LocationType::SellerWarehouse, баркоды через Facade"`

### Task 6 (3.3): FBS-фаза в `SyncWbInventorySnapshotHandler` и нормализация в Action

**Файлы:**
- Изменить: `site/src/Inventory/MessageHandler/SyncWbInventorySnapshotHandler.php`
- Изменить: `site/src/Inventory/Application/NormalizeInventorySnapshotAction.php`
- Тест: `site/tests/Integration/Inventory/MessageHandler/SyncWbInventorySnapshotHandlerTest.php`

**Интерфейсы:** потребляет Task 4 и Task 5. Поведение обработчика:

1. Цикл возобновления по сохранённым raw учитывает только страницы с
   `getSourceEndpoint() === WbInventoryClient::ENDPOINT` (FBW); страницы FBS-фазы считаются
   отдельно: `hasSellerStockPages = any(str_starts_with(endpoint, WbSellerWarehouseStocksRawNormalizer::ENDPOINT_PREFIX))`.
2. После FBW-цикла, до `markCompleted()`: если `!hasSellerStockPages` → private `fetchSellerStockPages()`:
   - `$barcodes = array_keys($this->marketplaceFacade->getListingBarcodes($companyId, 'wildberries'))`;
     пусто → `logger->info('WB seller warehouse stocks skipped: no barcodes.', [companyId, sessionId])`, выход.
   - `$warehouses = $this->inventoryClient->fetchSellerWarehouses($apiKey)`; для каждого склада и
     каждого `array_chunk($barcodes, WbInventoryClient::SELLER_STOCKS_CHUNK)`: запрос, затем
     `InventoryRawSnapshot(sourceEndpoint: WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/'.$id,
     requestParams: [connectionId, marketplace, warehouseId, warehouseName, chunk (с 1), chunkSize,
     requestedAt, correlationId], responseStatus: 200, responseBody: ответ, fetchedAt: now,
     fetchDurationMs, correlationId, pageNumber: продолжение счётчика $page)`, `persist`,
     `incrementReceivedPages()`, `flush()` на страницу (как у FBW).
   - `catch (WbInventoryRateLimitException|WbInventoryTemporaryApiException|WbInventoryApiException $e)` →
     `logger->warning('WB seller warehouse stocks skipped.', [companyId, sessionId, exceptionClass, errorMessage, savedSellerPages])`, выход из фазы.
     Комментарий у catch: `// ponytail: сбой FBS-фазы в этой сессии не повторяется, следующая ночь — новая попытка; ретраи внутри сессии — когда появится потребность.`
3. Дальше — как сейчас: `markCompleted()`, `dispatchNormalization()`.

Поведение Action (`normalizeWildberries`): `$rows = [...fbw, ...$this->wbSellerRawNormalizer->normalize($rawSnapshots)]`;
для строк с `fulfillmentType === 'fbs'` маппинг по баркоду через
`getListingBarcodes($companyId, 'wildberries')[$row->sourceSku]` → `listingId`, `sourceOfferId = marketplaceSku`,
`mappingStatus = Mapped` (нет в карте → `Unmapped`, `sourceOfferId = null`); локация создаётся с
`LocationType::SellerWarehouse`. Для FBW-строк — без изменений. Новый конструкторный аргумент
`private WbSellerWarehouseStocksRawNormalizer $wbSellerRawNormalizer` (autowire).

- [ ] **Шаг 1: тесты.** Добавить хелперы `sellerWarehousesResponse(array $warehouses): MockResponse`
  (`[{"id":..,"name":..}]`) и `sellerStocksResponse(array $stocks): MockResponse` (`{"stocks":[...]}`).
  В каждом существующем тесте, где сессия доходит до `completed`, после `inventoryResponse(...)`
  добавить `$this->sellerWarehousesResponse([])` (запрос складов уходит, складов нет — FBS-страниц нет).
  Новые тесты:

```php
public function testSellerWarehouseStocksAreFetchedAndNormalizedAsFbs(): void
// catalog: card(100, [[1001,'S','barcode-s']]); catalog trash []; inventory [stock(100,1001,507,4)];
// sellerWarehousesResponse([['id'=>7,'name'=>'Мой склад']]); sellerStocksResponse([['sku'=>'barcode-s','amount'=>3]])
// handler → Completed; normalize → строки:
//   fbw: source_sku '1001', quantity '4.000'
//   fbs: source_sku 'barcode-s', source_offer_id '100', quantity '3.000', fulfillment_type 'fbs',
//        listing_id = тот же, что у fbw-строки, mapping_status 'mapped',
//        location: type 'seller_warehouse', external_id 'seller:7', code 'WB-FBS-7', name 'Мой склад'
// raw: ровно одна страница с source_endpoint '/api/v3/stocks/7', request_params.chunk = 1

public function testSellerWarehouseAccessDeniedKeepsFbwResultAndCompletesSession(): void
// ... inventory [stock(...)]; new MockResponse('', ['http_code' => 403])
// → Completed, нормализация даёт fbw-строки, fbs-строк 0, raw с '/api/v3/stocks' нет

public function testNoBarcodesMeansNoMarketplaceApiRequests(): void
// catalogResponse([]) дважды (баркодов у компании нет), inventoryResponse([]) — пустая FBW-страница.
// http_client — MockHttpClient с callback: self::assertStringNotContainsString('marketplace-api', $url)
// на каждом запросе (исчерпание списка ответов нельзя использовать как проверку: оно ловится
// как transport → warning, и тест был бы зелёным зря). → Completed, raw с '/api/v3/stocks' нет.

public function testRedeliveryAfterSellerPhaseDoesNotDuplicateSellerPages(): void
// Подготовить сессию in_progress с сохранённой FBW-страницей (< pageLimit) и одной FBS-страницей
// (InventoryRawSnapshotBuilder ->withEndpoint('/api/v3/stocks/7') ->withRequestParams([...])).
// swapHttpClient([]) — любой запрос = провал. handler → Completed, raw-страниц по-прежнему 2.
```

- [ ] **Шаг 2:** `docker compose run --rm -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Inventory/MessageHandler/SyncWbInventorySnapshotHandlerTest.php` — FAIL на новых.
- [ ] **Шаг 3:** реализовать поведение 1–3 и изменения Action.
- [ ] **Шаг 4:** тот же запуск — PASS; плюс
  `tests/Integration/Inventory/MessageHandler/NormalizeInventorySnapshotHandlerTest.php`,
  `tests/Integration/Inventory/Application/NormalizeInventorySnapshotActionTest.php`,
  `tests/Integration/Inventory/Command/RenormalizeInventorySnapshotsCommandTest.php` — PASS (новый аргумент конструктора autowire'ится).
- [ ] **Шаг 5:** `git add site/src/Inventory/MessageHandler/SyncWbInventorySnapshotHandler.php site/src/Inventory/Application/NormalizeInventorySnapshotAction.php site/tests/Integration/Inventory/MessageHandler/SyncWbInventorySnapshotHandlerTest.php && git commit -m "feat(inventory): фаза FBS в ночном синке WB — остатки склада продавца в снимке"`

Stage checks: `composer test:unit` по `tests/Unit/Inventory`, интеграционные Inventory
(`php bin/phpunit tests/Integration/Inventory`), `make site-stan` (baseline не растёт),
`make site-cs-check`.
Reviewer focus: токен и тело ответа не попадают в лог; `flush()` только в обработчике/Action;
порядок `pageNumber` монотонный; `Location` для `seller:<id>` не пересекается с FBW-складом
с тем же числовым id; `StockQtyByListingOnDateQuery` теперь включает FBS WB — осознанно (TASK.md).

## Stage 4: документация и handoff

Risk: LOW
Definition of Done:
- `ARCHITECTURE.md`: раздел `InventoryStockReportQuery` (параметр схемы, пустая разбивка при одном
  типе, SQL-выражение схемы), блок `LocationType` (новое значение), раздел
  «Inventory — Wildberries FBW stock normalization» дополнен подразделом «FBS (склад продавца)»
  со схемой фазы и семантикой полей из TASK.md, `MarketplaceFacade::getListingBarcodes()` в
  списке методов Facade, строка Changelog `2.150 | 2026-11-06 | Inventory: …`.
- `docs/tasks/inventory-stocks-fbs/handoff.md` по шаблону `docs/workflow/templates.md`.
- Полные гейты: `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`,
  `make site-test-unit`, `make site-test` (затронуты integration-пути).
- PR Ready, один вопрос Владельцу: «merge and deploy #N».

### Task 7 (4.1): ARCHITECTURE.md и handoff

- [ ] **Шаг 1:** внести правки по DoD (текущее состояние — в разделы, история — в Changelog).
- [ ] **Шаг 2:** `grep -n "FulfillmentScheme\|getListingBarcodes\|seller_warehouse" ARCHITECTURE.md` — все три есть.
- [ ] **Шаг 3:** прогнать полные гейты, вывод — в handoff.md.
- [ ] **Шаг 4:** `git add ARCHITECTURE.md docs/tasks/inventory-stocks-fbs && git commit -m "docs(inventory): схема FBO/FBS в отчёте остатков и фаза FBS WB — ARCHITECTURE, handoff"`
- [ ] **Шаг 5:** push, PR Ready, вопрос «merge and deploy #N». После деплоя — приёмка по TASK.md
  (первая ночь: `warning` FBS-фазы по кабинетам, `?source=wildberries&scheme=fbs`).
