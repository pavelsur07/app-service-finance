## Current checkpoint

**Phase:** Stage 2
**Status:** done — Release Gate delivery pending
**Stage base commit:** `353ed3b1256bf34752f0ef1a6ca2c495d5c1fd01`
**Current Work item:** none — Stage 2 reviewed green
**Owner gate:** yes

### Completed

- Phase 0: scope, risk, acceptance and exclusions recorded in `plan.md`.
- 1.1: KPI provider returns exact current/previous windows and balance comparison date.
- 1.2: authenticated-only opt-in request mapping; public calls retain default behavior.
- 1.3: report transaction rows use the dashboard currency/activity/exclusion scope and expose an exact bcmath summary from the existing KPI aggregator.
- 1.4: integration and functional coverage for transfers, technical/unallocated/deleted rows, splits, currency, company scope, authorized export and public ignore.
- Internal Stage review completed green before external review.
- External review iteration 1 hit the recoverable 40-turn limit; iteration 2 found one IMPORTANT missing row-total assertion and safe MINOR improvements.
- Confirmed IMPORTANT and safe MINOR fixes implemented: row-derived totals, default-export negative assertions, orphan-safe filtered tree, architecture contract note, resumable checkpoint format.
- Stage 1 committed as `353ed3b1` and pushed to `origin/task/finance-dashboard-cashflow-reconciliation`.
- Draft PR creation attempted twice and blocked before GitHub by local execution policy (`approval required`, approval mode `never`); implementation continues per repository rules.
- 2.1 implemented: one controller formatter supplies exact current/previous/balance labels to both Twig modes, including cross-year years.
- 2.1 targeted checks green: dashboard functional test (1 test, 518 assertions) and Twig lint (2 files).
- 2.2 implemented: one controller-owned reconciliation query is reused by the three turnover cards in both UI modes; the balance card has no link.
- 2.2 targeted checks green: dashboard functional test (1 test, 582 assertions) and Twig lint (2 files).
- 2.3 implemented: opt-in ДДС presentation preserves reconciliation scope, hides incompatible filters, exposes exact summary and explains balance semantics with an exit to normal ДДС.
- 2.3 targeted checks green: cashflow functional test (13 tests, 185 assertions; 2 pre-existing deprecations) and report Twig lint.
- 2.4 integrated suite green: 39 tests, 963 assertions; full changed Twig/PHP syntax lint and PHP CS Fixer dry run green.
- `check:ui-kit` remains globally red with 9,085 legacy violations across 234 files; the Stage adds no CSS class to the app UI and changes no UI Kit source.
- Independent internal Stage 2 review green; safe MINOR fixes removed unused Twig context and completed the summary ARIA group.
- External Claude Code review (2.1.238) completed `REVIEW_GREEN`; no BLOCKER/IMPORTANT and no must-fix MINOR.
- Final relevant suite green after review: 39 tests, 963 assertions, 2 pre-existing deprecations; Twig/PHP syntax, changed-file PHP CS and `git diff --check` green.

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

- Stage 1 iteration: 4; final external review `REVIEW_GREEN`.
- Stage 2 iteration: 1; final external review `REVIEW_GREEN`.
- unresolved findings: no BLOCKER/IMPORTANT.
- rejected MINOR: enum/value-object extraction is unnecessary for an allowlisted internal string and would add speculative abstraction; mapper validation remains the HTTP invariant.
- rejected MINOR: archived accounts remain included in company-wide report balances by approved report semantics; Stage 2 will state that balances are not scoped like movement rows.
- accepted advisory MINOR without code change: keep Project × ЦФО matrix visible in reconciliation mode because it is a useful breakdown of the already-scoped rows; only incompatible filters are hidden by the approved plan.
- FOLLOW-UP: deterministic cross-year formatter coverage, explicit `all` presentation coverage, and shared activity-label vocabulary if another consumer appears.
- FOLLOW-UP: shared dashboard activity mapping may be consolidated in a separate task if further consumers appear.

### Exact next action

- Commit and push the reviewed Stage 2 diff, retry Draft PR creation with base `master`, verify delivery facts, then stop at the declared Release Gate.

### Files to inspect first on resume

- `site/src/Finance/Controller/HomeController.php`
- `site/templates/home/index.html.twig`
- `site/templates/app/home/index.html.twig`
- `site/templates/report/cashflow.html.twig`
- `docs/tasks/finance-dashboard-cashflow-reconciliation/plan.md`
