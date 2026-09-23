# marketplace-ozon-namespace — handoff

**Branch:** `refactor/marketplace-ozon-namespace` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2507 · **CI:** см. PR

## Summary
- Stage 1 — код Ozon перенесён в `src/Marketplace/Ozon/` (61 класс, 36 тестов), поведение не меняется. Разделение модуля по провайдерам завершено.

## Migrations
- none

## Checks
- `make site-stan` — OK; baseline 2410, эквивалентен master по карте переименований
- `make site-cs-check`, `make site-cs-strict-types` — OK
- `make site-test-unit` — 2978; `make site-test` — 5189 (как до)
- `lint:container` OK; `debug:router`, `list` идентичны; `debug:messenger` — только FQCN 4 обработчиков

## Reviews
- internal: 1 итерация, BLOCKER/IMPORTANT нет; external: REVIEW_GREEN

## Risks, limitations, follow-ups
- CI baseline-guard не понимает переименований — PR с `[baseline-grow]`, эквивалентность доказана.
- job-log `reason` у каталога Ozon: старые строки с прежним FQCN исключения.
- Отдельные PR: мёртвые `fetchSales/fetchCosts/fetchReturns` в `WildberriesAdapter`; guard с учётом `git diff -M`; дубль `WbSalesReportRowNormalizerTest`.

## Owner decision
Ready: PR #2507 "[baseline-grow] refactor(marketplace): перенести код Ozon в src/Marketplace/Ozon" — merge into master with automatic production deploy?
Reply: "merge and deploy #2507"
