# Cash auto rules — Stage 7.8.2 Cashflow Project × ЦФО matrix report

## Status

- Phase: Stage C / Stage 7.8.2.
- Risk: MEDIUM.
- Branch: `agent/stage7-8-2-cashflow-matrix`.
- Base: stacked on Stage 7.11 branch until PR #2199 is merged.
- Next action: commit, push, and create Draft PR after final diff check.

## What was done

- Added an additive `projectCenterMatrix` payload to the existing ДДС cashflow report builder.
- Matrix groups the selected Cash transaction row set by:
  - project;
  - ЦФО;
  - currency;
  - existing report period buckets.
- Preserved the existing sign convention:
  - inflow positive;
  - outflow negative.
- Preserved Stage 7.8.1 selected-ЦФО behavior:
  - category totals are filtered by selected ЦФО;
  - matrix is filtered by the same selected ЦФО;
  - opening/closing account balances remain company-wide.
- Added the matrix to JSON export additively.
- Rendered the same matrix on the existing Cashflow page in both directions:
  - `ЦФО → проекты`;
  - `Проект → ЦФО`.
- Kept legacy incomplete facts visible:
  - `NULL` ЦФО = `Не задано`;
  - missing project = `Без проекта`.
- No migration, no production mutation, no queue, no import, no backfill, no recalculation.

## Files changed

- `ARCHITECTURE.md` — Stage 7.8.2 contract and Stage 7.11 runtime-schema note.
- `docs/reviews/cash-auto-rules-stage-7-8-2-phase0.md` — Stage C Phase 0 plan.
- `docs/reviews/cash-auto-rules-stage-7-8-2-report.md` — this report.
- `docs/reviews/cash-auto-rules-stage-7-current-status.md` — current linear status.
- `site/src/Report/Cashflow/CashflowReportBuilder.php` — matrix aggregation.
- `site/src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php` — additive matrix JSON output.
- `site/templates/report/cashflow.html.twig` — matrix UI block.
- `site/tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php` — matrix builder coverage.
- `site/tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php` — JSON contract coverage.
- `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php` — real controller/DQL/JSON/UI coverage.

## Definition of Done

- [x] Existing ДДС category totals remain supported.
- [x] Existing selected-ЦФО filter still filters category totals and now filters matrix totals.
- [x] Matrix groups by project × ЦФО × currency × period.
- [x] UI shows both directions: ЦФО → projects and project → ЦФО.
- [x] JSON export includes `projectCenterMatrix` additively.
- [x] Invalid/foreign/archived ЦФО behavior remains unchanged through the existing mapper.
- [x] No migration, production mutation, import, queue, backfill, or recalculation.
- [x] Targeted unit/functional checks pass.
- [x] Internal review has no unresolved BLOCKER/IMPORTANT findings.
- [x] External read-only Claude review returns `REVIEW_GREEN`.
- [ ] Draft PR created separately from Stage 7.11.

## Checks

- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php tests/Unit/Report/Cashflow/CashflowReportRequestMapperTest.php tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php tests/Functional/Finance/CashflowJsonExportControllerTest.php` — OK, 15 tests / 103 assertions.
- `make site-test-unit` — OK, 1522 tests / 8956 assertions.
- `docker compose run --rm -T site-php-cli php -l src/Report/Cashflow/CashflowReportBuilder.php` — OK.
- `docker compose run --rm -T site-php-cli php -l src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php` — OK.
- `docker compose run --rm -T site-php-cli php -l tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php` — OK.
- `docker compose run --rm -T site-php-cli php -l tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php` — OK.
- `docker compose run --rm -T site-php-cli php -l tests/Functional/Finance/CashflowJsonExportControllerTest.php` — OK.
- `docker compose run --rm -T site-php-cli php bin/console lint:twig templates/report/cashflow.html.twig --env=test` — OK.
- `docker compose run --rm -T site-php-cli php bin/console lint:container --env=test` — OK.
- `docker compose run --rm -T site-php-cli php vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php src/Report/Cashflow/CashflowReportBuilder.php src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php tests/Functional/Finance/CashflowJsonExportControllerTest.php` — OK.
- `git diff --check` — OK.

## Internal automatic review

- Iterations: 2.
- Findings fixed:
  - IMPORTANT: unit mocks did not prove the new DQL join; added functional JSON export coverage using real controller/Doctrine/query execution.
  - IMPORTANT: UI DoD was not covered; added functional page test for both `ЦФО → проекты` and `Проект → ЦФО`.
  - MINOR: targeted CS formatting fixes in builder/test files.
- BLOCKER: none.
- IMPORTANT: none remaining.
- FOLLOW-UP: none inside Stage 7.8.2.

## External Claude Code review

- Iterations: 1.
- Result: `REVIEW_GREEN`.
- Confirmed findings fixed: none.
- Rejected/deferred findings:
  - MINOR: `responsibility_center_name` is populated only for legacy `NULL` as `Не задано`; non-null ЦФО names remain resolved by UI active choices and JSON carries stable ids. This preserves the existing API pattern and avoids adding another repository dependency to the builder.
  - MINOR/FOLLOW-UP: archived ЦФО labels may fall back to raw UUID in this debug/report UI. This is cosmetic and out of Stage 7.8.2 unless archived-name display becomes a product requirement.
  - FOLLOW-UP: duplicated period lookup work is acceptable for the existing report size; optimize only after measured performance pressure.
  - FOLLOW-UP: `ARCHITECTURE.md` also records the Stage 7.11 runtime-schema cleanup because this branch is stacked on Stage 7.11; no executable Stage 7.8.2 behavior depends on that note.

## Risks / reviewer focus

- Matrix is additive; existing top-level JSON fields remain.
- CSV export is intentionally unchanged because the existing CSV contract is category/period rows, while matrix is exposed through UI and JSON.
- Matrix does not dimension account balances by ЦФО.
- Stage C branch is stacked on Stage B until #2199 is merged; Stage C PR should be retargeted to `master` after #2199 lands.

## Open questions

- none.
