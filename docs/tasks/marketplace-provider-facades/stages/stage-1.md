### Stage 1: фасады вместо прямых импортов — DONE

**Risk:** MEDIUM
**Stage base commit:** `d7f2a599`
**Work items:** 1.1, 1.2, 1.3, 1.4

#### What was done
- 1.1 — `WbFinanceThrottleFacade` (+ `WbFinanceThrottleDTO`); Ingestion `WbFinanceReportClient` зависит от фасада; явная привязка `$rateLimiter` из `services.yaml` удалена, за фасадом тот же shared `WbFinanceRateLimiter`.
- 1.2 — `MarketplaceSyncFacade::findLatestOzonTotalsCheck` (+ `OzonTotalsCheckDTO`); Ingestion `ReconciliationQuery` зависит от фасада, сущность наружу не отдаётся.
- 1.3 — `CostCategoryCatalogFacade` (+ `CostCategoryGroupsDTO`); `MarketplaceCostAnalyticsGroupResolver` читает справочники через фасад, ветвление прежнее; мёртвый `WidgetServiceGroupMap` удалён.
- 1.4 — baseline −2, `ARCHITECTURE.md`.

#### Files changed
- `site/src/Marketplace/Facade/WbFinanceThrottleFacade.php` — new
- `site/src/Marketplace/Facade/CostCategoryCatalogFacade.php` — new
- `site/src/Marketplace/Application/DTO/{WbFinanceThrottleDTO,OzonTotalsCheckDTO,CostCategoryGroupsDTO}.php` — new
- `site/src/Marketplace/Facade/MarketplaceSyncFacade.php` — modified
- `site/src/Marketplace/Domain/OzonCostCategory.php` — docblock
- `site/src/Ingestion/Infrastructure/Api/Wildberries/WbFinanceReportClient.php` — modified
- `site/src/Ingestion/Infrastructure/Query/ReconciliationQuery.php` — modified
- `site/src/MarketplaceAnalytics/Application/Service/MarketplaceCostAnalyticsGroupResolver.php` — modified
- `site/src/MarketplaceAnalytics/Application/Service/WidgetServiceGroupMap.php` — deleted
- `site/config/services.yaml`, `site/phpstan-baseline.neon`, `ARCHITECTURE.md` — modified
- тесты: 2 новых (`tests/Unit/Marketplace/Facade/*`), 1 новый сценарий в `VerificationQueriesTest`, правка 6 существующих

#### Definition of Done
- [x] Ingestion и MarketplaceAnalytics не импортируют Ozon/WB-классы Marketplace (`grep` пусто)
- [x] один экземпляр `WbFinanceRateLimiter` за фасадом (`debug:container`)
- [x] поведение резолвера неизменно: 1545 комбинаций старая/новая версия, расхождений 0
- [x] тексты исключений лимитера закреплены тестами
- [x] IDOR-негатив для контроля Ozon
- [x] baseline 2463 → 2461, Marketplace-пробоев 8 → 6
- [x] `ARCHITECTURE.md` обновлён

#### Checks
- baseline: targeted unit 24/318 OK; `VerificationQueriesTest --filter Reconciliation` 2/17 OK
- `make site-stan` — `[OK] No errors`
- `make site-cs-check` — 0 files; `make site-cs-strict-types` — 0 files
- `make site-test-unit` — OK, 3033 tests (4 deprecations, не из изменённых файлов)
- `make site-test` — OK, 5241 tests (6 deprecations, pre-existing)
- `bin/console lint:container` — OK

#### Internal review
- iterations: 1 (отдельная сессия без истории реализации); BLOCKER/IMPORTANT: none
- MINOR отклонены: `?->` слева от `??` — PHPStan level 8 запрещает (`nullsafe.neverNull`), исправлено обратно на `->`; `?string unitBucket` в общем DTO — осознанно, Ozon бакет не задаёт; метод контроля Ozon в `MarketplaceSyncFacade` — это фасад Marketplace для Ingestion, выбор зафиксирован в плане; `Application/DTO` vs `Marketplace\DTO` — новые DTO в открытом для гейта подслое.
- FOLLOW-UP: 6 оставшихся пробоев границы (пункт 4 плана систематизации).

#### External review
- required: yes (Large, handoff)
- rounds: 1; result: находок по диффу задачи нет
- rejected with reason: IMPORTANT по `site/bin/capture-wb-inventory.sh` — untracked-файл, существовавший до задачи, в ветку не входит; скрипт ревью включает untracked-файлы в дифф (`docs/workflow/external-review.md`)

#### Risks / reviewer focus
- WB-троттлинг: после деплоя проверить обычный объём `WB finance throttle bucket busy` и 429-ретраев у воркера Ingestion.

#### Next
- handoff
