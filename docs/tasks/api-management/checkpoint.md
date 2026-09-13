## Current checkpoint
**Phase:** Stage 3 complete
**Status:** verified; preparing Stage 4
**Stage base commit:** 36b773b3

### Completed
- Phase 0 and Stages 1–2 committed and pushed. Stage 3 implementation/checks/internal review complete; external round 1 pending.
- Draft PR: https://github.com/pavelsur07/app-service-finance/pull/2474 (base master verified).
- Isolated worktree retains all task changes; unrelated original checkout files untouched.

### Current verification
- Stage 3 Api module + route coverage: 338 tests / 1354 assertions PASS.
- Focused Api PHPStan and style PASS. Production route collection excludes test fixtures.
- Full handoff gates reserved for Stage 4 completion.

### Review status
- Stage 1 external: round 1, fixed without re-run; Stage 2 internal clean.
- Stage 3 fresh internal: 0 BLOCKER / 0 IMPORTANT / 0 MINOR; external round 1 running.

### Exact next action
- Finish external review from base 36b773b3, fix/verify findings, commit/push Stage 3 and update Draft PR. Then implement Stage 4 UI automatically.

### Files to inspect first on resume
TASK.md, plan.md, this checkpoint, git status, Stage 3 report, site/var/external-review/36b773b3/review.txt.

### Earlier execution evidence

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

### Stage2 in progress
- Stage1 committed3edbb656 and pushed origin/feat/api-management. Stage2 base3edbb656; UI agent implementing2.1/2.2 after TDD.
- Draft PR creation currently external service issue: gh pr create --base master --head feat/api-management --draft ... returned GitHub GraphQL server error; REST gh api repos/pavelsur07/app-service-finance/pulls --method POST --input /tmp/api-management-pr.json returned unexpected end of JSON input. Both subsequent PR-list checks empty, no duplicate PR. Finish implementation/checks before reporting any remaining blocker; retry only after diagnostic/service change.
- Browser environment ready: api-management-cli test HTTP8000 (internal container network only), api-management-browser existing Playwright1.62.1 image; dependencies borrowed read-only from existing analytics node_modules, no install/new app dependency.
- Exact next action: integrate Stage2 UI, targeted tests and browser smoke, retry Draft PR after availability check.

### Stage2 complete
- Work items2.1/2.2 complete: owner-only settings, Symfony Forms/CSRF, scoped pagination, direct one-time secret, copy/history clearing, pasted shared validation, rename/revoke and safe errors.
- Api module plus write-gate coverage:50tests651assertions PASS. Focused PHPStan0, CS0/11, Twig/YAML PASS, npm lint/build PASS. UI Kit9107 baseline violations unchanged; React mapping47 pre-existing failures unchanged.
- Real Chromium desktop/mobile smoke PASS: login, empty/list, creation201/no-store, clipboard, no local/sessionStorage, navigation-back clears secret, rename, pasted check/no echo, mobile390px no page overflow, revoke. Screenshots inspected. Synthetic fixture and temporary native-session override removed before final tests.
- Fresh independent Stage2 review0BLOCKER/0IMPORTANT/0MINOR. Implementation fixed missing-form POST status and long-name overflow; open0. External review not required for MEDIUM Stage2.
- Exact next action: commit/push Stage2; record Stage3 base; implement backend catalog, prepared/effective policy and audited versioned save. Draft PR service remains unavailable; retry after new branch head is pushed.

- Stage2 committed/pushed36b773b3. Stage3 DoD/risk/work items recorded in plan before code. Selected scopes may be prepared; enabling disconnected resources is rejected so future connection cannot silently activate permissions.

- Draft PR created after Stage2 push: https://github.com/pavelsur07/app-service-finance/pull/2474; verified baseRefName=master,isDraft=true. External service blockage resolved. Stage3 principal/policy regression baseline2 failures observed before implementation.

### Work items3.1–3.3 implemented
- Explicit16scope catalog/presets, disconnected effective policy, JSON selected/enabled migration90200 appliedTEST, owner/version/atomic-audit save implemented. Test-only controllers exercise full16×16 policy matrix and HTTP disconnected/fresh-read behavior.
- Integrated Api module+write gate338tests1354assertions PASS. Focused wholeApi PHPStan0. CS identified importordering in exception subscriber; fixed locally. Own principal regression2red→8tests23assertions green; backendpolicy/save23tests60assertions green.
- Fresh independent Stage3 review running; next required external review after internal green (>500line Stage). PR2474 Draftbasemaster, Stage2 body updated via REST because installed gh edit queries retired projectCards.

### Stage3 complete
- External round1 REVIEW_GREEN, 0BLOCKER/0IMPORTANT/2MINOR. Both minor fixes verified14tests42assertions, focusedPHPStan/style PASS; no external rerun needed.
- Exact next action: commit/push Stage3 and update PR2474; record newbase and implement Stage4 matrix. Full gates and whole-task fresh internal/external review remain required at handoff.
