# moysklad-stock-sync — checkpoint

**Phase:** Stage 1 / handoff
**Status:** done locally, release in progress
**Stage base commit:** `13f706a92e847eb21d1c82c303257b5196735b3e`
**Branch:** `feat/moysklad-stock-sync`
**PR:** [#2498](https://github.com/pavelsur07/app-service-finance/pull/2498)

## Completed

- Зафиксирован контракт `/entity/store` и `/report/stock/bystore` по
  официальной JSON API 1.2 ревизии `74f6a4fa`.
- Добавлены tenant-scoped склады, неизменяемые снимки остатков и строки снимка
  с product/variant XOR, составными FK и decimal `numeric(30,10)`.
- Добавлены строгие постраничные parser/DTO, безопасная категория ошибки,
  обезличенные JSON fixtures и тесты parser/entity/storage.
- Миграция проверена вверх/вниз на пустой БД; rollback с данными отказал без
  удаления данных, как предусмотрено guard.
- Состояния terminal snapshot и его строки защищены триггерами БД.
- Обычная и экспоненциальная запись decimal нормализуются точно, до float.

## Checks and baseline

- baseline `make site-test-unit` — 2965 tests, 16341 assertions, PASS;
  4 существующих deprecations.
- `make site-stan` — PASS, no errors.
- `make site-cs-check` — PASS, 2693 files.
- `make site-cs-strict-types` — PASS, 2693 files.
- `make site-test-unit` — 3006 tests, 16453 assertions, PASS;
  4 существующих deprecations.
- `make site-test` — 5174 tests, 29330 assertions, PASS;
  6 существующих deprecations.
- current MoySklad module after review fixes — 201 tests, 797 assertions, PASS.
- migration down/up and guarded non-empty rollback — PASS.

## Review status

- internal: 7 iterations; 9 IMPORTANT fixed; BLOCKER open: none;
  final result `REVIEW_GREEN`.
- external: round 1; 1 IMPORTANT fixed and verified internally without re-run;
  result `fixed without re-run`; BLOCKER open: none.

## Exact next action

- Commit and push Stage 1, mark PR #2498 Ready, wait for required checks and
  merge into `master` under the owner's existing approval.

## Files to inspect first on resume

- `site/src/MoySklad/Application/StockReportPageParser.php`
- `site/migrations/Version20260921100000.php`
- `site/tests/Integration/MoySklad/StockStorageTest.php`
