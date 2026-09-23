# marketplace-legacy-sync-removal — handoff

**Branch:** `refactor/marketplace-legacy-sync-removal` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2504 · **CI:** см. PR

## Summary of stages
- Stage 1 — удалены `marketplace:sync`, `MarketplaceSyncFacade::sync*`, `FetchMarketplaceDataCommand`/`Action` с маршрутом Messenger, реестр и фетчеры Ozon/WB, канал `legacy_wb_sync`, тест заглушки.

## Migrations
- none

## Public API / contract changes
- удалена CLI-команда `marketplace:sync` и три метода `MarketplaceSyncFacade` (вызывающих не было); HTTP API не менялся

## Checks
- `make site-stan` — OK, baseline 2461 → 2454
- `make site-cs-check`, `make site-cs-strict-types` — OK
- `make site-test-unit` — OK 3017; `make site-test` — OK 5225
- `lint:container` — OK; `debug:messenger` — исчез только `FetchMarketplaceDataCommand`

## Reviews
- internal: iterations 1, BLOCKER/IMPORTANT нет
- external: rounds 1, REVIEW_GREEN

## Risks, limitations, follow-ups
- После деплоя: воркеры healthy, failed не растёт (61), в GlitchTip нет `ClassNotFound`/`NoHandlerForMessage`; ночные WB-синки 24.09 (Marketplace 03:10, Ingestion 03:00) — успешны.
- `WildberriesAdapter` / `MarketplaceAdapterRegistry` — этап 4 вместе с легаси Ozon v3.

## Owner decision
Ready: PR #2504 "refactor(marketplace): удалить легаси marketplace:sync и fetch-цепочку FetchMarketplaceData" — merge into master with automatic production deploy?
Reply: "merge and deploy #2504"
