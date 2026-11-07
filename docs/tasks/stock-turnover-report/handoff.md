# stock-turnover-report — handoff

**Branch:** `feat/stock-turnover-report` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2903 · **CI:** GitHub Actions на PR, обязательные job’ы зелёные перед merge

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
- Новые маршруты `GET /inventory/stock-turnover` и `GET /inventory/stock-turnover/export.xlsx` (`ROLE_COMPANY_USER`).
- `InventoryFacade::getStockQtyByListingOnReportDate(companyId, date, ?marketplace, ?scheme = null)` — новый необязательный параметр; существующие вызовы не меняются.
- Messenger routing, cron, схема БД — без изменений.

## Decisions by default (TASK.md «Данные и правила расчёта»)
- Возвраты не вычитаются из продаж: оборачиваемость меряет отгрузки, возврат вернётся в остаток следующим снимком.
- Товар в пути и на приёмке не в остатке (`status = available`, как в юнит-экономике).
- Себестоимость — себестоимость листинга на дату (`marketplace_inventory_cost_prices`), та же, что в юнит-экономике; нулевая = «без себестоимости»,
  такие листинги не входят в ₽ и показаны отдельно.
- Устаревший источник (`StockSnapshotFreshnessPolicy`, 2 дня) исключается из отчёта и показывается предупреждением, не смешивается со свежими.
- Невалидные параметры фильтра → умолчания (200), включая массив вместо строки и наоборот; `page` вне диапазона → редирект на первую страницу с сохранением фильтров.
- Схема FBO/FBS фильтрует только остатки; продажи считаются по листингу целиком (в продажах схемы нет), поэтому дни запаса в режиме одной схемы занижены — ограничение, см. ниже.
- `tags=<uuid>` строкой (ссылка без `[]`) принимается как один тег.
- «Заморожено» в сводке не красится красным (DS-Money: красный только для ошибок).

## Checks
- `make site-stan` — OK, No errors (baseline не рос)
- `make site-cs-check` — OK (0 of 3783 files); `make site-cs-strict-types` — OK
- `make site-test-unit` — OK (4504 tests, 25332 assertions)
- `phpunit tests/Functional/MarketplaceAnalytics/ tests/Integration/MarketplaceAnalytics/StockTurnoverQueryTest.php` после правок ревью — OK (43 tests, 200 assertions); `tests/Integration/MarketplaceAnalytics/StockTurnoverQueryTest.php` — OK (7);
  `tests/Integration/Inventory/Infrastructure/Query/` схема/свежесть — OK (8)
- `make site-test` на `cec62970a` (после правок ревью, до фикса нулевого байта в `date`) — OK (8327 tests, 46889 assertions); после фикса — `tests/Functional/MarketplaceAnalytics/` OK (36 tests), `site-stan` / `site-cs-check` / `site-cs-strict-types` зелёные; полный набор на финальном коммите — CI PR

## Reviews
- internal: self-review по задачам, 1 итерация; rulings — в stage-1 / stage-2.
- independent whole-branch review (свежий агент, Opus): «With fixes» — все 5 пунктов Review Focus и IDOR подтверждены; Important 1 (параметры-массивы → 400) и Important 2 (формулы в xlsx) исправлены; minor 3 (недетерминированный порядок при равенстве) и minor 4 (страница вне диапазона — 404, ruling про 422 был неверным) переоценены как Important и исправлены (редирект на первую страницу); minor 5 (пустое состояние при фильтре) исправлен; minor 6 отложен. Каждый фикс — тест RED→GREEN (`cec62970a`).
- external (Codex, `site/bin/external-review.sh`): round 1 — 1 IMPORTANT (формулы в xlsx: `Cell::fromValue()` делает из «=…» `FormulaCell`) + 1 MINOR (`period=abc` → 400), оба исправлены с тестами (`08b31dcfe`); round 2 — `REVIEW_GREEN` с одним MINOR (`date=…%00` → `ValueError` в `createFromFormat()` → 500 вместо умолчания), исправлен с регрессионным кейсом в `invalidParams()`.

## Risks, limitations, follow-ups
- Отчёт считается в памяти по всем листингам с остатком (четыре запроса, сортировка в PHP); для кабинетов в тысячи SKU — ок, регистра/кэша нет (подход 1 из TASK.md).
- Продажи берутся по дате продажи без учёта отмен; корзина «нет продаж» при коротком периоде 30 дней у сезонных товаров — ожидаемо.
- Теги — `select multiple` без виджета.
- Схема FBO/FBS делит остаток одной схемы на все продажи листинга: для раздельной оборачиваемости по схемам нужна схема в продажах (нет в данных).
- Отложенный minor: доля замороженного округляется до целого процента («0 %» при доле меньше 0,5 %).
- Скриншоты: `docs/tasks/stock-turnover-report/screenshots/` (общий вид с устаревшим WB, корзина «свыше 90», схема FBS).

## Status
Задача закрыта 07.11.2026. На проде: #2903 (отчёт), #2904 («Скорость продаж»), #2905 (вкладки «Товары и остатки», `/inventory/stock-turnover`), #2906 (размер листинга).
Проверка на проде 07.11 — см. `checkpoint.md`.

## Owner decision (историческое)
Ready: PR #2903 «feat(marketplace-analytics): отчёт «Оборачиваемость» — где заморожены деньги» — merge into master with automatic production deploy?
Reply: "merge and deploy #2903"
