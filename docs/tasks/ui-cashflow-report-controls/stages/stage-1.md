### Stage 1: Backward-compatible report filters — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** yes
**Next action:** continue autonomously

#### Stage scope
- Stage base commit: `569035ac30b7315fcc6d4f795246b3ca9ef36388`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`

#### What was done
- Added additive Project and responsibility-center plural query parameters with independent explicit-empty markers.
- Kept legacy `from`, `to`, `group`, and singular `responsibilityCenterId` behavior.
- Applied tenant-safe Project subtree and responsibility-center filters with AND semantics.
- Kept opening/closing balances company-wide while filtering movements, categories, and the Project × ЦФО matrix.
- Kept public JSON top-level and CSV schemas unchanged; protected JSON exposes plural filter metadata only for plural requests.
- Documented all/empty/partial, precedence, `NULL`-movement, and Stage 2 empty-catalogue semantics.

#### Files changed
- `site/src/Report/Cashflow/CashflowReportParams.php` — additive request state.
- `site/src/Report/Cashflow/CashflowReportRequestMapper.php` — validated plural filters and deterministic catalogue order.
- `site/src/Report/Cashflow/CashflowReportBuilder.php` — subtree/AND filtering and company-wide balance source.
- `site/src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php` — conditional protected-export metadata.
- `site/tests/Unit/Report/Cashflow/CashflowReportRequestMapperTest.php` — mapping, precedence, ordering, and empty-catalogue coverage.
- `site/tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php` — query, mixed-filter, empty, subtree, and foreign-catalogue coverage.
- `site/tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php` — additive response metadata coverage.
- `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php` — endpoint, company-scope, balances, `NULL` rows, JSON, and CSV compatibility.
- `ARCHITECTURE.md` — cashflow contract version 1.80.
- `docs/tasks/ui-cashflow-report-controls/` — plan, checkpoint, and Stage Report.

#### Definition of Done
- [x] Legacy request behavior is preserved for UI/protected/public endpoints.
- [x] Plural Project and ЦФО filters are tenant-safe and use P&L all/empty/partial semantics.
- [x] Project subtrees and Project + ЦФО AND behavior are covered.
- [x] Filtered movements and company-wide balances are covered against the real database.
- [x] Public JSON top-level keys and CSV columns remain compatible.
- [x] No migration, dependency, queue, external side effect, or production action.

#### Baseline
- Targeted unit — 8 tests, 50 assertions, green.
- Functional Cashflow export — 7 tests, 53 assertions, green.

#### Checks
- targeted: Cashflow mapper/builder/formatter — 18 tests, 125 assertions, green.
- module: Cashflow JSON/export endpoints — 9 tests, 76 assertions, green with 2 deprecations.
- full relevant stage: unit suite — 1,900 tests, 10,866 assertions, green with 5 deprecations.
- Symfony container lint — green.
- `git diff --check` — green.
- full PHP CS dry run — pre-existing repository failure in 526/2,317 files; no new formatter finding remains in task-owned lines.

#### Internal automatic review
- Iterations: 4
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: CSV stream assertion, mixed legacy/plural path, catalogue reuse, deterministic ordering, empty catalogue, foreign catalogue, unassigned movements, documentation/checkpoint accuracy.
- FOLLOW-UP: consider a shared P&L/Cashflow selection resolver and scalar-only internal params in a separate cross-module refactor.

#### External Claude Code review
- Iterations: 4 completed review cycles; recovery reruns were required when the 40-turn limit was reached.
- Result: `REVIEW_GREEN`
- Confirmed findings fixed: documentation precision, duplicate catalogue query, mixed-path coverage, checkpoint format, empty/foreign catalogue tests, deterministic ordering, `NULL`-movement functional coverage.
- Rejected findings with reason: docblock alignment suggestion contradicted the configured PHP CS Fixer output and the affected baseline style predates this task.

#### Review fixes applied
- Reused the mapper-loaded Project catalogue without weakening the builder company guard.
- Added fail-closed tests for empty catalogues and foreign supplied entities.
- Normalized both plural lists to catalogue order.
- Pinned unassigned movement exclusion and company-wide closing-balance inclusion in the functional test.

#### Risks / reviewer focus
- Stage 2 must emit only independent markers and omit markers for empty default catalogues.
- Stage 2 must explain company-wide balances for Project-only and multi-ЦФО filters.
- Public response shapes remain intentionally unchanged.

#### Checkpoint
- `docs/tasks/ui-cashflow-report-controls/checkpoint.md` updated.
- exact next action: commit/push Stage 1, update the Draft PR, then start Stage 2.

#### Open questions
- none

#### Expected owner response
- not required; continuing autonomously
