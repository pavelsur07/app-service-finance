# Cashflow report controls

## Goal

Replace the `/finance/reports/cashflow` period/filter form with the existing
`/finance/report/preview` controls pattern, offering month, quarter, and year
grouping plus multi-select Project and responsibility-center filters. Preserve
all existing UI/export/public API requests and response shapes.

## Stage 1: Backward-compatible report filters

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: `569035ac30b7315fcc6d4f795246b3ca9ef36388`

Definition of Done:

- Existing `from`, `to`, `group`, and singular `responsibilityCenterId` requests
  keep their behavior across UI, protected JSON, and public JSON/CSV endpoints.
- Optional `projectDirectionIds[]` and `responsibilityCenterIds[]` filters are
  tenant-safe and follow the P&L all/empty/partial semantics.
- Project and responsibility-center restrictions combine with AND; selected
  parent projects include descendants without double counting.
- Category totals and the Project × responsibility-center matrix are filtered,
  while account opening/closing balances stay company-wide.
- Existing JSON top-level keys and CSV columns remain compatible.
- No migration, dependency, queue, external side effect, or production action.

Work items:

- 1.1 — Extend the request DTO/mapper additively and validate plural IDs.
- 1.2 — Apply plural Project/responsibility-center restrictions in the builder.
- 1.3 — Preserve formatter/public API contracts and add regression coverage.
- 1.4 — Update architecture documentation.

Stage checks:

- Targeted mapper, builder, formatter, and controller PHPUnit tests.
- Full unit suite, PHP CS dry run, internal review, external Claude review.

Reviewer focus:

- Financial balance semantics, tenant isolation, legacy/plural precedence,
  explicit empty lists, descendants, and API response compatibility.

## Stage 2: Cashflow controls UI parity

Risk: MEDIUM
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: record after Stage 1

Definition of Done:

- Cashflow uses the P&L controls visuals and interactions for period, grouping,
  Project filters, responsibility-center filters, reset, and JSON export.
- The visible grouping options are Month, Quarter, and Year; legacy day/week
  query requests continue to render without contract changes.
- P&L preview, cashflow tables, calculations, matrix, and empty state do not
  regress.
- No new UI Kit API, React/Vite entry, dependency, or production action.

Work items:

- 2.1 — Reuse the P&L control styles/behavior through focused Twig partials.
- 2.2 — Render real Cashflow period/group/filter state and export/reset URLs.
- 2.3 — Add DOM/query compatibility tests and responsive/accessibility smoke.

Stage checks:

- Twig lint, targeted functional tests, full relevant PHPUnit suites,
  UI Kit check/build, internal review, and external Claude review.

Reviewer focus:

- Visual parity, query-state preservation, accessibility, P&L regression, and
  strict exclusion of report-calculation changes.

## Release and production gates

- One task branch and one Draft PR with explicit `master` base.
- Stage 1 continues automatically into Stage 2 after green checks/reviews.
- Stage 2 completes the Release Gate; Ready/merge requires owner approval.
- Merge-triggered deploy and every production action are outside this plan.
