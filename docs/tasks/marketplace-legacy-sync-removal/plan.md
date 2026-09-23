# marketplace-legacy-sync-removal: удалить легаси `marketplace:sync` и его fetch-цепочку

Этап 3 разделения `src/Marketplace` по провайдерам (пункт 10 плана систематизации):
мёртвый код удаляется до переезда. Разрешение снять пометку «код не удалять» в
docblock `MarketplaceSyncCommand` — утверждение плана Владельцем 23.09.2026.

Факты: единственный продюсер `FetchMarketplaceDataCommand` — `MarketplaceSyncFacade::sync*`,
их вызывает только `marketplace:sync`; команды нет в `app.cron`. На проде 23.09 все
очереди пусты, в failed (61) нет ни одного `FetchMarketplaceDataCommand`.

Baseline (на `24b87829`):
- `phpstan-baseline.neon` — 2461 запись
- `debug:messenger` — 48 обработчиков, в т.ч. `FetchMarketplaceDataCommand → FetchMarketplaceDataAction`
- `bin/console list` — есть `marketplace:sync`

## Stage 1: удаление легаси-синка
Risk: HIGH-LOCAL (маршрут Messenger)
stage_base_commit: 24b87829
Definition of Done:
- удалены `MarketplaceSyncCommand`, `MarketplaceSyncFacade::sync*` и guard, `FetchMarketplaceDataAction`/`Command` и маршрут, `MarketplaceFetcherRegistry`, `MarketplaceFetcherInterface`, `OzonFetcher`, `WbFetcher`, канал `legacy_wb_sync`, `LegacyWbSyncDisabledTest`
- baseline 2461 → 2454; осиротевших ссылок нет
- `debug:messenger`: исчез только `FetchMarketplaceDataCommand`
- `ARCHITECTURE.md`: `processCostsFromRaw` описан
- исключено: `WildberriesAdapter` / `MarketplaceAdapterRegistry` (этап 4), данные БД
Work items:
- 1.1 — команда, методы фасада, тест
- 1.2 — fetch-цепочка, маршрут Messenger, канал monolog
- 1.3 — baseline, поиск осиротевших ссылок
- 1.4 — документация
Stage checks:
- phpstan, cs, unit + full, lint:container, debug:messenger diff
Reviewer focus:
- ничего живого не зависит от удалённого (DI, Messenger, cron, шаблоны)
- конструктор `MarketplaceSyncFacade` и оставшиеся методы
