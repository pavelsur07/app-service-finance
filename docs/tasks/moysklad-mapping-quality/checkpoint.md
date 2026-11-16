## Current checkpoint

**Phase:** Stage 4 / handoff verification
**Status:** verification in progress
**Stage base commit:** 8372aa8f9

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

### Stage3 progress
- Work item3.1: run/row schema, DTO/message/query/handler; migration Version20261116122000 applied isolated test DB. Shared evaluator + keyset/fingerprint MarketplaceFacade implemented.
- Diagnostics RED3 (missing schema/message) → GREEN3 tests/25 assertions:205 rows/3 batch transactions, retry/no duplicates, failure preserves last completed and resumes100, stale filters/scoping.
- Added in-flight mutation and assignment/confirmation invalidation tests; run pending.
- Found prior-rule cached preview surfaced as503; regression RED proved it; version mismatch now treated as expired.
- PR edit via old gh GraphQL failed (deprecated projectCards); REST PATCH body update succeeded; branch push58c4f2c65 succeeded.

- Stage3 полный module461/1803 OK; focused PHPStan corrected run OK. Internal IMPORTANT1 old-preview version503 fixed, no open findings.
- Stage3 external Claude529 Overloaded (not green); Codex read-only fallback running, logs site/var/external-review/58c4f2c6-codex/.
- Exact next action: review fallback result, close/commit/push Stage3; Stage4 tests (settings CSRF/permissions, diagnostics states/filters, explicit package review fingerprints) then UI implementation.

- Stage3 external fallback Codex REVIEW_GREEN,0 BLOCKER/IMPORTANT; Stage3 завершён. Stage4 next: перенести подготовленный /tmp/mapping-quality-ui-test.php в Functional/MoySklad, выполнить RED, затем settings/refresh controllers и legacy templates.

### Stage4 progress
- UI RED3 selector failures; settings Action RED missing service. Реализованы settings/refresh controllers, WB Action(connection FOR UPDATE), ручной checkbox+source/target fingerprints, evidence и diagnostics partials.
- Первый targeted5 tests:51 assertions,1 test fixture detached-company после404; исправлено fresh reference.
- Найден общий pagination helper без pageParam: diagnostic pagination regression RED; добавлен совместимый optional pageParam, default page сохранён.
- Stage4/handoff review point объединяем: это последний Stage с application code; полные гейты выполняются один раз после UI, затем fresh internal + external полного task diff и отчёты Stage4/5/handoff. Это одновременно проверка HIGH-LOCAL Stage>500 и handoff без лишнего повторного раунда.

- Stage4 targeted65 tests/494 assertions green (UI/Action/reason flags). Дополнительные regression RED: сохранять page main-list при навигации diagnostic; показывать package reason наряду с ambiguity/no-match. Исправлены; повтор final targeted pending.
- Browser infrastructure discovered: existing vf-finance/auth-playwright:local image and cached /tmp/vf-cash-browser/ui/node_modules/playwright; no dependencies installed. Captured fixture HTML in site/var for desktop/mobile smoke.
- Local vendor copy prepared under site/var/mapping-quality-vendor (own writable) for exact make gates/composer; initial copy into Docker-created root-owned site/vendor failed, changed destination successfully. No permissions changed.

- Stage4 final regressions12 tests/92 assertions green: manual exact barcode search (RED→GREEN), diagnostic reasons and both independent paginators, risky manual checkbox/stale fingerprints.
- Frontend first lint passed, build failed because shared node_modules mount read-only for .vite-temp; corrected environment gives own writable .vite-temp/.vite cache mounts, no permission broadening or dependency changes.
- Local Composer install from lock succeeded with no dependency changes; owned vendor copy used for exact make gates. Combined Stage4/5 full verification starts now.

### Full gates progress
- Frontend lint/build/152 unit tests GREEN after own writable Vite-cache mounts.
- First full make site-stan ended incomplete: "Some parallel worker jobs have not finished" under host6GB RAM/full2GB swap; tool runtimes also restarted. Not a green result. Local ignored site/phpstan.neon limits parallel workers to1, includes unchanged project config/paths/rules/baseline. Retry planned after remaining gate runner finishes; no committed CI/config changes.
- make cs/strict/unit/Twig runner continues sequentially, logs /tmp/mapping-quality-gate-*.log and site/var/mapping-quality-gates.json.

- Второй full PHPStan с parallel workers1 также incomplete (parallel worker jobs not finished); третья попытка materially different: composer stan -- --debug полностью отключает parallel protocol, все rules/paths/baseline сохранены. Запущена через локальный adapter env MAPPING_QUALITY_STAN_DEBUG=1.
- Final internal IMPORTANT: report мог ложно оставаться fresh после смены marketplace листинга и отдельного package confirmation старой ревизии. Regression RED воспроизведён; versions теперь conservatively включают все assignment/review revisions подключения, настройки остаются scoped по marketplace. GREEN повтор pending.
- Документ ARCHITECTURE обновлён на actual identity_package_v2, UI contracts; старый v1 текст удалён.

- Third full local PHPStan (--debug/no parallel) terminated exit137 after source + part of integration tests, not green. Need standard full CI result on final branch; all changed paths additionally analysed locally with unchanged level8/rules/baseline (unmatched baseline warnings disabled only for focused scope).
- Final focused analysis found3 test typing errors: transport declared TransportInterface did not expose InMemory getSent; explicit runtime InMemoryTransport assertion added. Corrected focused check pending.
- Provider-drift diagnostic regression GREEN:5 tests/36 assertions after conservative connection-wide revision/review version hash.

- Full CI Stage3 findings obtained through REST job logs (old gh --log-failed returned empty): fixture PHPDoc guard lacked required candidateFingerprint/other; SchemaInSync test rejected our deprecated Table::setPrimaryKey. Fixed fixture contract and target validation; schema now reuses existing project addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()) pattern. No baseline suppression or dependency change.
- Final internal IMPORTANT: persisted row reasons were recalculated on read; regression RED proved old completed report gained package reason without recalculation. Reader now preserves recorded reasons/manual flag. GREEN pending.
- Browser desktop1280/mobile390 smoke passed for report/manual form, no body overflow; screenshot review corrected new small filter/save buttons to standard existing btn style and prevents mid-word reason wrapping. Scripts/remote application requests blocked during fixture smoke, static CSS/fonts only.

- Final targeted fixes GREEN13 tests/106 assertions (recorded report reasons, schema API, settings/UI/diagnosis); focused PHPStan including worker fixtures GREEN. Full make site-test launched once; Stage4 checkpoint commit triggers fresh CI before final review.
