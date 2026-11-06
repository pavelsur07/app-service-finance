# Отчёт «Оборачиваемость» — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Страница `/marketplace-analytics/stock-turnover`: по каждому листингу с остатком — дней запаса, деньги в товаре, корзина; сводка «всего / заморожено»; экспорт xlsx.

**Architecture:** Новый `StockTurnoverQuery` в MarketplaceAnalytics собирает строки из трёх Facade (остатки — Inventory, продажи и себестоимость — Marketplace) в PHP; классификация — чистая `StockTurnoverPolicy`; UI — Twig в легаси-слое по DS с Pagerfanta `ArrayAdapter`; экспорт — OpenSpout по образцу `UnitExtendedXlsxExporter`. Inventory получает один необязательный параметр схемы в существующем запросе остатков.

**Tech Stack:** PHP 8.4, Symfony, DBAL, Pagerfanta, Twig (Tabler + DS `vf-*`), OpenSpout 4, PHPUnit 11.

**Spec:** `docs/tasks/stock-turnover-report/TASK.md`

## Global Constraints

- `declare(strict_types=1)`; `final readonly class` для DTO/Policy/Query без состояния; `final class` для Controller/Exporter; `enum` без `final`.
- IDOR: каждый вызов Facade/Query получает `string $companyId` из `ActiveCompanyService`.
- Границы модулей: MarketplaceAnalytics импортирует только `App\Inventory\Facade\*`, `App\Marketplace\Facade\*`, `App\Marketplace\DTO\*`, `App\Inventory\Application\DTO\*`, `App\Inventory\Enum\FulfillmentScheme`; никакого SQL по чужим таблицам из MarketplaceAnalytics.
- Деньги в Twig — `vf_money`, количества — `vf_number` (0 знаков); числа `text-end vf-num`; без хардкода цветов.
- Query возвращает DTO, не `array`; Pagerfanta, `perPage = 50`.
- Доступ `ROLE_COMPANY_USER`; маршруты `marketplace_analytics_stock_turnover_index` и `marketplace_analytics_stock_turnover_export`.
- Корзины: `<30` / `30 ≤ d < 60` / `60 ≤ d ≤ 90` / `> 90` / «нет продаж»; `period ∈ {30, 60, 90}`, по умолчанию 30.
- Коммиты с pathspec, trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`; ARCHITECTURE.md обновляется в той же задаче (Changelog 2.152).

## Review Focus

1. Источник со снимком старше 2 дней: его остатков нет в строках и суммах, под сводкой написано «остатки WB устарели, последний снимок …» — не молча частичный отчёт. (Task 3 — `testStaleSourceIsExcludedAndReported`; Task 4 — баннер.)
2. Листинг с остатком без себестоимости: в строках с «—», в счётчике «без себестоимости», не в «всего ₽». (Task 3 — `testListingWithoutCostPriceIsCountedSeparately`.)
3. Листинг с продажами, но нулевым/отсутствующим остатком — не в строках и не в сводке. (Task 3 — `testListingWithoutStockIsExcluded`.)
4. Ровно 30, 60, 90 дней запаса попадают в «30–60», «60–90», «60–90». (Task 2 — `testBucketBoundaries`.)
5. `scheme=fbs` не подмешивает FBO-остатки и наоборот; без FBS-строк — пустой результат без ошибки. (Task 1 — `testSchemeFilterSeparatesFboAndFbs`.)

---

## Stage 1: данные и расчёт

### Task 1 (1.1): параметр схемы в остатках на дату

**Files:**
- Modify: `site/src/Inventory/Infrastructure/Query/StockQtyByListingOnDateQuery.php` (`execute()`)
- Modify: `site/src/Inventory/Facade/InventoryFacade.php` (`getStockQtyByListingOnReportDate()`)
- Modify: `ARCHITECTURE.md` (раздел `InventoryFacade`, `StockQtyByListingOnDateQuery`)
- Test: `site/tests/Integration/Inventory/Infrastructure/Query/StockQtyByListingOnDateSchemeTest.php`

**Interfaces:**
- Consumes: `FulfillmentScheme::FBS_TYPES`, тот же CASE-выражение, что в `InventoryStockReportQuery::applySchemeFilter()`.
- Produces: `InventoryFacade::getStockQtyByListingOnReportDate(string $companyId, \DateTimeImmutable $reportDate, ?string $marketplace = null, ?FulfillmentScheme $scheme = null): StockOnDateResult`; `StockQtyByListingOnDateQuery::execute(string $companyId, \DateTimeImmutable $reportDate, ?string $marketplace = null, ?FulfillmentScheme $scheme = null): StockOnDateResult`. `null` — обе схемы, как раньше.

- [ ] **Step 1: Write the failing test** (по образцу `StockQtyByListingOnDateFreshnessTest::persistSnapshot()`, добавить `->withFulfillmentType()`): одна сессия Ozon на вчера, листинг A — `fbo` 10 и `fbs` 4, листинг B — только `rfbs` 3.

```php
public function testSchemeFilterSeparatesFboAndFbs(): void
{
    // без схемы — как раньше
    self::assertSame([A => 14.0, B => 3.0], $this->query->execute(COMPANY, $date)->qtyByListingId);
    self::assertSame([A => 10.0], $this->query->execute(COMPANY, $date, null, FulfillmentScheme::Fbo)->qtyByListingId);
    self::assertSame([A => 4.0, B => 3.0], $this->query->execute(COMPANY, $date, null, FulfillmentScheme::Fbs)->qtyByListingId);
}

