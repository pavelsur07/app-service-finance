# Cash auto rules — Stage 7.11: consolidated follow-up cleanup plan

## Goal

Close the real unresolved Stage 7 remarks in one focused cleanup stage and stop using stale stage reports as the source of "next action" loops.

This stage is not a new ЦФО feature rollout. It is a cleanup/hardening unit for already merged Stage 7 work.

## Accepted unresolved remarks

### 1. Stale Stage 7 status / next-action documentation

Source:

- `docs/reviews/cash-auto-rules-stage-7-plan.md`
- `docs/reviews/cash-auto-rules-stage-7-6-plan.md`
- `docs/reviews/cash-auto-rules-stage-7-6-4-report.md`
- `docs/reviews/cash-auto-rules-stage-7-9-4-transition.md`
- `docs/reviews/cash-auto-rules-stage-7-10-report.md`
- `docs/reviews/cash-auto-rules-stage-7-8-1-report.md`

Issue:

- Several documents still say `STOP`, `Draft PR`, or "next action" for stages that were already merged, accepted, or superseded.
- This is the direct cause of repeated "what next" loops.

Fix:

- Add one current Stage 7 status section.
- Mark completed stages as done with PR/merge/acceptance references where known.
- Replace stale `Next action` lines with current factual status.
- Leave historical details intact; do not rewrite old evidence.

Acceptance:

- A single `rg` over Stage 7 docs no longer points to stale active next actions for closed work.
- The only remaining "next action" lines are either current, explicitly historical, or intentionally out of scope.

### 2. Remove temporary P&L daily-total runtime schema detection

Source:

- `docs/reviews/cash-auto-rules-stage-7-7-3-report.md`

Remark:

- `FOLLOW-UP: remove repository runtime schema detection after production migration is confirmed everywhere.`

Current status:

- Stage 7.7.3 migration and production acceptance are complete.
- Stage 7.10 production checks confirmed the Project×ЦФО invariant state is healthy.

Fix:

- Inspect the P&L daily-total repository/writer code.
- Remove the temporary runtime index/schema-detection branch if it only exists for the old-schema compatibility window.
- Keep the current Project×ЦФО aggregation key behavior unchanged.
- Add or update focused tests if the removed branch had behavioral coverage.

Acceptance:

- No old-schema fallback remains in runtime P&L writer code.
- New P&L daily totals still aggregate by Project × ЦФО.
- Existing tests for P&L register/update/report still pass.

### 3. Optional raw P&L debug ЦФО filter

Source:

- `docs/reviews/cash-auto-rules-stage-7-7-4-report.md`

Remark:

- `FOLLOW-UP: optional raw P&L debug filtering by ЦФО, intentionally outside this stage.`

Fix:

- Inspect the raw P&L debug route/controller/template.
- Add the same additive, read-only `responsibilityCenterId` filter only if the raw debug page already has a filter surface matching the P&L preview pattern.
- Reuse existing active-company ЦФО resolution.
- Ignore malformed/invalid/foreign/archived ids.
- Do not change formulas, period semantics, daily totals, rebuilds, or public contracts.

Acceptance:

- Raw P&L debug output can be narrowed by active company-owned ЦФО.
- Missing or invalid filter preserves current behavior.
- Targeted controller/query/template tests or the strongest available bounded verification pass.

### 4. Read-only diagnostic for existing failed Messenger messages

Source:

- `docs/reviews/cash-auto-rules-stage-7-10-report.md`

Remark:

- `FOLLOW-UP: inspect the three existing failed Messenger messages through a separately approved read-only diagnostic path if operationally required.`

Fix:

- Prepare read-only SQL/console diagnostics for failed message count, class names, timestamps, and safe high-level error metadata.
- Do not consume, retry, delete, replay, or mutate any queue/message state in this stage.
- Do not print sensitive payloads into repo docs or chat.

Acceptance:

- A short diagnostic note explains whether the failed messages are related to Stage 7 or unrelated legacy operations.
- No production state is changed.

STOP:

- Running this production read-only diagnostic still requires immediate owner approval at execution time.

## Explicitly excluded from this one cleanup stage

### Cashflow Project × ЦФО matrix

Source:

- `docs/reviews/cash-auto-rules-stage-7-8-1-report.md`
- original Stage 7.8 goal

Reason excluded:

- This is a product feature, not a cleanup remark.
- It needs its own Phase 0 contract: route/UI/API shape, matrix dimensions, exports, empty states, and performance bounds.

Recommended separate stage:

- Stage 7.8.2 — Cashflow Project × ЦФО matrix.

### Production import smoke

Source:

- `docs/reviews/cash-auto-rules-stage-7-10-report.md`

Reason excluded:

- It mutates production by running an import path.
- It requires immediate explicit owner approval with source/company/date bounds.

### Cashflow selected-ЦФО second DB query optimization

Source:

- `docs/reviews/cash-auto-rules-stage-7-8-1-report.md`

Reason excluded:

- External review classified it as FOLLOW-UP only.
- The second query is deliberate and keeps filtered category rows independent from company-wide balance rollup.
- Optimize only after measured report performance pressure.

### Composite database guard for pair-removal race

Source:

- `docs/reviews/cash-auto-rules-stage-7-9-plan.md`

Reason excluded:

- It is a schema/invariant hardening idea with migration risk.
- Current application code validates active pairs and production aggregate checks show invalid complete pairs = 0.
- If needed, this should be a separate schema-hardening Phase 0.

### Old restricted-wrapper reliability notes

Source:

- Stage 7.5 and Stage 7.6.1 reports.

Reason excluded:

- Stage 7.10 successfully used the read-only production wrappers.
- Treat the older wrapper notes as historical unless the wrapper fails again.

## Stage 7.11 Definition of Done

- [x] Current Stage 7 status docs no longer point to already-completed work as active next action.
- [x] Runtime P&L old-schema detection is removed or explicitly proven already absent.
- [x] Raw P&L debug ЦФО filter is implemented only if it fits the existing debug route pattern; otherwise documented as not applicable with evidence.
- [x] Failed Messenger messages have a read-only diagnostic plan, and actual production inspection is run only after immediate owner approval.
- [x] No production mutation, import run, queue consume/retry, migration, backfill, or recalculation.
- [x] Targeted tests/checks pass.
- [x] Internal review green.
- [x] External read-only Claude review returns `REVIEW_GREEN` for code/doc changes.
- [ ] One PR is created for Stage 7.11.

## Expected checks

- Targeted tests for touched P&L/Cash/Report code.
- `make site-test-unit`.
- Relevant integration tests if P&L writer/runtime fallback is changed.
- Twig lint if templates change.
- Symfony container lint.
- Targeted PHP CS Fixer dry-run.
- `git diff --check`.
- External read-only Claude review.

## Recommended execution order

1. Update Stage 7 status docs to remove circular next actions.
2. Inspect and remove/close P&L daily-total runtime schema fallback.
3. Implement or explicitly close raw P&L debug ЦФО filter.
4. Prepare failed Messenger read-only diagnostic note; STOP before production inspection unless owner gives immediate approval.
5. Run checks and reviews.
6. Commit, push, and create one Draft PR.
