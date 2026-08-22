## Current checkpoint

**Phase:** Stage 1
**Status:** done
**Stage base commit:** `3fc4e02f427e042d801186fc3bbcaa63f315359f`
**Current Work item:** none — integrated Stage review
**Owner gate:** no

### Completed

- Phase 0: scope, risk, acceptance and exclusions recorded in `plan.md`.
- 1.1: KPI provider returns exact current/previous windows and balance comparison date.
- 1.2: authenticated-only opt-in request mapping; public calls retain default behavior.
- 1.3: report transaction rows use the dashboard currency/activity/exclusion scope and expose an exact bcmath summary from the existing KPI aggregator.
- 1.4: integration and functional coverage for transfers, technical/unallocated/deleted rows, splits, currency, company scope, authorized export and public ignore.
- Internal Stage review completed green before external review.
- External review iteration 1 hit the recoverable 40-turn limit; iteration 2 found one IMPORTANT missing row-total assertion and safe MINOR improvements.
- Confirmed IMPORTANT and safe MINOR fixes implemented: row-derived totals, default-export negative assertions, orphan-safe filtered tree, architecture contract note, resumable checkpoint format.

### Current diff / affected files

- Dashboard KPI provider and integration tests.
- Cashflow report params, mapper, builder, authenticated controller and JSON formatter.
- Cashflow request/builder/formatter/functional tests.
- `ARCHITECTURE.md` and task documents.
- No migrations, dependencies, UI Kit, Vite/React or production files.

### Checks and baseline

- Baseline relevant PHPUnit: PASS, 30 tests / 777 assertions; 3 pre-existing deprecations.
- Before external findings: relevant PHPUnit PASS, 38 tests / 832 assertions; 2 pre-existing deprecations.
- PHP syntax: PASS in project CLI image; host PHP unavailable.
- PHP CS Fixer for changed files: PASS after one mechanical formatter pass.
- `git diff --check`: PASS.
- Post-review-fix relevant PHPUnit: PASS, 38 tests / 839 assertions; 2 pre-existing deprecations.
- Regression proof: removing `applyDashboardScope()` makes the focused integration test fail (`1160.0` vs `160.0`); restoring it returns PASS, 1 test / 22 assertions.
- Post-review-fix PHP CS and `git diff --check`: PASS.

### Review status

- iteration: 4; final external review `REVIEW_GREEN`.
- unresolved findings: no BLOCKER/IMPORTANT.
- rejected MINOR: enum/value-object extraction is unnecessary for an allowlisted internal string and would add speculative abstraction; mapper validation remains the HTTP invariant.
- rejected MINOR: archived accounts remain included in company-wide report balances by approved report semantics; Stage 2 will state that balances are not scoped like movement rows.
- FOLLOW-UP: shared dashboard activity mapping may be consolidated in a separate task if further consumers appear.

### Exact next action

- Commit/push the reviewed Stage 1 task-owned diff, create/update the Draft PR with base `master`, then record the Stage 2 base and continue automatically.

### Files to inspect first on resume

- `site/src/Report/Cashflow/CashflowReportBuilder.php`
- `site/tests/Integration/Finance/Application/Service/FinanceDashboardKpiProviderTest.php`
- `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php`
- `docs/tasks/finance-dashboard-cashflow-reconciliation/plan.md`
