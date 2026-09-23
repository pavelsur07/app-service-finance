### Stage 1: удаление легаси-синка — DONE

**Risk:** HIGH-LOCAL (маршрут Messenger)
**Stage base commit:** `24b87829`
**Work items:** 1.1, 1.2, 1.3, 1.4

#### What was done
- 1.1 — удалены `MarketplaceSyncCommand` (`marketplace:sync`), `MarketplaceSyncFacade::syncSales/syncCosts/syncReturns` и `guardLegacyWbSync`, зависимости фасада `MessageBusInterface` и логгер `legacy_wb_sync`; удалён `LegacyWbSyncDisabledTest` (16 тестов).
- 1.2 — удалены `FetchMarketplaceDataAction`, `FetchMarketplaceDataCommand` и его маршрут в `messenger.yaml`, `MarketplaceFetcherRegistry`, `MarketplaceFetcherInterface`, `OzonFetcher`, `WbFetcher`; канал monolog `legacy_wb_sync`.
- 1.3 — baseline −7 записей по удалённым файлам; осиротевших ссылок нет.
- 1.4 — `ARCHITECTURE.md`: описан `processCostsFromRaw`, заметка об удалении легаси.

#### Definition of Done
- [x] удалено всё из плана, данные БД не затронуты
- [x] baseline 2461 → 2454
- [x] `debug:messenger`: исчез только `FetchMarketplaceDataCommand`; `bin/console list`: исчез только `marketplace:sync`
- [x] `ARCHITECTURE.md` обновлён

#### Checks
- `make site-stan` — `[OK] No errors`
- `make site-cs-check`, `make site-cs-strict-types` — 0 files
- `make site-test-unit` — OK 3017 (было 3033, −16 удалённого теста)
- `make site-test` — OK 5225 (было 5241, −16)
- `lint:container` — OK

#### Internal review
- iterations: 1 (отдельная сессия); BLOCKER/IMPORTANT: none
- MINOR: неотслеживаемый `docs/plan/marketplace-systematization-plan-2026-09-19.md` описывает заглушку как текущую — вне ветки, у Владельца.
- FOLLOW-UP: удалённый Action вызывал несуществующий `MarketplaceRawDocumentRepository::save()` — подтверждение, что путь был сломан.

#### External review
- required: yes (Large, HIGH-LOCAL)
- rounds: 1; result: REVIEW_GREEN

#### Next
- handoff
