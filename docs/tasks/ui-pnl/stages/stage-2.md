# Stage 2 Report — Preview filter card UI

## Stage metadata

- Risk: MEDIUM
- owner_gate: yes
- release_candidate: yes
- independently_deployable: yes
- Stage base commit: `ae1c8855f51344235113c252780d21d2f8688b11`
- Result: `REVIEW_GREEN`

## Delivered behavior

- Replaced the legacy date form/filter offcanvas with a separate scoped card
  matching `PnL Report.dc.html` for period, grouping, mode, and filters.
- Added month range inputs and the approved presets: year to current month,
  last three calendar months, and current month.
- Added Month/Quarter and Periods/Projects segmented controls with immediate
  submission; grouping is visibly disabled in the Projects layout.
- Added true project/CFO multiselect dropdowns and the `Код и тип` filter chip.
- Reset clears project/CFO selections and `show_meta`, preserves the exact
  period/grouping/layout, and normalizes both dimensions to plural all-selected
  so the deduplicated project total does not change silently.
- JSON and recalculation actions preserve the current plural or legacy singular
  contract. Existing partial-date and day/week URLs keep their exact values.
- Added independent project/CFO presence markers so an empty selection in one
  dimension does not blank or broaden the other dimension.
- Kept the report table, formulas, public P&L endpoints, schema, dependencies,
  and production configuration unchanged.

## Verification

- Focused Stage suite: 15 tests, 261 assertions, green.
- Final `make site-test`: 3,379 tests, 18,846 assertions, green; 5 existing
  deprecations.
- Twig lint: green.
- Extracted inline JavaScript `node --check`: green.
- Targeted PHP CS Fixer for both changed PHP files: green.
- Repository-wide `make site-cs-check`: known baseline failure, 525 of 2,316
  pre-existing files require formatting; neither changed PHP file is listed.
- `git diff --check`: green.
- Table section SHA-256 at Stage base and final working tree is identical:
  `13f624f112a3e75c5a77d6214ca7aee9cf8fa3220ded6f843ed0aa9ac06fcf52`.
- Responsive behavior is implemented through wrapping rows, bounded dropdown
  widths/heights, and end alignment for the CFO menu. Keyboard behavior uses
  native `details`/`summary`, labeled checkboxes, button controls, and ARIA
  groups; these states received static review and Twig functional coverage.

## Review history

### Internal iteration 1

- Found and fixed legacy JSON/recalculation state drift: those actions now keep
  legacy singular semantics until the new multiselect form is used.
- No remaining BLOCKER or IMPORTANT findings.

### External iteration 1

- One IMPORTANT finding confirmed: reset dropped plural mode, which could switch
  project-layout `Итого` from the deduplicated union to the legacy column sum.
- Fixed reset to submit all active IDs with independent markers and added a
  functional round-trip assertion.
- Safe MINOR fixes accepted: strict month validation, exact reference chip copy,
  removal of bare dropdown counts, legacy project-layout chip consistency,
  ARIA groups, sibling/outside dropdown closing, bounded dropdown positioning,
  and none-selected UI coverage.

### Internal iteration 2

- Rechecked reset all/none semantics, legacy/plural actions, active-company ID
  validation, exact dates, table boundary, and public API scope.
- No BLOCKER or IMPORTANT findings.

### External iteration 2

- The 40-turn call reached the recoverable local limit without a verdict.
- The required narrowed 80-turn retry confirmed the reset fix and returned the
  exact standalone `REVIEW_GREEN` marker with no BLOCKER or IMPORTANT findings.

## Advisory findings and follow-ups

- No-JS submission was not added: a partial submit button would not correctly
  apply the month and segmented controls and would misrepresent the immediate-
  apply interaction contract. Full progressive enhancement is a separate scope.
- Reset/all-selected GET URLs enumerate active IDs. This is correct for current
  catalogues; if catalogues grow to hundreds of choices, an explicit all-sentinel
  can replace enumeration in a follow-up.
- The card keeps the supplied reference colors for exact visual fidelity. A
  future dark-theme/token migration should map them to global Tabler tokens.
- Quarter data-column width remains 160px because table CSS is explicitly
  excluded from this task; partial-quarter label width is a separate follow-up.
- Legacy day/week URLs remain compatible, but the redesigned grouping selector
  intentionally exposes only Month and Quarter.

## Release Gate

The Stage is checked, internally reviewed, externally `REVIEW_GREEN`, and ready
for the Draft PR update. The owner decision requested after delivery is whether
to move Draft PR #2340 to Ready for review. Ready, merge, automatic production
deployment, and branch deletion are not authorized by this Stage.
