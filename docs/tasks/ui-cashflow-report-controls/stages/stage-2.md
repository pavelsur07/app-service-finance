### Stage 2: Cashflow controls UI parity — DONE

**Risk:** MEDIUM
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** completed Release Gate; owner decides whether Draft PR #2348 may be marked Ready

#### Stage scope
- Stage base commit: `c8f01a6e5ad4174505ad308850964d5117eea498`
- Work items completed: `2.1`, `2.2`, `2.3`

#### What was done
- Replaced the Cashflow date/group/CFO form with the P&L preview controls pattern.
- Added visible Month, Quarter, and Year grouping plus Project and ЦФО checkbox filters.
- Reused the P&L styles and interactions through two focused Twig partials without adding a dependency or frontend entry point.
- Preserved legacy `day`, `week`, and singular `responsibilityCenterId` requests, including JSON-link and form round trips.
- Kept Project/ЦФО filter state in JSON and reset URLs, including explicit-empty and empty-catalogue cases.
- Kept Cashflow calculations, tables, matrix, protected/public response shapes, and company-wide balance semantics unchanged.

#### Files changed
- `site/templates/finance/report/preview.html.twig` — consumes the shared controls partials with unchanged P&L behavior.
- `site/templates/finance/report/_filter_controls_styles.html.twig` — mechanically extracted controls CSS.
- `site/templates/finance/report/_filter_controls_script.html.twig` — shared interactions and legacy-to-plural ЦФО transition.
- `site/templates/report/cashflow.html.twig` — new controls, filter state, reset/export links, and balance notice.
- `site/src/Finance/Controller/ReportCashflowController.php` — tenant-scoped catalogues and legacy UI state.
- `site/src/Report/Cashflow/CashflowReportRequestMapper.php` — optional reuse of the controller's preloaded catalogues.
- `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php` — DOM, query-state, empty-state, and legacy round-trip coverage.
- `docs/tasks/ui-cashflow-report-controls/` — plan, checkpoint, and Stage Report.

#### Definition of Done
- [x] Cashflow uses the P&L controls visuals and interactions.
- [x] Month, Quarter, and Year are the only visible grouping choices.
- [x] Both Project and ЦФО filters support all, partial, and explicit-empty states.
- [x] Legacy day/week and singular ЦФО requests remain compatible.
- [x] P&L preview and Cashflow output have regression coverage.
- [x] No report formula, formatter schema, dependency, migration, queue, or production action changed.

#### Baseline
- Cashflow functional suite at the Stage base — 9 tests, 76 assertions, green with 2 deprecations.
- P&L preview functional suite — 14 tests, 302 assertions, green with 1 deprecation.

#### Checks
- Cashflow functional/UI — 11 tests, 145 assertions, green with 2 deprecations after the review fix.
- Cashflow + P&L functional regression — 25 tests, 447 assertions, green with 2 deprecations.
- Full unit suite — 1,900 tests, 10,866 assertions, green with 5 deprecations; run before the template/controller-only review fix.
- Twig syntax lint for all four affected templates/partials — green.
- Targeted PHP CS Fixer dry runs for controller, mapper, and functional test — green.
- Symfony test-container lint — green.
- Vite production build — green, 122 modules; pre-existing missing UX Turbo package warning remains non-fatal.
- UI Kit global check — pre-existing baseline failure: 8,972 violations in 233 files; Stage 2 introduces no new class names.
- Global Twig CS — pre-existing repository baseline failure; new Cashflow local variables were aligned with the configured naming style.
- `git diff --check` — green.

#### Internal automatic review
- Iterations: 2
- BLOCKER: none
- IMPORTANT: none after the legacy singular ЦФО round-trip fix.
- Verified: P&L extraction equivalence, tenant scoping, empty/explicit-empty states, legacy grouping, export/reset state, accessibility smoke, and unchanged API/financial output.

#### External Claude Code review
- First 40-turn run reached its limit; the required narrowed 80-turn run found one IMPORTANT issue.
- Confirmed IMPORTANT fixed: legacy singular ЦФО could become plural all-selected for a one-ЦФО company, changing JSON/form results.
- Post-fix 40-turn run reached its limit; the required narrowed 80-turn re-review returned `REVIEW_GREEN`.
- Final BLOCKER: none.
- Final IMPORTANT: none.
- Rejected MINOR: keep `Режим отображения` because it is explicit owner wording; a lone hidden submit would not provide a functional no-JS version of the JS-driven controls; `ЦФО: 1` correctly signals an active legacy singular restriction rather than plural “Все”.

#### Review fixes applied
- Preserved singular `responsibilityCenterId` in the JSON link and unrelated control submissions.
- Transitioned to plural ЦФО parameters only when the user changes that filter or selects all.
- Added single-ЦФО legacy round-trip regression assertions.
- Removed a test assertion coupled to Twig's internal JavaScript escaping.
- Refreshed the resumable checkpoint with the actual Stage 2 files and results.

#### Follow-ups outside scope
- Consider a complete no-JS controls design rather than a partial hidden-submit fallback.
- Consider moving the shared controls partials to a neutral report directory if a third report adopts them.
- Reconcile the documented cross-module Facade rule with established direct Project repository usage in a dedicated architecture task.

#### Release Gate
- Draft PR: `#2348`, base `master`, head `codex/ui-cashflow-report-controls`.
- Marking Ready, merge, automatic production deploy, and all Production Gate actions were not performed.
- Exact owner decision: whether Draft PR #2348 may be marked Ready for review.

#### Expected owner response
- `Одобряю перевести Draft PR #2348 в Ready for review. Merge и production deploy не выполнять.`
