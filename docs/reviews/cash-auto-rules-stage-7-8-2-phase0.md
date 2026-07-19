# Cash auto rules — Stage 7.8.2 Phase 0: Cashflow Project × ЦФО matrix

## Status

- Phase: Stage C / Stage 7.8.2 Phase 0.
- Scope: existing ДДС cashflow report/API only.
- Risk: MEDIUM — additive read-side aggregation and UI block on existing report.
- Code changes in this Phase 0: none yet.

## Goal

Close the remaining Stage 7 analytics gap without jumping back to old stage reports:

- show ЦФО → projects, for example `Краснодар → Продажа компьютеров / Сервисные услуги`;
- show project → ЦФО, for example `Продажа компьютеров → Краснодар / Ростов`;
- preserve existing category × period ДДС report behavior;
- avoid historical recalculation, backfill, imports, queues, migrations, or production mutation.

## Existing implementation inspected

1. `CashflowReportRequestMapper`
   - already resolves optional active company-owned `responsibilityCenterId`;
   - malformed, foreign, or archived ids are ignored.

2. `CashflowReportBuilder`
   - already builds periods once;
   - already filters transaction category rows by selected ЦФО;
   - keeps opening/closing account balances company-wide through an unfiltered company rollup;
   - currently selects category/direction/amount/currency/date only.

3. `ReportCashflowController` and `templates/report/cashflow.html.twig`
   - existing server-rendered Twig report already has date/group/ЦФО controls;
   - no React screen is needed for this stage.

4. `CashflowReportJsonFormatter`
   - public/internal JSON export is additive and can include extra payload keys without changing old fields.

5. Tests
   - existing unit tests cover mapper, builder filter behavior, and JSON formatter;
   - functional/export tests exist for public cashflow export and company isolation.

## Best-practice decision

Do the smallest read-side extension:

- add project and ЦФО scalar dimensions to the existing transaction rows query;
- aggregate signed cash movement into a `projectCenterMatrix` payload using the same period buckets as the main report;
- render two compact tables on the existing Cashflow page:
  - `ЦФО → проекты`;
  - `Проект → ЦФО`;
- include the matrix in JSON export;
- leave CSV export unchanged unless tests show the existing CSV contract requires matrix rows.

No new tables, services, Messenger jobs, or rebuild commands.

## Behavior contract

- Matrix is based on Cash transactions only.
- Deleted transactions remain excluded.
- Direction signs match the existing Cashflow report:
  - inflow positive;
  - outflow negative.
- When `responsibilityCenterId` is selected, the matrix is scoped to that ЦФО, same as category totals.
- When no ЦФО is selected, matrix includes all Cash rows in the selected period.
- Legacy `NULL` ЦФО rows appear under `Не задано`.
- Missing project appears under `Без проекта`.
- Account opening/closing balances remain company-wide and are not included in the Project × ЦФО matrix.
- Existing report fields remain backward-compatible.

## Definition of Done

- [ ] Existing ДДС category totals remain unchanged.
- [ ] Existing selected-ЦФО filter still filters category totals and now filters matrix totals.
- [ ] Matrix groups by project × ЦФО × currency × period.
- [ ] UI shows both directions: ЦФО → projects and project → ЦФО.
- [ ] JSON export includes `projectCenterMatrix` additively.
- [ ] Invalid/foreign/archived ЦФО behavior remains unchanged.
- [ ] No migration, production mutation, import, queue, backfill, or recalculation.
- [ ] Targeted unit/functional checks pass.
- [ ] Internal review has no unresolved BLOCKER/IMPORTANT findings.
- [ ] External read-only Claude review returns `REVIEW_GREEN`.
- [ ] Draft PR created separately from Stage 7.11.

## Expected files

- `site/src/Report/Cashflow/CashflowReportBuilder.php`
- `site/src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php`
- `site/templates/report/cashflow.html.twig`
- `site/tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php`
- `site/tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php`
- optionally `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php`
- `ARCHITECTURE.md`
- Stage report for Stage 7.8.2

## Checks

- Targeted:
  - `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php`
  - relevant public cashflow export functional test if JSON contract changes need browser-level coverage.
- Lint:
  - changed PHP files with `php -l`;
  - `bin/console lint:twig templates/report/cashflow.html.twig --env=test`;
  - `bin/console lint:container --env=test`;
  - targeted PHP CS Fixer dry-run;
  - `git diff --check`.
- Broader:
  - `make site-test-unit`.
- Review:
  - internal automatic review;
  - external read-only Claude Code review.

## Explicitly out of scope

- New standalone analytics screen.
- React/Vite work.
- CSV matrix expansion unless required by a failing compatibility test.
- Account balances by ЦФО.
- Any historical data writes or recalculation.
- Production import smoke.
- Pair-removal DB hardening.
- Optimizing the existing selected-ЦФО second query without measured pressure.

## Phase 0 conclusion

Stage 7.8.2 is implementable autonomously as an additive read-only extension to the existing Cashflow report. No additional owner business decision is required.
