## Current checkpoint

**Phase:** Stage 2
**Status:** complete
**Stage base commit:** `e601ddb2b1b914e6c34ab621eed80a1d204e2fc1`
**Current Work item:** 2.4 (complete)
**Owner gate:** no

### Completed

- Phase 0: правила и релевантные документы прочитаны.
- Изучены builder, query, controller, Twig, CSV, листинги и история цен.
- Зафиксированы финансовые правила и ограничения.
- Baseline зелёный.
- Work items 1.1–1.4: builder агрегирует валидные товарные строки по варианту
  SKU, сохраняет раздельные продажи/возвраты и cost-date buckets.
- Добавлены unit-тесты размеров, aliases, дат себестоимости, корректировок и
  строк без стабильного идентификатора.
- Документированы raw-поля и формулы SKU.
- Stage 1 internal review green.
- Stage 1 external review: 4 iterations, final `REVIEW_GREEN`.

### Current diff / affected files

- `site/src/Marketplace/Application/Service/WbRawFinancialReportProductEnricher.php`
- `site/src/Marketplace/Infrastructure/Query/WbRawFinancialReportProductQuery.php`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/tests/Integration/Marketplace/Application/Service/WbRawFinancialReportProductEnricherTest.php`
- `docs/tasks/wb-finance-sku-cost/plan.md`
- `docs/tasks/wb-finance-sku-cost/checkpoint.md`

### Checks and baseline

- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
  — `OK (15 tests, 116 assertions)`.
- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
  — `OK (12 tests, 85 assertions)`.
- Финальные targeted builder + controller:
  `OK (20 tests, 148 assertions)`.
- `make site-test-unit` — `OK (1642 tests, 9543 assertions)`.
- targeted PHP CS Fixer — `Found 0 of 2 files that can be fixed`.
- `git diff --check` — clean.
- `make site-cs-check` — pre-existing failure: 582/2153 files; task-owned
  PHP files pass the same fixer config.
- Stage 2 targeted integration + functional:
  `OK (8 tests, 111 assertions)`.
- Marketplace bounded-context suite after review fixes:
  `OK (786 tests, 5445 assertions)`.
- Symfony container lint: green.
- Stage 2 targeted PHP CS Fixer: green.
- `git diff --check`: clean.

### Review status

- iteration: 4
- confirmed findings fixed:
  - identifier lookup переведён на индексируемый `UNION` listing ids;
  - Money parse exceptions больше не подавляются;
  - barcode-only mapping и полный набор barcode покрыты тестом.
- unresolved findings: none
- external result: `REVIEW_GREEN`

### Exact next action

- Commit/push Stage 2, затем зафиксировать base Stage 3 и реализовать Twig
  block.

### Files to inspect first on resume

- `site/src/Marketplace/Application/Service/WbRawFinancialReportProductEnricher.php`
- `site/src/Marketplace/Infrastructure/Query/WbRawFinancialReportProductQuery.php`
- `site/tests/Integration/Marketplace/Application/Service/WbRawFinancialReportProductEnricherTest.php`
