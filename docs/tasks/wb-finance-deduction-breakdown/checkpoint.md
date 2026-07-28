## Current checkpoint

**Phase:** Release Gate
**Status:** checking
**Stage base commit:** `baf8a12b7e5570a8f0e0f846293d9a5316ae8b8a`
**Current Work item:** none
**Owner gate:** yes

### Completed

- Work item 1.1: memory-bounded aggregation of non-zero raw `deduction`
  values by exact `bonusTypeName` / `bonus_type_name`.
- Work item 1.2: linked page breakdown and matching spreadsheet-safe CSV
  section.
- Work item 1.3: unit, functional, tenant-isolation, filter, sign, fallback,
  invariant, and CSV-injection coverage plus operating documentation.
- Focused Work-item self-reviews and the integrated internal Stage review.
- Stage Report prepared.

### Current diff / affected files

- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/templates/marketplace/wb_finance_report.html.twig`
- `site/tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
- `site/tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
- `docs/tasks/wb-finance-raw-report/report.md`
- `docs/tasks/wb-finance-deduction-breakdown/`

### Checks and baseline

- Baseline: 13 tests, 63 assertions — green in Docker.
- Targeted after review fixes: 15 tests, 84 assertions — green.
- Marketplace unit + functional after review fixes: 554 tests,
  4446 assertions — green.
- PHP syntax, changed-file PHP CS, changed Twig syntax, Symfony container,
  scope check, and `git diff --check` — green.
- Host PHP is unavailable; the project Docker runtime is sufficient.

### Review status

- iteration: 3
- internal: green after all accepted safe MINOR fixes.
- external: three iterations ended `REVIEW_GREEN`; all safe in-scope MINOR
  findings fixed.
- accepted MINOR fixes: callback type, derived impact field, checkpoint format,
  and explicit `reportId` count label.
- unresolved findings: none.
- FOLLOW-UP: consider top-N/length limits only if real 93-day seller data
  demonstrates excessive unique `bonusTypeName` cardinality; pin CSV section
  positions only if machine consumers appear.

### Exact next action

- Commit only task-owned files, push the task branch, create the Draft PR, wait
  for CI status, then stop for the declared owner merge decision.

### Files to inspect first on resume

- `docs/tasks/wb-finance-deduction-breakdown/plan.md`
- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/templates/marketplace/wb_finance_report.html.twig`
- `site/tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
