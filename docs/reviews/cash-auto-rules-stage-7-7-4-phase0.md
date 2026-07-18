# Cash auto rules — Stage 7.7.4 Phase 0

## Scope

Stage 7.7.4 connects the existing P&L read side to the `Project × ЦФО` daily-total key introduced in Stage 7.7.3.

This stage must not:

- create or apply database migrations;
- run a historical P&L rebuild;
- mutate production/staging data;
- change P&L formulas, signs, periods, or amount semantics;
- replace the existing Twig report UI with React.

## Current patterns inspected

- `PlReportPreviewController` resolves the active company, project filter, grouping, layout, JSON export, and recalc redirect parameters.
- `PlReportGridBuilder` builds period columns and delegates facts to `PlReportCalculator`.
- `PlReportProjectsCompareBuilder` builds the existing project comparison layout by calling the same calculator per project.
- `PLDailyTotalFactsProvider` is the read-side source of truth for P&L facts from `pl_daily_totals`.
- `FinancialResponsibilityCenterFacade::getActiveChoices()` and `findByIdAndCompany()` are the existing Company boundary for ЦФО labels and company isolation.

## Definition of Done

- P&L preview accepts an optional `responsibilityCenterId` query filter.
- Period layout filters values by selected Project and/or selected ЦФО.
- Project comparison layout can compare projects inside the selected ЦФО.
- JSON export preserves the selected ЦФО in metadata and values.
- Public P&L JSON endpoints support the same optional ЦФО filter additively.
- Raw P&L debug page shows Project and ЦФО for document operations and daily totals.
- Invalid or foreign ЦФО ids are ignored like invalid project ids; no IDOR leak.
- Existing behavior without `responsibilityCenterId` remains unchanged.
- No history recalculation or production mutation is performed.

## Implementation plan

1. Extend the internal facts contract:
   - add optional `?string $responsibilityCenterId = null` to `FactsProviderInterface::value()`;
   - pass it through `PlReportCalculator`, `PlReportGridBuilder`, and `PlReportProjectsCompareBuilder`;
   - filter `PLDailyTotalFactsProvider` by `dt.responsibilityCenterId` when provided.

2. Extend controllers:
   - resolve active ЦФО choices through `FinancialResponsibilityCenterFacade`;
   - pass selected ЦФО to grid/project builders;
   - preserve it in preview JSON, recalc redirects, and public JSON endpoints.

3. Extend Twig read-side:
   - add a ЦФО select to the existing offcanvas filter;
   - preserve the parameter in JSON download and recalc form;
   - show Project/ЦФО columns in the raw P&L debug tables.

4. Tests and documentation:
   - add regression coverage that the calculator passes the responsibility-center filter to facts;
   - add integration coverage for `PLDailyTotalFactsProvider` filtering by ЦФО;
   - update `ARCHITECTURE.md`, checkpoint, and Stage Report.

## Risk

**MEDIUM** — additive read-side contract/UI/API change. No schema change and no data mutation.

## Baseline

- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Finance/Report/PlReportCalculatorTest.php` — OK, 1 test, 4 assertions.

## Planned checks

- targeted unit: `tests/Unit/Finance/Report/PlReportCalculatorTest.php`
- targeted integration: new/updated facts-provider integration test
- module: relevant Finance integration/unit tests
- Twig lint for changed templates
- targeted php-cs-fixer dry-run on changed PHP files
- external read-only Claude Code review
