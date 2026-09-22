# moysklad-stock-sync — checkpoint

**Phase:** Stage 4 complete / handoff
**Status:** handoff complete; preparing Ready PR
**Stage base commit:** `b47e6875705803bc6e12866c65dc51b14469040e`
**Branch:** `feat/moysklad-stock-sync-ui`
**PR:** pending

## Completed

- Stage 1 released in PR #2498; Stage 2 released in PR #2499 and is present in
  the Stage 3 base `origin/master`.
- Stage 3/4 delivery split recorded: backend POST/read model first, Legacy Twig
  UI and handoff second.
- Stage 3 endpoint dispatches `SyncStockSnapshotMessage` only for a verified,
  active tenant-owned connection after UUID, permission and CSRF checks.
- Generic batch `MoySkladSyncStatusQuery` supports all five streams and reads
  the latest completed stock snapshot with line count without N+1.
- Stage 3 internal review complete; no BLOCKER/IMPORTANT/MINOR.
- Stage 4 adds store/stock empty/running/succeeded/failed UI, cursor/history,
  completed snapshot details and permission-aware recovery form.
- Добавлен последовательный полный проход активных и архивных складов с
  отдельным `store` run/cursor и tenant-scoped upsert.
- Добавлена загрузка сырого `/report/stock/bystore`, постраничная запись строк
  и публикация снимка только после проверки `meta.size`, дубликатов и ссылок.
- HTTP выполняется вне транзакций; каждая страница и финальная публикация
  атомарны, а ошибка stock не откатывает успешный проход складов.
- Добавлены advisory lock, восстановление зависших runs/snapshots и безопасное
  закрытие failed snapshot даже при закрытом EntityManager.
- Добавлены Message/Handler на `async_sync` с ручным bounded retry только для
  `rate_limited` и `temporary`.
- Покрыты первичная и повторная загрузка, pagination/drift/duplicates,
  unknown references, empty report, tenant isolation, lock и recovery.

## Checks and baseline

- Stage 3 baseline `ConnectionsControllerTest` — 23 tests, 158 assertions, PASS.
- RED endpoint — 4 tests failed on missing route; GREEN — 4 tests, 18 assertions.
- RED read model — missing `MoySkladSyncStatusQuery`; GREEN — 1 test, 8 assertions.
- Stage 3 functional — 28 tests, 184 assertions, PASS.
- focused PHP CS Fixer — 4 files, 0 fixable; focused PHPStan — no errors.
- RED Twig UI — 3 failures on absent sections; GREEN — 4 tests, 42 assertions.
- Stage 4 functional after review fixes — 32 tests, 232 assertions, PASS.
- focused Twig CS — no violations; focused PHP CS/PHPStan — green.
- frontend lint/build — PASS; global UI Kit checks remain at pre-existing
  baselines (8996 class findings, 47 React mapping findings), with no related
  files or new CSS definitions in this diff; only existing Tabler utilities.
- Stage 2 baseline MoySklad unit — 143 tests, 499 assertions, PASS.
- Stage 2 baseline MoySklad integration — 60 tests, 302 assertions, PASS.
- Final MoySklad unit+integration — 246 tests, 1003 assertions, PASS.
- `make site-stan` — PASS, 2687 files, no errors.
- `make site-cs-check` — PASS, 2702 files.
- `make site-cs-strict-types` — PASS, 2702 files.
- `make site-test-unit` — 3023 tests, 16503 assertions, PASS;
  4 existing deprecations.
- `make site-test` — 5230 tests, 29611 assertions, PASS;
  6 existing deprecations.

## Review status

- Stage 3 internal: iteration 1, 0 BLOCKER, 0 IMPORTANT, 0 MINOR; open none.
- Handoff internal: iteration 1, 0 BLOCKER, 1 IMPORTANT and 1 MINOR found and
  fixed; open none.
- Handoff external: round 1, `REVIEW_GREEN`, 0 BLOCKER, 0 IMPORTANT.

## Exact next action

- Commit the handoff review fixes, push and create/mark the Draft PR Ready.

## Files to inspect first on resume

- `docs/tasks/moysklad-stock-sync/plan.md`
- `site/tests/Functional/MoySklad/ConnectionsControllerTest.php`
- `site/src/MoySklad/Infrastructure/Query/MoySkladSyncStatusQuery.php`
- `site/src/MoySklad/Controller/MoySkladConnectionsController.php`
- `site/templates/moy_sklad/connections/index.html.twig`
