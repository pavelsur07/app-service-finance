## Current checkpoint

**Phase:** Stage 3 / Work item 3.1
**Status:** implementing
**Stage base commit:** d66582791

### Completed
- Stage1: commit d66582791, Draft PR #3032 base master. Targeted concurrency повтор: 11 tests/76 assertions OK; причина исходной задержки boot не подтверждена.
- Work item 2.1: policy RED 18 failures → GREEN 69 tests/187 assertions.
- Work item 2.2: код/штрихкод/alias integration RED 4 failures → GREEN 24 tests/97 assertions; confirmation RED отсутствующий метод → GREEN 5 tests/17 assertions.
- Work item 1.1: parser RED (2 failures) → GREEN (16 tests/46 assertions), nullable barcode snapshots/entities и повтор импорта.
- Work item 1.2: scoped batch Facade и настройки, миграция применена к isolated test DB.
- Прочитан утверждённый план; создана свежая ветка/worktree от актуального master.
- Технический план и приёмка записаны; изучены preview/policy/writer/import/facade.
- Официальный формат штрихкодов проверен: JSON list объектов ean8/ean13/code128/gtin/upc со строковыми значениями.

### Checks and baseline
- Stage2 module: 450 tests/1737 assertions OK; focused PHPStan MoySklad/tests OK.
- Stage2 external review: Claude CLI running, logs site/var/external-review/d6658279/.
- unit MoySklad — OK (222 tests, 727 assertions).
- migrations baseline — OK, 320 migrations; additive Stage1 migration — OK.
- module: 414 tests/1616 assertions; 2 existing concurrency tests timed out while PHPStan/fixer ran concurrently; diagnostics pending.
- focused PHPStan: 9 test typing errors identified and fixed; corrected run OK no errors.
- Targeted storage/import: OK (13 tests, 89 assertions).
- Ruling: confirmations/diagnosis storage introduced in consuming Stages 2/3, avoiding unused tables in Stage 1; no change to final scope.

### Review status
- internal: pending; external: pending.

### Exact next action
- Закончить Stage3 shared evaluator/Facade/schema/routing, мигрировать isolated test DB; добиться GREEN diagnostics tests.
- Stage3 decision: enqueue request без DB side effect (как SyncCatalog), Handler сам создаёт run и обходит keyset ID<=100 в отдельных транзакциях в рамках одного async_pipeline delivery. Durable cursor позволяет retry без dispatch-after-commit окна; источник/каталог/настройки/назначения/review fingerprint до/после обхода; изменившийся run stale и не заменяет completed.

### Files to inspect first on resume
- TASK.md, plan.md; CatalogPageParser, ProductSnapshot, VariantSnapshot; ChangeListingMappingAction, AutomaticMappingPreviewQuery.

### Stage2 final review
- External Claude2 rounds: IMPORTANT1+3, BLOCKER0, all confirmed fixes verified; fixed without re-run per policy. Internal BLOCKER1 barcode race fixed RED→GREEN; no open findings.
- Latest targeted75 tests/219 assertions, bounded busy1/5 OK. Full Stage2 module rerun excludes new Stage3 RED test (preparation), pending.
- Stage3 tests/schema/DTO/message prepared independently while reviewer ran, not part of Stage2 commit. Production Stage3 evaluator/handler/query not implemented yet.
- Ruling: timeout after wrapInTransaction closes EM; map to listing_busy409 and stop remaining batch safely instead of reuse. User may retry remaining rows.

- Stage2 завершён:456 tests/1770 assertions, focused PHPStan OK; external2 rounds fixed without re-run.
- Stage3 RED3 errors: отсутствовали tables/message. Начаты новые изолированные DTO/message/migration/query/handler; для GREEN нужны shared evaluator, Marketplace facade methods, schema extension, routing.
