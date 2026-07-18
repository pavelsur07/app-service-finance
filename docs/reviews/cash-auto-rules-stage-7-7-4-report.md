# Stage 7.7.4: P&L reports read-side Project × ЦФО — DONE

**Risk:** HIGH-LOCAL  
**Next action:** commit, push, Draft PR

## What was done

- Added optional read-only `responsibilityCenterId` filtering to P&L facts, calculator, period grid, and project comparison builders.
- Added company-safe ЦФО selection to the P&L preview page and JSON export.
- Added additive public JSON support for `responsibilityCenterId`.
- Added Project and ЦФО columns to the raw P&L debug page.
- Added regression coverage for calculator filter propagation and DB-level `pl_daily_totals` filtering.
- Updated architecture notes for Stage 7.7.4.

## Files changed

- `ARCHITECTURE.md` — Stage 7.7.4 architecture note.
- `docs/reviews/cash-auto-rules-stage-7-7-4-phase0.md` — Phase 0 plan.
- `docs/reviews/cash-auto-rules-stage-7-7-4-checkpoint.md` — resumable checkpoint.
- `site/src/Finance/Controller/Api/PublicPlReportController.php` — public JSON filter support.
- `site/src/Finance/Controller/PlReportPreviewController.php` — preview filter support and state preservation.
- `site/src/Finance/Controller/RawPlReportController.php` — raw debug Project/ЦФО labels.
- `site/src/Finance/Facts/FactsProviderInterface.php` — optional ЦФО filter parameter.
- `site/src/Finance/Facts/NullFactsProvider.php` — interface compatibility.
- `site/src/Finance/Facts/PLDailyTotalFactsProvider.php` — DB aggregation filter.
- `site/src/Finance/Report/PlReportCalculator.php` — filter propagation through leaf and formula fallback lookups.
- `site/src/Finance/Report/PlReportGridBuilder.php` — period-grid filter propagation.
- `site/src/Finance/Report/PlReportProjectsCompareBuilder.php` — project comparison filter propagation.
- `site/templates/finance/report/preview.html.twig` — ЦФО selector and query/form state.
- `site/templates/finance/reports/pl_raw.html.twig` — raw debug columns.
- `site/tests/Integration/Finance/Facts/PLDailyTotalFactsProviderTest.php` — DB regression test.
- `site/tests/Unit/Finance/Report/PlReportCalculatorTest.php` — calculator propagation regression test.

## Definition of Done

- [x] P&L preview accepts optional `responsibilityCenterId`.
- [x] Period layout filters by Project and/or selected ЦФО.
- [x] Project comparison layout compares projects inside selected ЦФО.
- [x] JSON export preserves selected ЦФО in metadata.
- [x] Public P&L JSON endpoints support the same optional ЦФО filter additively.
- [x] Raw P&L debug page shows Project and ЦФО for operations and daily totals.
- [x] Invalid, foreign, or archived ЦФО ids are ignored at controller boundary.
- [x] Existing behavior without `responsibilityCenterId` remains unchanged.
- [x] No migrations, no history recalculation, no production/staging mutation.

## Baseline

- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Finance/Report/PlReportCalculatorTest.php` — OK, 1 test, 4 assertions.

## Checks

- targeted: `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Finance/Report/PlReportCalculatorTest.php tests/Integration/Finance/Facts/PLDailyTotalFactsProviderTest.php` — OK, 3 tests, 13 assertions.
- module: `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Finance tests/Integration/Finance` — OK, 61 tests, 266 assertions.
- unit: `make site-test-unit` — OK, 1517 tests, 8919 assertions.
- DI: `docker compose run --rm -T site-php-cli php bin/console lint:container --env=test` — OK.
- Twig: `docker compose run --rm -T site-php-cli php bin/console lint:twig templates/finance/report/preview.html.twig templates/finance/reports/pl_raw.html.twig --env=test` — OK.
- CS: `docker compose run --rm -T site-php-cli sh -lc 'PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php ...'` — OK, 0 fixable files.
- diff hygiene: `git diff --check` — OK.
- API types equivalent:
  - `docker compose run --rm -T site-php-cli sh -lc 'php bin/console nelmio:apidoc:dump --format=json > var/openapi.json'` — OK.
  - `docker compose run --rm -T site-frontend sh -lc 'npx openapi-typescript var/openapi.json -o /tmp/schema.check.d.ts && diff /tmp/schema.check.d.ts assets/api/schema.d.ts'` — OK.

Known unrelated failure:

- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Functional/Finance` — FAIL in `SoftDeleteExclusionRegressionTest::testAutoRuleCheckPreviewExcludesSoftDeletedTransactions`. The fixture creates 2024 transactions while the default preview window on 2026-07-18 is 2026-01-18—2026-07-18. Stage 7.7.4 does not touch Cash auto-rule preview.

## Internal automatic review

- Iterations: 2.
- BLOCKER: none.
- IMPORTANT: none.
- MINOR fixed: removed redundant active-state check after `getActiveChoices()`.
- FOLLOW-UP: optional raw P&L debug filtering by ЦФО, intentionally outside this stage.

## External Claude Code review

- Iterations: 3 command attempts:
  - attempt 1: failed with `Reached max turns (20)`;
  - attempt 2: `REVIEW_GREEN` with one MINOR and one FOLLOW-UP;
  - attempt 3 after MINOR fix: `REVIEW_GREEN`.
- Confirmed findings fixed:
  - MINOR: removed redundant `isActive()` check in preview resolver.
- Rejected findings with reason: none.

## Risks / reviewer focus

- `responsibilityCenterId` is intentionally additive and optional.
- Specific ЦФО filter excludes the legacy `NULL` bucket; unfiltered report still includes all buckets.
- Invalid, foreign, and archived ЦФО ids are ignored, matching the existing lenient project-filter behavior.
- Raw debug page displays ЦФО labels only; it does not add a raw-page ЦФО filter in this stage.

## Checkpoint

- `docs/reviews/cash-auto-rules-stage-7-7-4-checkpoint.md` updated.
- Exact next action: commit current task files, push branch, create Draft PR.

## Open questions

- none.
