# moysklad-stock-sync — checkpoint

**Phase:** Stage 2 / handoff
**Status:** Stage 2 complete; handoff in progress
**Stage base commit:** `06ef7968e61216f4b5898ce382e884bfbc737b1a`
**Branch:** `feat/moysklad-stock-snapshot-loader`
**PR:** not created yet

## Completed

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

- Stage 2 baseline MoySklad unit — 143 tests, 499 assertions, PASS.
- Stage 2 baseline MoySklad integration — 60 tests, 302 assertions, PASS.
- Final MoySklad unit+integration — 246 tests, 1003 assertions, PASS.
- `make site-stan` — PASS, 2686 files, no errors.
- `make site-cs-check` — PASS, 2701 files.
- `make site-cs-strict-types` — PASS, 2701 files.
- `make site-test-unit` — 3023 tests, 16503 assertions, PASS;
  4 existing deprecations.
- `make site-test` — 5221 tests, 29540 assertions, PASS;
  6 existing deprecations.

## Review status

- internal: 0 BLOCKER, 3 IMPORTANT and 1 MINOR found and fixed; open: none.
- external round 1: 0 BLOCKER, 2 IMPORTANT and 3 MINOR; both IMPORTANT and two
  MINOR fixed; one MINOR rejected because the task forbids duplicate assortment
  IDs regardless of type; result `fixed without re-run`.

## Exact next action

- Commit, push, create the Draft PR, add handoff metadata and mark Ready.

## Files to inspect first on resume

- `docs/tasks/moysklad-stock-sync/plan.md`
- `site/src/MoySklad/Application/Action/SyncStockSnapshotAction.php`
- `site/src/MoySklad/Application/StoreSyncRunner.php`
- `site/src/MoySklad/Application/StockSnapshotRunner.php`
- `site/src/MoySklad/MessageHandler/SyncStockSnapshotHandler.php`
