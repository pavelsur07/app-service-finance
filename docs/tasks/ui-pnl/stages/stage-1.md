# Stage 1 Report — Preview report filtering contract

## Stage metadata

- Risk: HIGH-LOCAL
- owner_gate: no
- release_candidate: no
- independently_deployable: no
- stage_base_commit: `21451bd83f636aef1d1f83187dd8e7f9eedbf14e`

## Result

Preview HTML and Preview JSON now support calendar-quarter grouping and
active-company-validated plural project/CFO filters. Project subtrees are
deduplicated before facts are queried. Preview project comparison can render
only selected columns and computes its opt-in total from one union calculation,
preventing parent/child double count.

Legacy singular Preview inputs remain compatible. The public P&L controllers,
their `day|week|month` contract, and the Twig report table have zero diff.

## Work items

- 1.1 — Calendar-quarter periods implemented with full labels (`I кв. 2026`)
  and exact partial-edge labels.
- 1.2 — Internal facts/calculation filters widened to project/CFO lists with
  `null` (unfiltered), `[]` (no facts), active-company validation, CFO `IN`, and
  deduplicated project descendants.
- 1.3 — Preview query normalization and additive JSON metadata implemented;
  plural parameters win per dimension while a singular value remains compatible
  for a dimension whose plural input is absent.
- 1.4 — Preview project columns respect selected projects and the opt-in total is
  a union calculation; the legacy/public default remains a sum of columns.
- 1.5 — Unit, integration, functional, IDOR, compatibility, explicit-empty,
  all-selected, mixed-request, recalc, and legacy-total regressions added.

## Definition of Done

- Observable behavior: met.
- Required tests: met.
- Documentation: `ARCHITECTURE.md`, plan, checkpoint, and this report updated.
- Operational/observability changes: none required; no new logs, queues, jobs,
  migrations, dependencies, or external calls.
- Compatibility: public P&L API and legacy Preview contract preserved.
- Explicit exclusions: financial formulas, signs, mappings, database schema,
  Twig table, UI Kit, deployment, and production data untouched.

## Checks

- Baseline focused: 7 tests, 119 assertions — green.
- Final focused: 13 tests, 178 assertions — green.
- Full unit suite: 1,890 tests, 10,790 assertions — green; 5 existing
  deprecations.
- Full clean integration suite: 974 tests, 4,551 assertions — green; 1 existing
  deprecation.
- Targeted PHP CS Fixer on changed PHP files — green.
- Repository-wide `make site-cs-check` — pre-existing unrelated baseline:
  528/2,316 files require formatting. All task files pass the same configuration.
- Public controller diff — empty.
- Twig template/table diff — empty.
- No migration/schema checks required.

An initial integration attempt lacked local Redis. A later attempt overlapped
with the interrupted container and produced deadlocks/duplicate IDs; those
results were discarded. After starting only local Redis and ensuring one test
container, the complete integration suite passed cleanly.

## Internal review

Four review/fix iterations completed. Final result: no BLOCKER, IMPORTANT, or
confirmed MINOR findings. Reviewer focus covered scope, IDOR/company isolation,
empty/all semantics, hierarchy double count, public compatibility, query count,
tests, secrets, and unrelated files.

## External Claude Code review

- Iteration 1: `REVIEW_GREEN`; four safe MINOR findings accepted and fixed.
- Iteration 2: `REVIEW_GREEN`; five safe MINOR findings accepted and fixed.
- Iteration 3: `REVIEW_GREEN`; four safe MINOR findings accepted and fixed.
- Concluding 40-turn call: configuration failure (`Reached max turns`), not a
  review result.
- Required narrowed retry at 80 turns: `REVIEW_GREEN`; no BLOCKER/IMPORTANT.

Final advisory MINOR items rejected with technical reasons:

- Marker value `0` is intentionally false on both POST and GET; marker-less list
  keys are preserved independently and still activate plural parsing.
- Per-project and union calculations necessarily emit the same row set because
  both read the same company category repository in the same request.
- Project list elements follow the documented internal
  `list<ProjectDirection>` contract and are created only from the validated
  active-company map; no raw request element reaches the facts provider.

Final external result: `REVIEW_GREEN`.

## Risks and follow-ups

- Stage 2 must not submit the global marker unconditionally for an empty CFO
  catalogue; the visible all/default state must remain unfiltered.
- Stage 2 must preserve plural lists in JSON and recalculation controls and add
  quarter labels/width in the new card.
- In projects layout, any plural request deliberately changes `_total` from the
  legacy sum of columns to the deduplicated union; the public endpoint remains
  on the legacy behavior.
- Existing per-category facts queries were not refactored; batching is outside
  this UI task.

## Delivery state

- Stage is review-green and ready for focused commit/push/Draft PR update.
- `owner_gate: no`: continue automatically to Stage 2 after delivery.
- No Release Gate or Production Gate action is authorized by this Stage.
