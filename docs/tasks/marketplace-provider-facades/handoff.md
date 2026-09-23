# marketplace-provider-facades — handoff

**Branch:** `refactor/marketplace-provider-facades` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2502 · **CI:** см. PR

## Summary of stages
- Stage 1 — три межмодульных импорта Ozon/WB-классов Marketplace заменены фасадами (`WbFinanceThrottleFacade`, `CostCategoryCatalogFacade`, `MarketplaceSyncFacade::findLatestOzonTotalsCheck`); мёртвый `WidgetServiceGroupMap` удалён.

## Files changed
- см. `stages/stage-1.md`

## Migrations
- none

## Public API / contract changes
- новые Facade-методы (внутренний контракт между модулями), описаны в `ARCHITECTURE.md`; HTTP API не менялся

## Checks
- `make site-stan` — OK, baseline 2463 → 2461
- `make site-cs-check`, `make site-cs-strict-types` — OK
- `make site-test-unit` — OK 3033; `make site-test` — OK 5241
- `bin/console lint:container` — OK

## Reviews
- internal: iterations 1, BLOCKER/IMPORTANT нет
- external: rounds 1, находок по диффу нет; отклонена 1 находка по untracked-файлу `site/bin/capture-wb-inventory.sh` вне ветки

## Risks, limitations, follow-ups
- После деплоя: объём `WB finance throttle bucket busy` и 429-ретраев у воркера Ingestion — обычный; дельта на экране сверки Ozon за закрытый месяц не изменилась.
- Follow-up: 6 оставшихся пробоев границы Marketplace (пункт 4 плана систематизации); этап 2 — закрыть `App\Marketplace\{Ozon,Wildberries}` в `ModuleBoundaryRules`.
- `docs/plan/marketplace-systematization-plan-2026-09-19.md` в git не отслеживается — в PR не включён.

## Owner decision
Ready: PR #2502 "refactor(marketplace): фасады вместо прямых импортов Ozon/WB-классов из Ingestion и Analytics" — merge into master with automatic production deploy?
Reply: "merge and deploy #2502"