public function testSchemeWithoutRowsGivesEmptyQtyButKeepsSnapshotDate(): void
{
    // листинг только fbo; scheme=fbs → qtyByListingId = [], snapshotDateBySource['ozon'] = дата сессии, staleSources = []
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Inventory/Infrastructure/Query/StockQtyByListingOnDateSchemeTest.php`
Expected: FAIL — `execute()` принимает 3 аргумента / ArgumentCountError.

- [ ] **Step 3: Implement** — в `execute()` четвёртый параметр; при `null !== $scheme` к условиям `LEFT JOIN … s` добавить `AND CASE WHEN LOWER(TRIM(COALESCE(s.fulfillment_type, ''))) IN (:fbsTypes) THEN 'fbs' ELSE 'fbo' END = :scheme` с параметрами `fbsTypes` (`ArrayParameterType::STRING`) и `scheme`. Фильтр только в JOIN, не в `latest_sessions`: сессия выбирается по источнику, иначе день без FBS-строк «откатился» бы на старую сессию. Facade пробрасывает параметр, docblock дополнить.

- [ ] **Step 4: Run tests** — тот же файл + `tests/Integration/Inventory/Infrastructure/Query/StockQtyByListingOnDateFreshnessTest.php` + `tests/Integration/MarketplaceAnalytics`. Expected: OK.

- [ ] **Step 5: ARCHITECTURE.md** — сигнатуры обоих методов с `?FulfillmentScheme $scheme = null`, фраза «фильтр схемы применяется к строкам сессии, не к выбору сессии».

- [ ] **Step 6: Commit** — `feat(inventory): фильтр схемы FBO/FBS в остатках на дату (InventoryFacade)`.

### Task 2 (1.2): корзины и политика расчёта

**Files:**
- Create: `site/src/MarketplaceAnalytics/Enum/TurnoverBucket.php`
- Create: `site/src/MarketplaceAnalytics/Domain/Service/StockTurnoverPolicy.php`
- Create: `site/src/MarketplaceAnalytics/DTO/TurnoverMeasure.php`
- Test: `site/tests/Unit/MarketplaceAnalytics/Domain/Service/StockTurnoverPolicyTest.php`, `site/tests/Unit/MarketplaceAnalytics/Enum/TurnoverBucketTest.php`

**Interfaces:**
- Produces:
  - `enum TurnoverBucket: string { case Fast='fast'; case Medium='medium'; case Slow='slow'; case Frozen='frozen'; case NoSales='no_sales'; public function label(): string; public function isFrozen(): bool; public static function fromDaysOfCover(?int $days): self; }` — подписи «до 30 дней», «30–60 дней», «60–90 дней», «свыше 90 дней», «нет продаж»; `isFrozen()` true для Frozen и NoSales.
  - `final readonly class TurnoverMeasure { public function __construct(public float $salesPerDay, public ?int $daysOfCover, public TurnoverBucket $bucket) {} }`
  - `final readonly class StockTurnoverPolicy { public function measure(int $stockQty, int $salesQty, int $periodDays): TurnoverMeasure; }` — `salesPerDay = round(salesQty / periodDays, 2)`; `daysOfCover = salesQty > 0 ? (int) floor(stockQty / (salesQty / periodDays)) : null`; `Assert::greaterThan($periodDays, 0)`.

- [ ] **Step 1: Write the failing tests**

```php
#[DataProvider('boundaries')] public function testBucketBoundaries(?int $days, TurnoverBucket $expected): void
// [null, NoSales], [0, Fast], [29, Fast], [30, Medium], [59, Medium], [60, Slow], [90, Slow], [91, Frozen], [400, Frozen]

public function testMeasureUsesPeriodAverage(): void
{   // 412 шт, 39 продаж за 30 дней → 1.3/день, 316 дней (412 / 1.3 = 316.9 → floor), Frozen
    $m = (new StockTurnoverPolicy())->measure(412, 39, 30);
    self::assertSame(1.3, $m->salesPerDay); self::assertSame(316, $m->daysOfCover); self::assertSame(TurnoverBucket::Frozen, $m->bucket);
}
public function testNoSalesGivesNullDaysAndNoSalesBucket(): void   // measure(5, 0, 30) → 0.0, null, NoSales
public function testZeroStockWithSalesGivesZeroDays(): void          // measure(0, 10, 30) → 0.33, 0, Fast
public function testLabelsAndFrozenFlag(): void                      // все подписи, isFrozen только у Frozen/NoSales
```

- [ ] **Step 2: Run** `… bin/phpunit tests/Unit/MarketplaceAnalytics/Domain/Service/StockTurnoverPolicyTest.php tests/Unit/MarketplaceAnalytics/Enum/TurnoverBucketTest.php` — Expected: FAIL (class not found).
- [ ] **Step 3: Implement** три файла по Interfaces; `daysOfCover` считать как `intdiv(stockQty * periodDays, salesQty)` — целочисленно, без float-дрейфа на границах.
- [ ] **Step 4: Run** те же тесты — Expected: OK.
- [ ] **Step 5: Commit** — `feat(marketplace-analytics): TurnoverBucket и StockTurnoverPolicy`.

### Task 3 (1.3): StockTurnoverQuery — сборка отчёта из Facade

**Files:**
- Create: `site/src/MarketplaceAnalytics/DTO/StockTurnoverFilter.php`, `StockTurnoverRow.php`, `StockTurnoverSummary.php`, `StockTurnoverReport.php`
- Create: `site/src/MarketplaceAnalytics/Infrastructure/Query/StockTurnoverQuery.php`
- Modify: `ARCHITECTURE.md` (раздел «Query — MarketplaceAnalytics»: новый подраздел, DTO)
- Test: `site/tests/Integration/MarketplaceAnalytics/StockTurnoverQueryTest.php`

**Interfaces:**
- Consumes: `InventoryFacade::getStockQtyByListingOnReportDate(companyId, date, source, scheme)` (Task 1); `MarketplaceFacade::getSalesAggregatesByListing(companyId, ?marketplace, from, to): array<listingId, ListingSalesAggregateDTO{quantity:int}>`; `MarketplaceFacade::getCostPricesForListingsOnDate(companyId, listingIds, date): array<listingId, string>` (нули уже отфильтрованы); `MarketplaceFacade::getListingsMetaByIds(companyId, ids): array<id, ListingMetaDTO{title, sku, marketplace, supplierSku}>`; `ListingTagFacade::listingIdsByTags(companyId, tagIds, false): list<string>`; `StockTurnoverPolicy::measure()` (Task 2).
- Produces:
  - `final readonly class StockTurnoverFilter { public function __construct(public string $companyId, public \DateTimeImmutable $date, public int $periodDays, public ?string $source, public ?FulfillmentScheme $scheme, public ?TurnoverBucket $bucket, /** @var list<string> */ public array $tagIds) {} public function salesFrom(): \DateTimeImmutable; // date − (periodDays − 1) }`
  - `final readonly class StockTurnoverRow { listingId: string, title: ?string, sku: string, supplierSku: ?string, marketplace: string, stockQty: int, salesQty: int, salesPerDay: float, daysOfCover: ?int, costPrice: ?string, stockValue: ?string, bucket: TurnoverBucket }`
  - `final readonly class StockTurnoverSummary { totalValue: string, frozenValue: string, frozenShare: ?float /* проценты, null при totalValue 0 */, /** @var array<string, string> bucket value => ₽ */ valueByBucket: array, listingsWithoutCostPrice: int, qtyWithoutCostPrice: int, listingsTotal: int, /** @var array<string,string> */ snapshotDateBySource: array, /** @var array<string,string> */ staleSources: array }`
  - `final readonly class StockTurnoverReport { /** @var list<StockTurnoverRow> */ public array $rows; public StockTurnoverSummary $summary; }`
  - `final readonly class StockTurnoverQuery { public function execute(StockTurnoverFilter $filter): StockTurnoverReport; }` — строки: листинги с `stockQty > 0` (после `(int) round`), при `tagIds` — пересечение с `listingIdsByTags`; `bucket`-фильтр применяется к `rows`, `summary` считается до него; сортировка `stockValue` DESC (bcmul scale 2, сравнение `bccomp`), строки без себестоимости после всех, внутри группы — по `title`; `valueByBucket` содержит все пять корзин (нули — `'0.00'`).

- [ ] **Step 1: Write the failing test** — сид: компания, 4 листинга Ozon (+ `Product` и `ProductPurchasePrice` для трёх), сессия снимков на `date` (вчера), продажи за последние 30 дней.

```php
// A: остаток 412, продаж 39, цена 1800.00 → Frozen, value 741600.00
// B: остаток 48, продаж 186, цена 600.00  → Fast (7 дней), value 28800.00
// C: остаток 20, продаж 0, цена 100.00    → NoSales, value 2000.00
// D: остаток 15, продаж 3, без цены       → строка есть, costPrice null, stockValue null; в листингах без цены 1 / 15 шт
// E: продаж 50, остатка нет               → строки нет
public function testRowsBucketsAndSummary(): void
{
    $report = $this->query->execute(new StockTurnoverFilter(COMPANY, $date, 30, null, null, null, []));
    self::assertSame(['A', 'B', 'C', 'D'], array_column($report->rows, 'listingId'));      // по деньгам, без цены — последним
    self::assertSame('772400.00', $report->summary->totalValue);
    self::assertSame('743600.00', $report->summary->frozenValue);                           // A + C
    self::assertEqualsWithDelta(96.27, $report->summary->frozenShare, 0.01);
    self::assertSame('28800.00', $report->summary->valueByBucket['fast']);
    self::assertSame('0.00', $report->summary->valueByBucket['medium']);
    self::assertSame(1, $report->summary->listingsWithoutCostPrice); self::assertSame(15, $report->summary->qtyWithoutCostPrice);
    self::assertSame(4, $report->summary->listingsTotal);
}
public function testBucketFilterNarrowsRowsButNotSummary(): void   // bucket=Frozen → rows [A], summary.totalValue тот же
public function testListingWithoutStockIsExcluded(): void          // E ни в rows, ни в listingsTotal
public function testListingWithoutCostPriceIsCountedSeparately(): void
public function testTagFilterKeepsOnlyTaggedListings(): void       // тег на B → rows [B], summary по B
public function testStaleSourceIsExcludedAndReported(): void       // снимок 5 дней назад → rows [], staleSources['ozon'] = дата
public function testSchemeFbsUsesOnlySellerWarehouseRows(): void   // A: fbo 400 + fbs 12 → scheme=Fbs: stockQty 12
```

- [ ] **Step 2: Run** `… bin/phpunit tests/Integration/MarketplaceAnalytics/StockTurnoverQueryTest.php` — Expected: FAIL (class not found).
- [ ] **Step 3: Implement** DTO и Query по Interfaces. Себестоимость запрашивать одним вызовом по всем листингам с остатком; продажи — одним вызовом за период; мета — одним вызовом. Листинг без меты (удалён) — `sku = '—'`, `title = null`, в отчёте остаётся.
- [ ] **Step 4: Run** тот же файл — Expected: OK. Затем `make site-stan`.
- [ ] **Step 5: ARCHITECTURE.md** — подраздел `StockTurnoverQuery` (источники, правила сортировки, что сводка не зависит от фильтра корзины), DTO, `TurnoverBucket`, `StockTurnoverPolicy`.
- [ ] **Step 6: Commit** — `feat(marketplace-analytics): StockTurnoverQuery — строки, корзины и сводка оборачиваемости`.

## Stage 2: страница

### Task 4 (2.1): контроллер, шаблон, вкладка

**Files:**
- Create: `site/src/MarketplaceAnalytics/Controller/StockTurnoverIndexController.php`
- Create: `site/src/MarketplaceAnalytics/Application/StockTurnoverFilterParser.php`
- Create: `site/templates/marketplace_analytics/stock_turnover/index.html.twig`
- Modify: `site/templates/marketplace_analytics/_tabs.html.twig` (вкладка «Оборачиваемость», `activeTab == 'stock_turnover'`)
- Modify: `ARCHITECTURE.md` (маршруты модуля)
- Test: `site/tests/Functional/MarketplaceAnalytics/StockTurnoverControllerTest.php`

**Interfaces:**
- Consumes: `StockTurnoverQuery::execute(StockTurnoverFilter)` (Task 3), `ListingTagFacade::list(companyId): list<ListingTagDTO{id,name}>`, `partials/_pagination.html.twig` с `pager`.
- Produces: маршрут `GET /marketplace-analytics/stock-turnover` (`marketplace_analytics_stock_turnover_index`); render-переменные `pager` (Pagerfanta `ArrayAdapter` по `report->rows`, 50 на страницу), `summary`, `filter`, `periods` ([30, 60, 90]), `sources`, `schemes` (`FulfillmentScheme::cases()`), `buckets` (`TurnoverBucket::cases()`), `tags`; `final readonly class StockTurnoverFilterParser { public function parse(Request $request, string $companyId): StockTurnoverFilter; }` (общий для страницы и экспорта) — `period` не из {30,60,90} → 30; `source` не из `MarketplaceType` → null; `scheme` не `fbo|fbs` → null; `bucket` не из enum → null; `date` не `Y-m-d` или в будущем → сегодня; `tags[]` — только валидные UUID.

Экран (DS: карточка фильтров → карточка сводки → таблица `vf-table`):

```
[Период продаж: 30|60|90] [Источник ▾] [Схема: все|FBO|FBS] [Дата] [Корзина ▾] [Теги ▾] [Применить] [Сбросить] [Excel]
В товаре 4 820 000 ₽   Заморожено 1 310 000 ₽ · 27 %   без себестоимости: 3 листинга, 120 шт
до 30 дней 1 900 000 · 30–60 980 000 · 60–90 630 000 · свыше 90 940 000 · нет продаж 370 000
Остатки: Ozon на 05.11.2026, WB на 05.11.2026 | «остатки WB устарели, последний снимок 01.11.2026»
Товар | Источник | Остаток | Продано | В день | Дней запаса | Себестоимость | В деньгах | Корзина
```
Бейдж корзины: Fast `bg-green-lt`, Medium `bg-azure-lt`, Slow `bg-yellow-lt`, Frozen `bg-red-lt`, NoSales `bg-secondary-lt`. «Дней запаса» null → «—». Деньги `vf_money`, количества `vf_number`, «в день» `vf_number(2)`. Пустое состояние: «Остатков на эту дату нет. Проверьте дату или загрузки остатков».

- [ ] **Step 1: Write the failing tests** (сид как в Task 3 через те же Builder'ы; `loginUser` + `active_company_id` как в `UnitExtendedTagFilterControllerTest`):

```php
public function testPageRendersRowsSortedByValueAndSummary(): void
// 200; tbody tr = 4; первая строка содержит «A»; .vf-turnover-summary содержит «772 400 ₽» и «96 %»; есть баннер «Остатки: Ozon на dd.mm.yyyy»
#[DataProvider('invalidParams')] public function testInvalidParamsFallBackToDefaults(string $query): void
// 'period=7', 'scheme=dbs', 'bucket=zzz', 'date=31-12-2026', 'source=amazon', 'tags[]=not-a-uuid' → 200, period radio 30 checked, 4 строки
public function testBucketFilterKeepsSummaryAndNarrowsRows(): void  // ?bucket=frozen → 1 строка, сводка «772 400 ₽»
public function testPaginationUsesFiftyRowsAndKeepsFilters(): void  // 51 листинг с остатком → 50 строк, ссылка на page=2 содержит period/bucket
public function testStaleSourceBannerIsShown(): void                 // снимок 5 дней назад → «остатки Ozon устарели», tbody пустое состояние
public function testTabIsPresentInAnalyticsTabs(): void             // ссылка на маршрут в _tabs на странице снапшотов
```

- [ ] **Step 2: Run** `… bin/phpunit tests/Functional/MarketplaceAnalytics/StockTurnoverControllerTest.php` — Expected: FAIL (404).
- [ ] **Step 3: Implement** контроллер, шаблон, вкладку. Контроллер только парсит запрос, зовёт Query, пагинирует, рендерит.
- [ ] **Step 4: Run** те же тесты — Expected: OK. `make site-stan`, `make site-cs-check`.
- [ ] **Step 5: Скриншоты** на локальном стенде (данные досеять SQL-ом, как в задаче `inventory-stocks-table-format`): общий вид, фильтр корзины «свыше 90», устаревший источник. Снести сид после.
- [ ] **Step 6: Commit** — `feat(marketplace-analytics): страница «Оборачиваемость»`.

## Stage 3: экспорт и закрытие

### Task 5 (3.1): экспорт в Excel

**Files:**
- Create: `site/src/MarketplaceAnalytics/Infrastructure/Export/StockTurnoverXlsxExporter.php`
- Create: `site/src/MarketplaceAnalytics/Controller/StockTurnoverExportController.php`
- Modify: `site/templates/marketplace_analytics/stock_turnover/index.html.twig` (кнопка «Excel» с текущими параметрами)
- Modify: `ARCHITECTURE.md`
- Test: `site/tests/Functional/MarketplaceAnalytics/StockTurnoverExportControllerTest.php`

**Interfaces:**
- Consumes: `StockTurnoverQuery`, `StockTurnoverFilterParser::parse()` (Task 4), `ReportsApiRateLimiter`, `OpenSpout\Writer\XLSX\Writer` как в `UnitExtendedXlsxExporter`.
- Produces: `GET /marketplace-analytics/stock-turnover/export.xlsx` (`marketplace_analytics_stock_turnover_export`), `StreamedResponse` с `Content-Disposition: attachment; filename="stock-turnover-<date>-<period>d.xlsx"`; `StockTurnoverXlsxExporter::export(StockTurnoverReport $report, string $outputPath): void` — лист 1 строки: `Листинг ID, Товар, SKU, Артикул продавца, Источник, Остаток, Продано за период, Продаж в день, Дней запаса, Себестоимость, В деньгах, Корзина`; лист 2 «Сводка» — всего, заморожено, доля, по корзинам, без себестоимости.

- [ ] **Step 1: Write the failing test**

```php
public function testExportReturnsXlsxWithHeaderAndRows(): void
// 200, Content-Type xlsx, filename содержит дату; файл читается OpenSpout Reader: первая строка — заголовки, строк данных 4, у A в колонке «Корзина» «свыше 90 дней», у D «В деньгах» пусто
public function testExportRespectsBucketFilter(): void   // ?bucket=frozen → 1 строка данных
public function testExportRequiresAuthentication(): void  // без логина — редирект на вход, как testReturnsErrorWhenNotAuthenticated в UnitExtendedExportControllerTest
```

- [ ] **Step 2: Run** — Expected: FAIL (404).
- [ ] **Step 3: Implement** экспортер, контроллер, кнопку.
- [ ] **Step 4: Run** functional MarketplaceAnalytics целиком — Expected: OK. `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`.
- [ ] **Step 5: ARCHITECTURE.md** — маршрут экспорта, Changelog `2.152 | 2026-11-06 | MarketplaceAnalytics: отчёт «Оборачиваемость» …`.
- [ ] **Step 6: Commit** — `feat(marketplace-analytics): экспорт отчёта «Оборачиваемость» в xlsx`.

### Task 6 (3.2): документы задачи и handoff

**Files:**
- Create: `docs/tasks/stock-turnover-report/checkpoint.md`, `stages/stage-1.md`, `stages/stage-2.md`, `stages/stage-3.md`, `handoff.md` (шаблоны — `docs/workflow/templates.md`)

- [ ] **Step 1:** заполнить отчёты Stage по чек-листу `docs/workflow/stage-report.md` (+ `stage-report-frontend.md` для Stage 2), handoff — гейты с цифрами, скриншоты, решения по умолчанию из TASK.md «Данные и правила расчёта» (возвраты не вычитаются, товар в пути не считается).
- [ ] **Step 2:** внешнее ревью `site/bin/external-review.sh master --effort high --context docs/tasks/stock-turnover-report/handoff.md`; замечания — чинить с тестом RED→GREEN, фиксировать в stage-3.
- [ ] **Step 3: Commit** — `docs(marketplace-analytics): задача stock-turnover-report — отчёты этапов и handoff`; push, PR «feat(marketplace-analytics): отчёт «Оборачиваемость» — где заморожены деньги», Ready после зелёного CI.
