## Current checkpoint

**Phase:** Release Gate
**Status:** done
**Stage base commit:** f6ba0e628407e30cdeda33357312af4ebcf2d5a5
**Current Work item:** none
**Owner gate:** yes

### Completed
- Confirmed `master` and `origin/master` at the Stage base commit.
- Recorded applicable instruction hashes after switching to the task branch.
- Created `task/finance-dashboard-kpi-turnover-fix`.
- Ran the pre-change targeted baseline.
- Added a regression test for opposite directions on a parent and child cashflow category.
- Proved the regression red on the old implementation: expected gross inflow `100.00`, received netted `20.00`.
- Added direct split-based gross inflow/outflow aggregation with company, currency, date, soft-delete, transfer, technical, activity, and unallocated filters.
- Switched the KPI provider from the netted cashflow tree to the direct aggregate and removed the invalid recursive decomposition.
- Added coverage for mixed parent/child signs, cashflow net reconciliation, multi-splits, activity selection, transfers, technical and unallocated categories, currency, tenant isolation, soft delete, and missing splits.
- Completed all Stage checks, internal review, external review-fix cycles, and the Stage Report.

### Current diff / affected files
- Task implementation, integration tests, and delivery documents are complete.
- Unrelated owner working-tree changes remain present and are excluded from task scope.

### Checks and baseline
- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/Application/Service/FinanceDashboardKpiProviderTest.php tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php` — OK, 7 tests, 85 assertions.
- Regression-only old-code proof — expected failure, 1 test: expected `100.00`, actual `20.00`.
- Changed implementation targeted test — OK, 3 tests, 26 assertions.
- PHP syntax checks for all changed PHP files — OK in the PHP CLI container; host PHP is unavailable.
- Full relevant Stage tests — OK, 25 tests, 198 assertions.
- Symfony container lint — OK.
- Task-scoped PHP CS Fixer dry-run — OK, 0 of 3 files need fixes.
- Repository-wide `make site-cs-check` — pre-existing failure: 522 of 2323 unrelated files need formatting; no task-owned file was reported and no files were rewritten.
- Repeated Stage tests after review fixes — OK, 25 tests, 198 assertions; one pre-existing PHP 8.4 deprecation in `AppLogger::error()` was reported.

### Review status
- internal iteration: 3, green
- external completed iterations: 3; final result `REVIEW_GREEN`
- unresolved findings: none

### Exact next action
- Commit only task-owned files, push without force, create a Draft PR with base `master`, then wait for the explicit merge and automatic production deploy decision.

### Files to inspect first on resume
- `site/src/Finance/Application/Service/FinanceDashboardKpiProvider.php`
- `site/src/Cash/Repository/Transaction/CashTransactionRepository.php`
- `site/tests/Integration/Finance/Application/Service/FinanceDashboardKpiProviderTest.php`
