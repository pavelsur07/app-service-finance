# checkpoint — listings-server-search-bulk-tag

- Ветка: `feat/listings-server-search-bulk-tag` от `master` `ae67bde9c`.
- Baseline 19.10.2026: `ListingTagsApiTest`, `MarketplaceListingsTagsColumnTest`,
  `ListingTagsManageTest` — 38 тестов OK (своя БД `app_test_lst`).

## Ход

| Когда | Что | Следующее действие |
|---|---|---|
| 19.10 | Phase 0: ветка, baseline, план этапов, `stages/stage-1.md` | 1.1 `ListingsFilter` |
| 19.10 | Stage 1 закрыт: серверный поиск, фильтр по тегу, регрессия красная→зелёная, 1743 теста модуля OK | план Stage 2 |
| 19.10 | Stage 2 закрыт: assign по фильтру, `matched`, `filter_too_broad`, ARCHITECTURE 2.30; 1761 тест модуля OK | план Stage 3 (UI) |
