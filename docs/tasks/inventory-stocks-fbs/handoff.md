# inventory-stocks-fbs — handoff

**Branch:** `feat/inventory-stocks-fbs-scheme` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2900 · **CI:** GitHub Actions на PR #2900, обязательные job’ы зелёные перед merge

## Summary of stages
- Stage 1 — `FulfillmentScheme`; схема FBO / FBS в `InventoryStockReportQuery` (фильтр, эффективная дата, разбивка — внутри схемы; разбивка скрыта при одном типе) и `StockReportController` (`?scheme=`).
- Stage 2 — переключатель FBO / FBS в фильтрах `/inventory/stocks` (Tabler `btn-group`), скриншоты обоих положений сняты на стенде.
- Stage 3 — WB: остатки склада продавца. `WbInventoryClient::fetchSellerWarehouses()/fetchSellerWarehouseStocks()`, `MarketplaceFacade::getListingBarcodes()`, `LocationType::SellerWarehouse`, `WbSellerWarehouseStocksRawNormalizer`, фаза FBS в `SyncWbInventorySnapshotHandler` (та же сессия и cron), маппинг по баркоду в `NormalizeInventorySnapshotAction`.
- Stage 4 — ARCHITECTURE.md (Changelog 2.150), документы задачи.
- После ревью: баркоды уходят в WB строками; FBS-страницы сохраняются только все вместе после успеха фазы; клиент отвергает HTTP 200 без списка `stocks`.

## Files changed
- `site/src/Inventory/Enum/FulfillmentScheme.php` — new
- `site/src/Inventory/Enum/LocationType.php` — `SellerWarehouse`
- `site/src/Inventory/Infrastructure/Query/InventoryStockReportQuery.php`, `site/src/Inventory/Controller/StockReportController.php`, `site/templates/inventory/stocks/index.html.twig`
- `site/src/Inventory/Infrastructure/Api/Wildberries/WbInventoryClient.php`
- `site/src/Inventory/Infrastructure/Normalizer/WbSellerWarehouseStocksRawNormalizer.php` — new
- `site/src/Inventory/MessageHandler/SyncWbInventorySnapshotHandler.php`, `site/src/Inventory/Application/NormalizeInventorySnapshotAction.php`
- `site/src/Marketplace/Facade/MarketplaceFacade.php` — `getListingBarcodes()`
- `site/phpstan-baseline.neon` — −1 запись (enum-тест переписан через Reflection)
- tests: `StockReportControllerTest` (+6, rollup переписан), `InventoryEnumsTest`, `WbInventoryClientTest` (+8), `WbSellerWarehouseStocksRawNormalizerTest` (new), `SyncWbInventorySnapshotHandlerTest` (+7), `NormalizeInventorySnapshotActionTest` (сигнатура)
- docs: `ARCHITECTURE.md`, `docs/tasks/inventory-stocks-fbs/*`

## Migrations
- none (новое значение enum хранится строкой без CHECK; новых колонок нет)

## Public API / contract changes
- `GET /inventory/stocks` — новый необязательный параметр `scheme=fbo|fbs` (по умолчанию `fbo`), старые ссылки работают.
- `InventoryStockReportQuery::findEffectiveSnapshotDate/getBreakdownColumns/getPage` — новый обязательный параметр `FulfillmentScheme $scheme` (единственный потребитель — контроллер и тест).
- `MarketplaceFacade::getListingBarcodes(string $companyId, string $marketplace): array` — новый метод.
- Поведение данных: `StockQtyByListingOnDateQuery` (остатки для юнит-экономики) после первой ночи включит FBS WB в остаток листинга — как у Ozon.
- Messenger routing, cron, схема БД — без изменений.

## Checks
- baseline (до кода): `phpunit StockReportControllerTest SyncWbInventorySnapshotHandlerTest tests/Unit/Inventory` — OK (154 tests)
- `make site-stan` — OK, No errors (baseline −1)
- `make site-cs-check` — OK (0 of 3756 files)
- `make site-cs-strict-types` — OK
- `make site-test-unit` — OK (4464 tests, 25202 assertions)
- `make site-test` на коммите `898a165ea` (до правок ревью) — 8208 tests, 2 failures в `SchemaInSyncWithMigrationsTest` / `SchemaSyncStage3MigrationTest`: тестовая БД содержала миграцию `Version20261106120000`, которой нет в репозитории (чужая локальная ветка, колонка `cash_transaction.imported_description_overridden`); к задаче не относится, тестовая БД пересоздана из миграций репозитория
- `make site-test` на коммите `8fc6041dd` (финальный код до слияния master) — OK, 8217 tests, 46311 assertions; после слияния `origin/master` (`ce28870f5`, конфликт только в строке Changelog ARCHITECTURE.md → 2.150) — `site-stan`, `site-cs-check`, `site-cs-strict-types` зелёные, `tests/{Unit,Integration,Functional}/Inventory` + схемные тесты — OK, 294 tests; полный набор на слитом дереве — CI PR #2900
- целевые после правок ревью: `WbInventoryClientTest SyncWbInventorySnapshotHandlerTest InventoryEnumsTest StockReportControllerTest` — OK (77 tests, 262 assertions)

## Reviews
- internal: self-review по задачам, 1 итерация; тест 403 усилен проверкой числа запросов.
- independent whole-branch review (свежий агент, Opus): «With fixes» — Important 1 (частичная FBS-фаза) исправлен, Important 2 (контракт WB без живого ответа, возможное удвоение остатка размера по баркодам) — вынесен в приёмку «Первая ночь»; 8 minor — отложены (список в разделе ниже).
- external (Codex, `site/bin/external-review.sh`): round 1 — 2 IMPORTANT (числовые баркоды; частичная фаза), исправлены с регрессионными тестами; round 2 — 1 IMPORTANT (HTTP 200 без `stocks`), исправлен; round 3 — `REVIEW_GREEN`.
- rejected: находки по untracked-файлам рабочего дерева вне задачи — не часть диффа.

## Risks, limitations, follow-ups
- Контракт WB зафиксирован по OpenAPI, живого ответа не было: первая ночь — проверка (см. TASK.md «Приёмка»): `skus` vs `chrtIds`, размер с несколькими баркодами, доля нулей, `EXPLAIN` эффективной даты в режиме FBS.
- Сбой FBS-фазы не повторяется внутри сессии (следующая ночь); FBW не страдает.
- Отложенные minor ревью: обратный скан истории при отсутствии FBS-строк; нулевые `amount`; `getListingBarcodes()` вне `try` фазы; нет assert на `warning` и теста redelivery «только FBW»; `fromFulfillmentType()` не используется в production; сырой SQL в Facade и фильтр по `l.marketplace`; слабая подсветка активного положения переключателя.
- Токен WB без категории «Маркетплейс» — режим FBS у кабинета пуст, в логе `warning` `WB seller warehouse stocks skipped.`

## Owner decision
Ready: PR #2900 «feat(inventory): переключатель FBO/FBS в отчёте остатков, остатки склада продавца WB» — merge into master with automatic production deploy?
Reply: "merge and deploy #2900"
