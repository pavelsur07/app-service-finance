### Stage 1: данные и расчёт — DONE

**Risk:** MEDIUM (новый Query только через Facade; правка SQL `StockQtyByListingOnDateQuery` в Inventory — необязательный параметр)
**Stage base commit:** `0947abe78`
**Work items:** 1.1 схема в остатках на дату, 1.2 корзины и политика, 1.3 `StockTurnoverQuery`

#### What was done
- `StockQtyByListingOnDateQuery` и `InventoryFacade::getStockQtyByListingOnReportDate()` — 4-й необязательный параметр `?FulfillmentScheme $scheme`;
  фильтр схемы применяется к строкам выбранной сессии (не к выбору сессии), чтобы день без FBS-строк не «откатывался» на старую сессию.
- `TurnoverBucket` (Fast/Medium/Slow/Frozen/NoSales, `fromDaysOfCover()`, `label()`, `isFrozen()`), `TurnoverMeasure`,
  `StockTurnoverPolicy::measure(stockQty, salesQty, periodDays)` — целочисленные дни запаса `intdiv(stock × period, sales)`.
- `StockTurnoverFilter`, `StockTurnoverRow`, `StockTurnoverSummary`, `StockTurnoverReport`; `StockTurnoverQuery::execute()` собирает отчёт
  из `InventoryFacade`, `MarketplaceFacade` (продажи, себестоимость листинга), `ListingTagFacade`; сортировка по деньгам, сводка до фильтра корзины.
- `ARCHITECTURE.md`: сигнатура Facade, блок `TurnoverBucket`, раздел `StockTurnoverQuery`.

#### Files changed
- `site/src/Inventory/Infrastructure/Query/StockQtyByListingOnDateQuery.php`, `site/src/Inventory/Facade/InventoryFacade.php` — modified
- `site/src/MarketplaceAnalytics/Enum/TurnoverBucket.php`, `DTO/TurnoverMeasure.php`, `DTO/StockTurnoverFilter.php`, `DTO/StockTurnoverRow.php`,
  `DTO/StockTurnoverSummary.php`, `DTO/StockTurnoverReport.php`, `Domain/Service/StockTurnoverPolicy.php`,
  `Infrastructure/Query/StockTurnoverQuery.php` — new
- `site/config/services_test.yaml` — `StockTurnoverQuery` public для integration-теста
- tests: `StockQtyByListingOnDateSchemeTest` (new, 2), `TurnoverBucketTest` (new), `StockTurnoverPolicyTest` (new), `StockTurnoverQueryTest` (new, 7)

#### Definition of Done
- [x] схема FBO/FBS фильтрует остаток листинга, дата снимка источника сохраняется и при пустой схеме
- [x] границы корзин по TASK.md, «заморожено» = Frozen + NoSales
- [x] отчёт без SQL по чужим таблицам, сводка по всем строкам фильтра, без себестоимости — отдельно и в конце

#### Checks
- `phpunit StockQtyByListingOnDateSchemeTest StockQtyByListingOnDateFreshnessTest` — OK (8 tests, 21 assertions)
- `phpunit TurnoverBucketTest StockTurnoverPolicyTest` — OK (15 tests, 28 assertions)
- `phpunit StockTurnoverQueryTest` — OK (7 tests, 61 assertions)
- `make site-stan` — OK; `make site-cs-check` — OK

#### Internal review
- iterations: 1. Ruling: ожидание плана «bucket=Frozen → только A» было неверным — листинг D (15 шт, 3 продажи) тоже Frozen; тест по спеке.

#### External review
- required: no (handoff — см. stage-3)

#### Risks / reviewer focus
- Себестоимость — `marketplace_inventory_cost_prices` (как в юнит-экономике), формулировка TASK.md про «закупочную цену каталога» исправлена.
- Отчёт строится в памяти по всем листингам компании с остатком: для кабинетов в тысячи SKU — четыре запроса и сортировка в PHP, без N+1.

#### Next
- continue to Stage 2 automatically
