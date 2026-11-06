# stock-turnover-report — handoff

**Branch:** `feat/stock-turnover-report` · **PR:** (будет заполнено) · **CI:** GitHub Actions на PR, обязательные job’ы зелёные перед merge

## Summary of stages
- Stage 1 — `InventoryFacade::getStockQtyByListingOnReportDate()` получил `?FulfillmentScheme $scheme`; `TurnoverBucket`, `StockTurnoverPolicy`
  (дни запаса = `intdiv(остаток × период, продано)`, корзины <30 / 30–59 / 60–90 / >90 / нет продаж); `StockTurnoverQuery` — отчёт из трёх Facade
  без SQL по чужим таблицам, сводка до фильтра корзины, без себестоимости — отдельно.
- Stage 2 — страница «Оборачиваемость» (вкладка аналитики маркетплейсов): фильтры период 30/60/90, источник, схема FBO/FBS, дата, корзина, теги;
  сводка «В товаре / Заморожено / По корзинам»; баннер даты остатков и устаревших источников; `vf-table`, пагинация по 50.
- Stage 3 — экспорт `export.xlsx` (два листа), документы задачи.

## Files changed
- Inventory: `Infrastructure/Query/StockQtyByListingOnDateQuery.php`, `Facade/InventoryFacade.php` — необязательный параметр схемы
- MarketplaceAnalytics (new): `Enum/TurnoverBucket.php`, `DTO/{TurnoverMeasure,StockTurnoverFilter,StockTurnoverRow,StockTurnoverSummary,StockTurnoverReport}.php`,
  `Domain/Service/StockTurnoverPolicy.php`, `Infrastructure/Query/StockTurnoverQuery.php`, `Application/StockTurnoverFilterParser.php`,
  `Controller/StockTurnoverIndexController.php`, `Controller/StockTurnoverExportController.php`, `Infrastructure/Export/StockTurnoverXlsxExporter.php`
- Twig: `marketplace_analytics/stock_turnover/index.html.twig` (new), `marketplace_analytics/_tabs.html.twig`
- `site/config/services_test.yaml` — `StockTurnoverQuery` public в test
- tests: `StockQtyByListingOnDateSchemeTest`, `TurnoverBucketTest`, `StockTurnoverPolicyTest`, `StockTurnoverQueryTest`, `StockTurnoverControllerTest`,
  `StockTurnoverExportControllerTest`, trait `StockTurnoverFixtures`
- docs: `ARCHITECTURE.md` (Changelog 2.152), `docs/tasks/stock-turnover-report/*` (TASK, plan, stages, screenshots, handoff)

## Migrations
- none

## Public API / contract changes
- Новые маршруты `GET /marketplace-analytics/stock-turnover` и `GET /marketplace-analytics/stock-turnover/export.xlsx` (`ROLE_COMPANY_USER`).
- `InventoryFacade::getStockQtyByListingOnReportDate(companyId, date, ?marketplace, ?scheme = null)` — новый необязательный параметр; существующие вызовы не меняются.
- Messenger routing, cron, схема БД — без изменений.

## Decisions by default (TASK.md «Данные и правила расчёта»)
- Возвраты не вычитаются из продаж: оборачиваемость меряет отгрузки, возврат вернётся в остаток следующим снимком.
- Товар в пути и на приёмке не в остатке (`status = available`, как в юнит-экономике).
- Себестоимость — себестоимость листинга на дату (`marketplace_inventory_cost_prices`), та же, что в юнит-экономике; нулевая = «без себестоимости»,
  такие листинги не входят в ₽ и показаны отдельно.
- Устаревший источник (`StockSnapshotFreshnessPolicy`, 2 дня) исключается из отчёта и показывается предупреждением, не смешивается со свежими.
- Невалидные параметры фильтра → умолчания (200), `page` вне диапазона → 422.
- «Заморожено» в сводке не красится красным (DS-Money: красный только для ошибок).

## Checks
- `make site-stan` — OK, No errors (baseline не рос)
- `make site-cs-check` — OK (0 of 3783 files); `make site-cs-strict-types` — OK
- `make site-test-unit` — OK (4504 tests, 25332 assertions)
- `phpunit tests/Functional/MarketplaceAnalytics/` — OK (25 tests, 101 assertions); `tests/Integration/MarketplaceAnalytics/StockTurnoverQueryTest.php` — OK (7);
  `tests/Integration/Inventory/Infrastructure/Query/` схема/свежесть — OK (8)
- полный набор — (будет заполнено)

## Reviews
- internal: self-review по задачам, 1 итерация; rulings — в stage-1 / stage-2.
- independent whole-branch review: (будет заполнено)
- external (Codex, `site/bin/external-review.sh`): (будет заполнено)

## Risks, limitations, follow-ups
- Отчёт считается в памяти по всем листингам с остатком (четыре запроса, сортировка в PHP); для кабинетов в тысячи SKU — ок, регистра/кэша нет (подход 1 из TASK.md).
- Продажи берутся по дате продажи без учёта отмен; корзина «нет продаж» при коротком периоде 30 дней у сезонных товаров — ожидаемо.
- Теги — `select multiple` без виджета.
- Скриншоты: `docs/tasks/stock-turnover-report/screenshots/` (общий вид с устаревшим WB, корзина «свыше 90», схема FBS).

## Owner decision
Ready: PR #N «feat(marketplace-analytics): отчёт «Оборачиваемость» — где заморожены деньги» — merge into master with automatic production deploy?
Reply: "merge and deploy #N"
