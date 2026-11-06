### Stage 3: WB — остатки склада продавца (FBS) в ночном синке — DONE

**Risk:** HIGH-LOCAL (внешняя интеграция, Messenger-обработчик; routing и cron не менялись)
**Stage base commit:** `f233d680d`
**Work items:** 3.1 (клиент), 3.2 (Facade-метод, `LocationType`, нормализатор), 3.3 (фаза в обработчике, маппинг в Action)

#### What was done
- `WbInventoryClient::fetchSellerWarehouses()` / `fetchSellerWarehouseStocks()` на marketplace-api; общий `requestJson()`
  с прежней классификацией статусов (429 → rate limit, 400–403 → api, 5xx/transport → temporary).
- `MarketplaceFacade::getListingBarcodes(companyId, marketplace)` — DBAL, карта баркод → листинг.
- `LocationType::SellerWarehouse`; `WbSellerWarehouseStocksRawNormalizer` (только страницы `/api/v3/stocks/`).
- `SyncWbInventorySnapshotHandler`: после FBW — фаза `fetchSellerStockPages()`; без баркодов — пропуск (info);
  сбой фазы — `warning`, сессия `completed`; при возобновлении FBS-страницы отделяются от FBW по `sourceEndpoint`.
- `NormalizeInventorySnapshotAction::normalizeWildberries`: FBS-строки маппятся по баркоду, `sourceOfferId` = nmId,
  локация `SellerWarehouse`.

#### Files changed
- `site/src/Inventory/Infrastructure/Api/Wildberries/WbInventoryClient.php` — modified
- `site/src/Marketplace/Facade/MarketplaceFacade.php` — modified (новый метод)
- `site/src/Inventory/Enum/LocationType.php` — modified
- `site/src/Inventory/Infrastructure/Normalizer/WbSellerWarehouseStocksRawNormalizer.php` — new
- `site/src/Inventory/MessageHandler/SyncWbInventorySnapshotHandler.php` — modified
- `site/src/Inventory/Application/NormalizeInventorySnapshotAction.php` — modified
- tests: `WbInventoryClientTest` (+6), `WbSellerWarehouseStocksRawNormalizerTest` (new, 2), `InventoryEnumsTest`,
  `SyncWbInventorySnapshotHandlerTest` (+4, пять существующих получили пустой ответ складов)

#### Definition of Done
- [x] FBS-страницы в raw, строки `fbs` с локацией `seller_warehouse` и листингом по баркоду
- [x] 403 на складах — `completed` с FBW, `warning`; без баркодов — ни одного запроса в marketplace-api
- [x] повторная доставка не дублирует FBS-страницы
- [x] routing/cron не менялись, миграций нет

#### Checks
- `phpunit tests/Integration/Inventory tests/Unit/Inventory` — OK (239 tests, 782 assertions)
- `phpstan analyse` по изменённым файлам — 0 ошибок (один лишний `??` убран по подсказке PHPStan)
- `php-cs-fixer --dry-run` — чисто

#### Internal review
- iterations: 1; IMPORTANT fixed: тест 403 был зелёным и без фазы — добавлен `getRequestsCount() === 4`

#### External review
- required: yes (HIGH-LOCAL); round 1 (Codex): 2 IMPORTANT, оба подтверждены и исправлены с регрессионными тестами:
  числовые баркоды уходили в WB числами (`testNumericBarcodesAreSentToSellerStocksAsStrings`);
  частичная FBS-фаза публиковала неполный остаток (`testSellerPhaseFailureAfterFirstPageDiscardsSellerPages`,
  страницы фазы теперь сохраняются только все вместе). Round 2 — см. handoff.md

#### Risks / reviewer focus
- токен и тело ответа не логируются; `flush()` только в обработчике/Action
- контракт WB (`/api/v3/warehouses`, `/api/v3/stocks/{id}`) зафиксирован по OpenAPI без живого ответа — первая ночь на проде = проверка
- `StockQtyByListingOnDateQuery` теперь включает FBS WB в остаток листинга (как Ozon) — осознанно, TASK.md

#### Next
- continue to Stage 4 automatically
