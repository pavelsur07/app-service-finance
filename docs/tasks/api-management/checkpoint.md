## Current checkpoint
**Phase:** Stage 2 / Work item 2.1
**Status:** planned
**Stage base commit:** 22399152

### Completed
- Read owner plan and relevant rules; isolated worktree/branch created. Unrelated files stay in original checkout.
- TASK.md and four-Stage plan saved; no application code/database changes yet.
- Reviewed Company/ReportApiKey, ModuleAccess gates, Shared audit and module boundaries.

### Checks and baseline
- docker exec api-management-cli php vendor/bin/phpunit tests/Unit/Company/Security — PASS 29 tests, 119 assertions.
- docker exec api-management-cli php vendor/bin/phpunit tests/Unit/Shared/EventSubscriber/AuditLogSubscriberTest.php — PASS 1 test, 1 assertion.
- docker exec api-management-cli php vendor/bin/phpunit tests/Integration/Company/Security/ModuleWriteGateCoverageTest.php — PASS 1 test, 12 assertions.
- Architecture focused PHPStan running; /tmp/api-management-baseline-stan.log (outside repo).

### Review status
Internal implementation review pending; external 0 rounds (not yet due).

### Exact next action
Finish external HTTP tests and key Actions integration, focused static/style; Stage 1 review and required external review before commit.

### Files to inspect first on resume
TASK.md, plan.md, this checkpoint; git status; Company Entity/Facade, Shared AuditLog, Api readme.

### Work item 1.1 completed
- Generated INTEGER public ID/sequence/backfill+legacy compatibility/Facade owner check implemented. Isolated migration and facade tests: 11 tests / 35 assertions PASS; focused PHPStan/style PASS.
- Architecture baseline PHPStan PASS. Applied only task migrations90000/90100 to local TEST schema; before company_count=0. Isolated migration tests cover populated table/concurrent physical connections/rollback nonreuse.
- 1.2 Api key Entity/Actions/audit implemented by subagent; integration checks ongoing.
- 1.3 principal/stateless firewall/validation/rate-limit/explicit controller policy/Problem Details implemented. Shared AuditContext and Ingestion subscriber regression: 2 failures on old code, 2 tests/5 assertions green on new. Principal/log-redaction unit tests red then green.
- Ruling: identifier example100025 does not designate a specific existing company; numbers are assigned by sequence without business ordering. Schema INTEGER agrees with numeric API and installed DBAL mapping.

### Work items 1.2–1.3
- Key lifecycle/atomic audit tests18/63 PASS. Timestamps now datetimetz_immutable (initial datetime_immutable hydration shifted expiry by3h; functional regression reproduced and fixed). Only task-created local test table converted; source migration updated before release.
- External authentication tests11/333 PASS, then added invalid-company-header quota regression: red61st422, green429 (1 test90 assertions).
- Fresh internal Stage1 review:2 IMPORTANT +1 MINOR found and fixed; scoped re-review open0. Prior implementation verification caught1 BLOCKER UTC expiry and2 IMPORTANT invariants/debug handling. External reviewer round1 running via standard script.
- Module suite first pass379 tests1575 assertions:1 test timezone-string comparison failure; fixed to epoch comparison, module rerun ongoing. No application regression in remaining378 tests. Focused test static typing corrected without baseline changes.
- Stage2 UI baseline `docker exec api-management-node npm run check:ui-kit`: FAIL9107 existing violations,233 files/11406 usages. No UI changes at baseline.
- Exact next action: inspect Stage1 module/static/external results, fix confirmed findings, write Stage1 report, commit/push/create Draft PR, then Stage2 automatically.

- Final Stage1 module set:381 tests/1676 assertions PASS,1 pre-existing deprecation. Focused PHPStan production/tests/migrations PASS. Doctrine mapping --skip-sync PASS, YAML5 files PASS, API types check PASS. External round1 remains running; no Stage close before result.

### Stage1 complete
- External Claude round1:0BLOCKER2IMPORTANT3MINOR, all confirmed/fixed/verified; fixed without re-run. Fresh internal focused review confirms0open.
- Final fix verification22tests457assertions PASS;8exception/facade tests PASS; focusedPHPStan/style PASS. Public-ID accepted DBAL drift documented; version default synchronized; throwable retained for monitoring.
- Stage1 report saved. Exact next action: commit/push Stage1 and create single Draft PR, record its commit as Stage2 base, implement UI2.1/2.2.
