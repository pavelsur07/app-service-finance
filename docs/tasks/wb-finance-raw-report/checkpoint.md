## Current checkpoint

**Phase:** Release Gate
**Status:** checking
**Stage base commit:** `b1a49db20b1f3d907b914703f3931cd39875787d`
**Current Work item:** none
**Owner gate:** yes

### Completed

- Phase 0 plan, baseline, and Stage Definition of Done.
- Work item 1.1: tenant-scoped iterative raw query and exact report builder.
- Work item 1.2: GET page, CSV export, navigation, and UI states.
- Work item 1.3: unit/functional coverage and report documentation.
- Full relevant Stage checks and internal review-fix cycles.
- External read-only review-fix cycles; final result: `REVIEW_GREEN`.

### Current diff / affected files

- `docs/tasks/wb-finance-raw-report/` — plan, report documentation, checkpoint, Stage Report.
- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/src/Marketplace/Infrastructure/Query/WbRawFinancialReportQuery.php`
- `site/src/Twig/CurrencyFormatExtension.php`
- `site/templates/marketplace/layout.html.twig`
- `site/templates/marketplace/wb_finance_report.html.twig`
- `site/tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
- `site/tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
- `site/tests/Unit/Twig/CurrencyFormatExtensionTest.php`

### Checks and baseline

- Baseline: existing WB sync-status functional test — 4 tests, 16 assertions, green.
- Final targeted: report unit/functional/Twig tests — 14 tests, 65 assertions, green.
- Final module/relevant: 479 tests, 4,006 assertions, green.
- Twig syntax: all 218 templates green.
- Symfony test container lint: green.
- PHP CS for all changed PHP files using the active project config: green.
- Full repository PHP CS remains red on 583 pre-existing unrelated files.
- Full repository Twig-CS remains red on 509 pre-existing unrelated violations; Twig syntax is green.

### Review status

- Internal review iterations: 4.
- External completed review iterations: 4, plus two prescribed max-turn retries.
- Final external result: `REVIEW_GREEN`.
- Unresolved BLOCKER findings: none.
- Unresolved IMPORTANT findings: none.
- Follow-up: duplicate `rrdId` rows are reported but remain included in raw totals pending an explicit financial-semantics decision.
- Follow-up: aggregate overflow belongs in the shared `Money` VO; driver buffering and the period-wide `rrdId` set may need a different architecture for very large tenants.

### Exact next action

- Commit and push the task-owned Stage diff, create/update the single Draft PR, then update this checkpoint with the publication result and stop at the declared owner Release Gate.

### Files to inspect first on resume

- `docs/tasks/wb-finance-raw-report/plan.md`
- `docs/tasks/wb-finance-raw-report/stages/stage-1.md`
- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/src/Marketplace/Infrastructure/Query/WbRawFinancialReportQuery.php`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
