## Current checkpoint

**Phase:** Stage 2
**Status:** planned
**Stage base commit:** `cbc5775c7474e266486c07ea49bcedc09f97bd09`
**Current Work item:** 2.1
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

- `docs/tasks/wb-finance-sku-cost/TASK.md`
- `docs/tasks/wb-finance-sku-cost/plan.md`
- `docs/tasks/wb-finance-sku-cost/checkpoint.md`
- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
- `site/src/Marketplace/WB_API_V5_FIELDS.md`
- `docs/tasks/wb-finance-sku-cost/stages/stage-1.md`

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

### Review status

- iteration: 4
- unresolved findings: none

### Exact next action

- Commit/push Stage 1, создать Draft PR и зафиксировать base Stage 2.

### Files to inspect first on resume

- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
