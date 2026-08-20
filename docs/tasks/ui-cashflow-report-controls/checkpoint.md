## Current checkpoint

**Phase:** Release Gate
**Status:** stopped
**Stage base commit:** `c8f01a6e5ad4174505ad308850964d5117eea498`
**Current Work item:** none
**Owner gate:** yes

### Completed
- Phase 0 plan and baseline.
- Work item 1.1 — additive plural filter request contract.
- Work item 1.2 — tenant-safe Project/ЦФО filtering with project-subtree expansion and company-wide balances.
- Work item 1.3 — legacy public JSON/CSV compatibility coverage and architecture contract update.
- Stage 1 internal review and first external review cycle.
- Stage 1 committed as `c8f01a6e5ad4174505ad308850964d5117eea498`, pushed, and published in Draft PR #2348 with base `master`.
- Work item 2.1 — shared P&L/Cashflow Twig styles and behavior partials; P&L DOM/behavior preserved.
- Work item 2.2 — Cashflow period, month/quarter/year, Project/ЦФО, reset, export, and balance notice UI.
- Work item 2.3 — DOM/query compatibility, empty-catalogue, legacy grouping, accessibility smoke, and frontend build checks.
- Stage 2 internal review is green.
- Stage 2 external review returned `REVIEW_GREEN` after the legacy singular ЦФО round-trip fix.
- Stage 2 report prepared; Draft PR #2348 remains Draft with base `master`.

### Current diff / affected files
- `site/templates/finance/report/preview.html.twig` and `_filter_controls_*.html.twig` — shared controls presentation/behavior.
- `site/templates/report/cashflow.html.twig` — Cashflow controls, query state, reset/export links, and balance notice.
- `site/src/Finance/Controller/ReportCashflowController.php` — tenant-scoped filter catalogues and legacy UI state.
- `site/src/Report/Cashflow/CashflowReportRequestMapper.php` — optional reuse of preloaded catalogues.
- `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php` — DOM/query and legacy round-trip coverage.
- `docs/tasks/ui-cashflow-report-controls/` — plan, checkpoint, and Stage delivery records.

### Checks and baseline
- Baseline targeted unit: 8 tests, 50 assertions — green.
- Baseline functional Cashflow: 7 tests, 53 assertions — green.
- Current full unit: 1,900 tests, 10,866 assertions — green with 5 deprecations.
- Current targeted unit: 18 tests, 125 assertions — green.
- Current functional Cashflow: 9 tests, 76 assertions — green with 2 deprecations.
- Symfony `lint:container --env=test` — green.
- Full `composer cs:check` — pre-existing repository failure in 526/2,317 files; task-owned new lines corrected separately.
- Shared Twig partial lint — green.
- P&L Preview functional regression: 14 tests, 302 assertions — green with 1 deprecation.
- Cashflow functional/UI regression: 11 tests, 145 assertions — green with 2 deprecations after the external-review fix.
- Cashflow + P&L functional regression: 25 tests, 447 assertions — green with 2 deprecations after the external-review fix.
- Vite production build — green (122 modules; pre-existing missing UX Turbo package warning).
- UI Kit global check — pre-existing baseline failure: 8,972 violations in 233 files; Stage 2 adds no class names.

### Review status
- iteration: 2 internal review cycles; 2 completed external review cycles with required max-turn recovery runs.
- unresolved findings: none.
- external result: final `REVIEW_GREEN`; no BLOCKER or IMPORTANT findings.

### Exact next action
- Owner decides whether Draft PR #2348 may be marked Ready for review; merge and production deploy remain separately gated.

### Files to inspect first on resume
- `site/src/Finance/Controller/ReportCashflowController.php`
- `site/src/Report/Cashflow/CashflowReportRequestMapper.php`
- `site/templates/finance/report/preview.html.twig`
- `site/templates/finance/report/_filter_controls_script.html.twig`
- `site/templates/report/cashflow.html.twig`
- `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php`
