### Stage 1: Correct gross turnover KPIs on the Finance dashboard — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required after Draft PR delivery

#### Stage scope
- Stage base commit: `f6ba0e628407e30cdeda33357312af4ebcf2d5a5`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`

#### What was done
- Replaced reconstruction of gross turnover from the netted cashflow tree with direct decimal aggregation of split amounts by transaction direction.
- Preserved rolling periods, balances, comparison states, output shape, controller payload, and both Twig UIs.
- Excluded transfers and technical categories; kept unallocated splits only in the `all` activity.
- Added regression, filtering, multi-split, tenant, currency, soft-delete, boundary, and cashflow reconciliation coverage.

#### Files changed
- `site/src/Cash/Repository/Transaction/CashTransactionRepository.php` — direct gross turnover aggregate.
- `site/src/Finance/Application/Service/FinanceDashboardKpiProvider.php` — uses the aggregate and removes invalid tree decomposition.
- `site/tests/Integration/Finance/Application/Service/FinanceDashboardKpiProviderTest.php` — regression and scope coverage.
- `docs/tasks/finance-dashboard-kpi-turnover-fix/` — plan, checkpoint, and Stage Report.

#### Definition of Done
- [x] Gross inflow/outflow are calculated before cashflow netting.
- [x] Net flow reconciles with the signed cashflow total under matching filters.
- [x] Transfers, technical, unallocated, tenant, currency, date, and soft-delete rules are covered.
- [x] Current/previous periods, balance, comparisons, and UI contracts remain compatible.
- [x] No migration, dependency, production access, or report UI change was introduced.

#### Baseline
- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/Application/Service/FinanceDashboardKpiProviderTest.php tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php` — OK, 7 tests, 85 assertions.
- Regression proof on old code — expected failure: gross inflow expected `100.00`, old netted result `20.00`.

#### Checks
- targeted: provider integration test — OK, 3 tests, 26 assertions.
- full relevant Stage: provider, report builder, and Analytics tests — OK, 25 tests, 198 assertions.
- container: `php bin/console lint:container` — OK.
- syntax: changed PHP files — OK in the PHP CLI container; host PHP is unavailable.
- task-scoped CS Fixer dry-run — OK, 0 of 3 files need fixes.
- repository-wide `make site-cs-check` — pre-existing failure: 522 of 2323 unrelated files need formatting; task-owned files are clean and no files were rewritten.
- pre-existing warning: PHP 8.4 deprecation in unchanged `App\Shared\Service\AppLogger::error()`.

#### Internal automatic review
- Iterations: 3
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: method naming/docblock, blank line, repository money normalization, wording.
- FOLLOW-UP: direct cross-module repository use is pre-existing module-boundary debt; activity filtering relies on the existing persisted-flow-kind invariant; legacy children under unallocated are unreachable through current write paths but would require separate data analysis.

#### External Claude Code review
- Attempts: first broad review hit the configured 40-turn limit; it was not treated as green and was retried with the prescribed 80-turn focused scope.
- Completed iterations: 3
- Result: REVIEW_GREEN
- Confirmed findings fixed: method naming/documentation, provider CS blank line, repository decimal normalization, docblock wording.
- Rejected findings with reason: none.

#### Review fixes applied
- Renamed the repository method to explicitly state gross turnover and transfer exclusion.
- Documented direction, technical, transfer, and unallocated semantics.
- Normalized repository decimal strings at scale 2 and removed the extra blank line.

#### Risks / reviewer focus
- Excluding `isTransfer=true` with a non-technical category is an intended behavior change in addition to the netting fix; production volume was not queried because no production-check request was given.
- Activity filtering uses stored `CashflowCategory.flowKind`; current save and structure-migration paths synchronize it with the root effective kind.
- The report UI still includes technical movements and remains unchanged, so reconciliation requires identical filters.

#### Checkpoint
- `docs/tasks/finance-dashboard-kpi-turnover-fix/checkpoint.md` updated.
- exact next action: commit/push the task branch, create the Draft PR, then wait at the Release Gate.

#### Open questions
- none

#### Expected owner response
Recommended response after the Draft PR is delivered:
`Одобряю merge PR #<number> в master и автоматический production deploy.`
