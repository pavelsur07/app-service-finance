# P&L Preview filter card

## Scope

Refactor only the controls above `/finance/report/preview`: period, grouping,
view mode, project/CFO filters, and the existing `show_meta` switch. Match the
control block in `PnL Report.dc.html`, keep the report table unchanged, and keep
the existing public P&L API contract compatible.

## Stage 1: Preview report filtering contract

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `21451bd83f636aef1d1f83187dd8e7f9eedbf14e`

Definition of Done:
- Preview HTML and Preview JSON accept calendar-quarter grouping.
- Preview HTML and Preview JSON accept validated multi-project and multi-CFO
  filters without cross-company access.
- Selected project hierarchies are deduplicated before facts are summed.
- Project comparison displays only selected project columns and its total is
  calculated from the union of selected facts, without parent/child double count.
- Legacy singular Preview query parameters remain compatible.
- Public `/api/public/reports/pl*.json` inputs and outputs remain unchanged.
- No financial formulas, signs, category mappings, database schema, or
  production data are changed.

Work items:
- 1.1 — Add calendar-quarter period construction, including exact labels for
  partial edge quarters.
- 1.2 — Widen the existing internal facts/calculation filters to validated
  project/CFO lists with explicit `null` (all) and `[]` (none) semantics.
- 1.3 — Normalize plural Preview query parameters, preserve singular fallback,
  and add backward-compatible Preview JSON metadata.
- 1.4 — Scope project comparison columns and calculate its total through one
  deduplicated union filter.
- 1.5 — Add unit, integration, and functional regression coverage.

Stage checks:
- Focused PHPUnit tests for calculator, facts provider, grid/compare builders,
  and Preview controller.
- Relevant PHP syntax and code-style checks.
- Internal independent review of the Stage diff.
- Read-only Claude Code CLI review to `REVIEW_GREEN`.

Reviewer focus:
- Company isolation for every accepted project/CFO identifier.
- Exact empty/all filter semantics and singular-query compatibility.
- No duplicate facts for selected parent/child projects.
- No change to the public P&L API contract.

## Stage 2: Preview filter card UI

Risk: MEDIUM
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: recorded immediately before Stage 2 implementation

Definition of Done:
- The old date/filter offcanvas is replaced by a separate card matching the
  period/grouping/filter block in `PnL Report.dc.html` within the legacy layout.
- The card offers month-based ranges and the approved presets: year to current
  month, last three calendar months, and current month.
- Grouping offers Month and Quarter; mode offers Periods and Projects.
- Projects and CFOs are true multiselect controls; `show_meta` is the third
  filter chip; controls apply immediately.
- Reset clears only projects, CFOs, and `show_meta`, preserving period,
  grouping, and mode.
- Existing arbitrary partial-date and day/week URLs still render and preserve
  their exact values until the user changes the corresponding control.
- JSON download and recalculation preserve the complete selected filter state.
- The report table markup, table styling, report formulas, and public APIs are
  unchanged.
- Responsive, keyboard, empty, and selected states are covered by focused tests.

Work items:
- 2.1 — Replace the old control form/offcanvas with the scoped filter card.
- 2.2 — Add minimal inline behavior for presets, month bounds, immediate submit,
  multiselect dropdown interaction, reset, and mode/grouping state.
- 2.3 — Preserve plural filter state in JSON and recalculation actions.
- 2.4 — Add functional/Twig checks and verify the table is byte-for-byte outside
  the intended control boundary.

Stage checks:
- Focused functional PHPUnit tests for rendered controls and query behavior.
- Twig lint and project code-style checks.
- `make site-test` and `make site-cs-check` at the final Release Gate.
- Internal independent review of the complete task diff.
- Read-only Claude Code CLI review to `REVIEW_GREEN`.

Reviewer focus:
- Visual fidelity to the supplied HTML block without introducing global styles.
- Progressive behavior in the existing Bootstrap/Tabler legacy layout.
- Exact preservation of dates and filters across GET, JSON, and recalc flows.
- No table markup/style changes and no public API regression.

## Release and Production gates

- Final Release Gate: after Stage 2 checks, reviews, commits, non-force push,
  and Draft PR update, request the owner's decision about Ready for review.
- Production Gate: Ready, merge to `master`, automatic production deployment,
  and branch deletion are excluded and require separate explicit owner approval.

## Baseline

- `make site-test-prepare`: initially stopped because local `site-postgres` was
  not running; after starting only that local service, completed successfully.
- Focused PHPUnit baseline (calculator, facts provider, Preview controller):
  `OK (7 tests, 119 assertions)`.
- No pre-existing focused test failures.

## Expected change areas

- `site/src/Finance/Facts/`
- `site/src/Finance/Report/`
- `site/src/Finance/Controller/PlReportPreviewController.php`
- `site/templates/finance/report/preview.html.twig`
- focused tests under `site/tests/`
- task delivery records under `docs/tasks/ui-pnl/`

## Explicit exclusions

- Report table markup and table-specific CSS.
- Financial formulas, signs, periods' accounting meaning, and category mappings.
- Database schema and migrations.
- Public P&L API request/response contract.
- UI Kit global components, Vite/React entrypoints, dependencies, and deployment.
