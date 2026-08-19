# Checkpoint

## Current checkpoint

**Phase:** Stage 1 — Preview report filtering contract
**Status:** done
**Stage base commit:** `21451bd83f636aef1d1f83187dd8e7f9eedbf14e`
**Current Work item:** none
**Owner gate:** no

### Completed

- Work item 1.1: calendar quarters with full/partial labels.
- Work item 1.2: internal plural project/CFO filters, active-company validation,
  descendant deduplication, IN filtering, and explicit `null`/`[]` semantics.
- Work item 1.3: Preview HTML/JSON normalization, additive metadata, legacy
  singular compatibility, and plural recalc redirect state.
- Work item 1.4: selected project columns and opt-in union-based `_total`, while
  legacy/public callers keep column-sum behavior.
- Work item 1.5: focused unit/integration/functional regression coverage.
- `ARCHITECTURE.md` updated with the Preview-only contract; public P&L API and
  Twig table have zero diff.

### Current diff / affected files

- `site/src/Finance/Controller/PlReportPreviewController.php`
- `site/src/Finance/Facts/{FactsProviderInterface,NullFactsProvider,PLDailyTotalFactsProvider}.php`
- `site/src/Finance/Report/{PlReportCalculator,PlReportGridBuilder,PlReportProjectsCompareBuilder}.php`
- focused tests under `site/tests/{Unit,Integration,Functional}/Finance/`
- `ARCHITECTURE.md` and `docs/tasks/ui-pnl/`
- Owner-supplied `docs/tasks/ui-pnl/PnL Report.dc.html` is unchanged; unrelated
  `.mimocode/command/`, `docs/integrations/`, and UI audit screenshots are not
  task-owned and remain untouched.

### Checks and baseline

- Instruction hashes at task start:
  - `AGENTS.md`: `f6b2cc5013bae1d87b855f41d5553fcddd6155dbb602da87bc0e7e41ea407456`
  - `CLAUDE.md`: `c40c56df861b0044e6e00e9e786f8666c92e1f26ef3ba353240d3b0f910ccacb`
  - `CLAUDE.frontend.md`: `1d4176e3de4f865f37a185c3596b89bba334723bb26782de5eb31fa229ada22c`
  - `PATTERNS.md`: `aee5498cae3cf96a6922103d931f4b92771171625e8512afa18135f0d52a09f7`
- Baseline: 7 focused tests, 119 assertions, green.
- Latest focused check after review iteration 3 fixes: 13 tests, 178
  assertions, green; targeted PHP CS Fixer green.
- Full unit suite: 1,890 tests, 10,790 assertions, green; 5 deprecations.
- Full clean integration suite: 974 tests, 4,551 assertions, green; 1
  deprecation. An earlier concurrent-container attempt produced infrastructure
  deadlocks; it was discarded and repeated cleanly in one process.
- Repository-wide `make site-cs-check` has a pre-existing baseline failure: 528
  of 2,316 files outside this task require formatting. All changed PHP files pass
  the same fixer configuration when checked directly.

### Review status

- iteration: 1 internal — green, no BLOCKER/IMPORTANT.
- iteration: 1 external — `REVIEW_GREEN`; four MINOR findings accepted and fixed.
- iteration: 2 internal — green after fixes.
- iteration: 2 external — `REVIEW_GREEN`; five additional safe MINOR findings
  accepted and fixed: projects-layout documentation scope, empty-catalogue
  `[]` semantics, mixed-type CFO guard, legacy `_total` regression coverage,
  and this checkpoint structure.
- iteration: 3 internal — green after the second MINOR fix set.
- iteration: 3 external — `REVIEW_GREEN`; four safe MINOR findings accepted and
  fixed: empty-catalogue documentation, mixed marker-less recalc round-trip,
  non-vacuous all-selected facts coverage, and current checkpoint state.
- iteration: 4 internal — green after the third MINOR fix set.
- iteration: 4 external — first call reached the recoverable 40-turn limit; the
  required narrowed 80-turn retry returned `REVIEW_GREEN` with no
  BLOCKER/IMPORTANT. Three advisory MINOR items were rejected with technical
  reasons recorded in `stages/stage-1.md`.
- unresolved findings: none BLOCKER/IMPORTANT/confirmed MINOR.
- Stage 2 follow-ups: wire marker/all state deliberately, preserve plural JSON
  and recalc inputs in Twig, expose quarter label/width in the new card, and
  document the plural project-total meaning in the Stage report.

### Exact next action

- Commit only Stage 1 task-owned files, push without force, create/update the
  Draft PR with base `master`, then start Stage 2 automatically.

### Files to inspect first on resume

- `docs/tasks/ui-pnl/plan.md`
- `site/src/Finance/Controller/PlReportPreviewController.php`
- `site/src/Finance/Facts/PLDailyTotalFactsProvider.php`
- `site/src/Finance/Report/PlReportProjectsCompareBuilder.php`
- `site/tests/Functional/Finance/PlReportPreviewControllerTest.php`
- `site/tests/Unit/Finance/Report/PlReportProjectsCompareBuilderTest.php`
- `docs/tasks/ui-pnl/stages/stage-1.md`
