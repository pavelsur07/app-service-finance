## Current checkpoint

**Phase:** Stage 1
**Status:** done
**Stage base commit:** `569035ac30b7315fcc6d4f795246b3ca9ef36388`
**Current Work item:** none
**Owner gate:** no

### Completed
- Phase 0 plan and baseline.
- Work item 1.1 — additive plural filter request contract.
- Work item 1.2 — tenant-safe Project/ЦФО filtering with project-subtree expansion and company-wide balances.
- Work item 1.3 — legacy public JSON/CSV compatibility coverage and architecture contract update.
- Stage 1 internal review and first external review cycle.

### Current diff / affected files
- `site/src/Report/Cashflow/` — request params, mapper, and report builder.
- `site/src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php` — protected export filter metadata.
- `site/tests/Unit/Report/Cashflow/` — mapper and builder regression coverage.
- `site/tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php` — response-shape coverage.
- `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php` — protected/public endpoint compatibility coverage.
- `ARCHITECTURE.md` and `docs/tasks/ui-cashflow-report-controls/` — contract and delivery records.

### Checks and baseline
- Baseline targeted unit: 8 tests, 50 assertions — green.
- Baseline functional Cashflow: 7 tests, 53 assertions — green.
- Current full unit: 1,896 tests, 10,834 assertions — green with 5 deprecations.
- Current targeted unit: 18 tests, 125 assertions — green.
- Current functional Cashflow: 9 tests, 76 assertions — green with 2 deprecations.
- Symfony `lint:container --env=test` — green.
- Full `composer cs:check` — pre-existing repository failure in 526/2,317 files; task-owned new lines corrected separately.

### Review status
- iteration: 4
- unresolved findings: none.
- external result: final `REVIEW_GREEN`; no BLOCKER or IMPORTANT findings.

### Exact next action
- Commit and push Stage 1, create/update the Draft PR, record the Stage 2 base commit, then continue autonomously with Work item 2.1.

### Files to inspect first on resume
- `site/src/Report/Cashflow/CashflowReportRequestMapper.php`
- `site/src/Report/Cashflow/CashflowReportBuilder.php`
- `site/tests/Unit/Report/Cashflow/CashflowReportRequestMapperTest.php`
- `site/tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php`
