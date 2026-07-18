# Cash auto rules — Stage 7.7.4 checkpoint

## Current checkpoint

**Phase:** Stage 7.7.4 — P&L read-side Project × ЦФО  
**Status:** publishing

### Completed

- Created branch `agent/cash-stage7-7-4-pl-report-cfo-read-side`.
- Saved Phase 0 plan: `docs/reviews/cash-auto-rules-stage-7-7-4-phase0.md`.
- Added optional `responsibilityCenterId` read filter through:
  - `FactsProviderInterface`;
  - `PLDailyTotalFactsProvider`;
  - `PlReportCalculator`;
  - `PlReportGridBuilder`;
  - `PlReportProjectsCompareBuilder`.
- Added company-safe ЦФО selection to P&L preview and JSON export.
- Added additive public JSON support for `responsibilityCenterId`.
- Added Project/ЦФО columns to raw P&L debug output.
- Added unit and integration regression tests.
- Updated `ARCHITECTURE.md`.
- Completed internal review and external Claude Code review with final `REVIEW_GREEN`.
- Saved Stage Report: `docs/reviews/cash-auto-rules-stage-7-7-4-report.md`.

### Current diff / affected files

- `ARCHITECTURE.md`
- `docs/reviews/cash-auto-rules-stage-7-7-4-phase0.md`
- `docs/reviews/cash-auto-rules-stage-7-7-4-report.md`
- `site/src/Finance/Controller/Api/PublicPlReportController.php`
- `site/src/Finance/Controller/PlReportPreviewController.php`
- `site/src/Finance/Controller/RawPlReportController.php`
- `site/src/Finance/Facts/FactsProviderInterface.php`
- `site/src/Finance/Facts/NullFactsProvider.php`
- `site/src/Finance/Facts/PLDailyTotalFactsProvider.php`
- `site/src/Finance/Report/PlReportCalculator.php`
- `site/src/Finance/Report/PlReportGridBuilder.php`
- `site/src/Finance/Report/PlReportProjectsCompareBuilder.php`
- `site/templates/finance/report/preview.html.twig`
- `site/templates/finance/reports/pl_raw.html.twig`
- `site/tests/Integration/Finance/Facts/PLDailyTotalFactsProviderTest.php`
- `site/tests/Unit/Finance/Report/PlReportCalculatorTest.php`

### Checks and baseline

- Baseline:
  - `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Finance/Report/PlReportCalculatorTest.php` — OK, 1 test, 4 assertions.
- Current checks:
  - targeted: `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Finance/Report/PlReportCalculatorTest.php tests/Integration/Finance/Facts/PLDailyTotalFactsProviderTest.php` — OK, 3 tests, 13 assertions.
  - module: `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Finance tests/Integration/Finance` — OK, 61 tests, 266 assertions.
  - unit: `make site-test-unit` — OK, 1517 tests, 8919 assertions.
  - DI: `docker compose run --rm -T site-php-cli php bin/console lint:container --env=test` — OK.
  - Twig: `docker compose run --rm -T site-php-cli php bin/console lint:twig templates/finance/report/preview.html.twig templates/finance/reports/pl_raw.html.twig --env=test` — OK.
  - targeted CS: php-cs-fixer dry-run on changed PHP files — OK.
  - API types check equivalent:
    - `docker compose run --rm -T site-php-cli sh -lc 'php bin/console nelmio:apidoc:dump --format=json > var/openapi.json'` — OK.
    - `docker compose run --rm -T site-frontend sh -lc 'npx openapi-typescript var/openapi.json -o /tmp/schema.check.d.ts && diff /tmp/schema.check.d.ts assets/api/schema.d.ts'` — OK.
  - diff hygiene: `git diff --check` — OK.
- Known unrelated failure:
  - `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Functional/Finance` — FAIL, 1 unrelated pre-existing/stale Cash auto-rule test. `SoftDeleteExclusionRegressionTest::testAutoRuleCheckPreviewExcludesSoftDeletedTransactions` creates 2024 transactions but default preview window on 2026-07-18 is 2026-01-18—2026-07-18, so it renders zero matches. Current Stage 7.7.4 diff does not touch Cash auto-rule preview.

### Review status

- Internal automatic review:
  - iterations: 2.
  - unresolved findings: none.
- External Claude Code review:
  - final result: `REVIEW_GREEN`.
  - fixed finding: MINOR redundant active-state check in preview resolver.
  - unresolved findings: none.

### Exact next action

- Commit current task files.
- Push branch `agent/cash-stage7-7-4-pl-report-cfo-read-side`.
- Create Draft PR.

### Files to inspect first on resume

- `site/src/Finance/Facts/PLDailyTotalFactsProvider.php`
- `site/src/Finance/Report/PlReportCalculator.php`
- `site/src/Finance/Controller/PlReportPreviewController.php`
- `site/src/Finance/Controller/Api/PublicPlReportController.php`
- `site/tests/Integration/Finance/Facts/PLDailyTotalFactsProviderTest.php`
