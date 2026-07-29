## Current checkpoint

**Phase:** Final Release Gate
**Status:** done
**Stage base commit:** `92cccfae0a9d00b7b6302178c4693c445a464bc9`
**Current Work item:** 3.5 (complete)
**Owner gate:** yes

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
- Stage 2: tenant-scoped listing/cost query, историческая себестоимость,
  coverage и результат по SKU реализованы и запушены.
- Stage 2 internal/external review green.
- Work items 3.1–3.4: Twig summary и таблица SKU, CSV parity, empty/missing/
  negative states, порядок блоков и spreadsheet safety реализованы.
- Документация формул и пользовательских оговорок обновлена.
- Stage 3 internal/external review green.
- Stage 3 Stage Report и handoff подготовлены.
- Final full-task external review от task base: `REVIEW_GREEN`.

### Current diff / affected files

- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/templates/marketplace/wb_finance_report.html.twig`
- `site/tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
- `site/src/Marketplace/WB_API_V5_FIELDS.md`
- `docs/tasks/wb-finance-sku-cost/plan.md`
- `docs/tasks/wb-finance-sku-cost/checkpoint.md`
- `docs/tasks/wb-finance-sku-cost/stages/stage-3.md`
- `docs/tasks/wb-finance-sku-cost/handoff.md`

### Checks and baseline

- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
  — `OK (15 tests, 116 assertions)`.
- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
  — `OK (12 tests, 85 assertions)`.
- Финальные targeted builder + controller:
  `OK (20 tests, 148 assertions)`.
- `make site-test-unit` — `OK (1645 tests, 9560 assertions)`.
- targeted PHP CS Fixer — `Found 0 of 2 files that can be fixed`.
- `git diff --check` — clean.
- `make site-cs-check` — pre-existing failure: 582/2156 files; task-owned
  PHP files pass the same fixer config.
- Stage 2 targeted integration + functional:
  `OK (8 tests, 111 assertions)`.
- Marketplace bounded-context suite after review fixes:
  `OK (786 tests, 5445 assertions)`.
- Symfony container lint: green.
- Stage 2 targeted PHP CS Fixer: green.
- `git diff --check`: clean.
- Stage 3 targeted integration + functional after review fixes:
  `OK (10 tests, 173 assertions)`.
- Stage 3 controller functional:
  `OK (7 tests, 107 assertions)`.
- Marketplace bounded-context suite:
  `OK (787 tests, 5488 assertions)`.
- Twig lint: green.
- Symfony container lint: green.
- Stage 3 targeted PHP CS Fixer: green.

### Review status

- iteration: 3
- confirmed findings fixed:
  - рентабельность явно определена относительно нетто-продаж без СПП;
  - HTML показывает sales reconciliation и alerts даже без SKU-строк;
  - проценты локализованы, KPI и footer сделаны читаемыми;
  - fallback/partial/conflict/unallocated и полная CSV-строка покрыты тестами.
- unresolved findings: none
- Stage 3 external result: `REVIEW_GREEN`
- Final full-task external result: `REVIEW_GREEN`

### Exact next action

- Commit/push Stage 3, обновить Draft PR, проверить CI и остановиться на
  owner Release Gate.

### Files to inspect first on resume

- `site/templates/marketplace/wb_finance_report.html.twig`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
