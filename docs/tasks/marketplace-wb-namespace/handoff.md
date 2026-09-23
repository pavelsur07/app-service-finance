# marketplace-wb-namespace — handoff

**Branch:** `refactor/marketplace-wb-namespace` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2506 · **CI:** см. PR

## Summary
- Stage 1 — код Wildberries перенесён в `src/Marketplace/Wildberries/` (59 классов, 38 тестов), поведение не меняется.

## Migrations
- none

## Public API / contract changes
- HTTP-маршруты и CLI-команды не менялись; FQCN внутренних классов WB сменились (внешние модули зависят только от фасадов)

## Checks
- `make site-stan` — OK, baseline 2410
- `make site-cs-check`, `make site-cs-strict-types` — OK
- `make site-test-unit` — 2978; `make site-test` — 5189 (как до переноса)
- `lint:container` OK; `debug:router`, `list` идентичны; `debug:messenger` — только FQCN обработчика

## Reviews
- internal: 1 итерация, BLOCKER/IMPORTANT нет
- external: 1 раунд, REVIEW_GREEN

## Risks, limitations, follow-ups
- В `marketplace_financial_report_sync_error.error_class` и `…sync_status.last_error_class` будут два варианта FQCN `WbRawDocumentRefreshConflictException` (до/после деплоя); код значение не сравнивает.
- Два теста с именем `WbSalesReportRowNormalizerTest` в разных namespace — объединить позже.
- Этап 6 — Ozon по той же схеме; затем удаление мёртвых `fetchSales/fetchCosts/fetchReturns` в `WildberriesAdapter`.

## Owner decision
Ready: PR #2506 "refactor(marketplace): перенести код Wildberries в src/Marketplace/Wildberries" — merge into master with automatic production deploy?
Reply: "merge and deploy #2506"
