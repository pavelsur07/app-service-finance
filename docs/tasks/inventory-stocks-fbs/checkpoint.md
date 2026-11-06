## Current checkpoint

**Phase:** handoff
**Status:** checking
**Stage base commit:** Stage 1 `6e46fda5c`, Stage 2 `798e29e3b`, Stage 3 `f233d680d`, Stage 4 `24246e760`

### Completed
- Stage 1 (Task 1–2): `FulfillmentScheme`, схема в `InventoryStockReportQuery` и `StockReportController` — `9f4274c18`, `798e29e3b`
- Stage 2 (Task 3): переключатель FBO / FBS в Twig, скриншоты сняты на стенде — `f233d680d`
- Stage 3 (Task 4–6): `WbInventoryClient` (склады продавца, остатки по баркодам), `WbSellerWarehouseStocksRawNormalizer`, `LocationType::SellerWarehouse`, `MarketplaceFacade::getListingBarcodes()`, FBS-фаза в `SyncWbInventorySnapshotHandler`, маппинг по баркоду в `NormalizeInventorySnapshotAction` — `f05047e3b`, `61a7feb92`, `24246e760`
- Stage 4 (Task 7): ARCHITECTURE.md, stage-отчёты, handoff — в работе

### Checks and baseline
- baseline: `phpunit StockReportControllerTest SyncWbInventorySnapshotHandlerTest tests/Unit/Inventory` — OK (154 tests)
- Stage 3: `phpunit tests/Integration/Inventory tests/Unit/Inventory` — OK (239 tests, 782 assertions)
- полные гейты — см. handoff.md

### Review status
- internal: self-review по ходу задач, итерация 1
- external: round 1 — pending (Codex, `site/bin/external-review.sh`)

### Exact next action
- дождаться гейтов и ревью, заполнить handoff.md, PR Ready, вопрос «merge and deploy #N»

### Files to inspect first on resume
- `docs/tasks/inventory-stocks-fbs/plan.md`, `.superpowers/sdd/plan/progress.md` (ledger), `handoff.md`
