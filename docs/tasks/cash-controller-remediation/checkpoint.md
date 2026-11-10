## Current checkpoint

**Phase:** Stage 4 / Work item 4.1
**Status:** implementing
**Task base:** 22b07f6ba
**Stage base commit:** 967ac022d

### Completed
- Phase 0: isolated managed worktree `/home/deploy/.codex/worktrees/f30b/app-service-finance`; branch `fix/cash-controller-remediation`.
- Stage 1 DONE: see stages/stage-1.md; module 906 tests / 3608 assertions PASS; focused PHPStan/CS clean; external 1 round fixed without re-run, 2 confirmed IMPORTANT fixed and 1 rejected with plan citation.

### Checks and baseline
- Container: `vf-cash-remediation-php`, mounted worktree /app and existing vendor only. Commands: `docker exec -e XDEBUG_MODE=off vf-cash-remediation-php ...`.
- Stage 2 baseline: existing Cash module green. New Stage 2 tests RED: SaveMoneyAccountActionTest (no recalc/no rollback/stale saved parameters); RestoreCashTransactionEventTest (successful restore does not publish change; rollback case already succeeds).
- Logs under /tmp/vf-cash-* and site/var/external-review/22b07f6b/ (ignored).

### Review status
- Stage 1 internal complete, open BLOCKER/IMPORTANT none.
- Stage 1 external: 1 round; fixed without re-run.

### Exact next action
- Implement atomic job/log creation, durable delivery failure handling and queued recovery command; finish Transaction controllers/JSON listener and backend pagination.
- Keep Stage 2 red test files outside Stage 1 commit.

### Files to inspect first on resume
- plan.md, git status; SaveMoneyAccountAction, AccountBalanceService, MoneyAccountRepository, RestoreCashTransactionAction; Stage 2 red tests.

### Rulings
- Closed-period checks in both document Actions are authorized by owner §1.2; rejected reviewer scope objection.
- Full handoff gates run once after Stage 5; Stage module checks are narrowed to Cash.

- PR: https://github.com/pavelsur07/app-service-finance/pull/2964, Draft, base master verified.
- 2.1/2.2 GREEN: 20 tests / 82 assertions, real PG rollback + restored-event commit checks; concurrent stale recalc RED against original implementations, GREEN fixed.
- 2.3: accounts/funds/transfers/calendar split into final __invoke controllers with readonly constructor DI. Shared calendar HTTP presenter reuses filtered query/forecast and existing recurrence Action; no recurrence behavior changed. Scoped CounterpartyFacade extended with optional includeArchived for filter lists, ordinary form defaults preserved; query regression RED→GREEN.

- Stage 2 DONE: module PASS 915 tests / 3699 assertions, focused PHPStan/CS clean, 74 route contracts unchanged; external round 1 REVIEW_GREEN (0 BLOCKER/IMPORTANT). Internal IMPORTANT lock-order issue fixed RED→GREEN.
- Stage 3 new functional validation tests RED: 24 cases fail on old code (session/company/storage guard, files, mapping structure/drafts, date filters); not included in Stage 2 commit.
- Stage 5 carry-over: calendar counterparty dropdown must label shownName to match facade sorting (external MINOR).

- 3.1–3.3 implemented: company/account-scoped session guard before storage and committed-job redirects; legacy states reset; structural mapping validator shared by profile Actions, HTTP mapping and profile application; Upload Action checks file validity/format/32MiB and actual CSV/XLS/XLSX readability before storage write; strict journal dates render 422. First target set PASS 114 tests / 285 assertions.
- 3.4 extraction: Prepare/Confirm Actions + reusable plan selection; all import controllers split final __invoke with constructor DI (Session read from Request). Existing flow checks PASS 99 tests / 260 assertions. Real supported-upload/mapping tests caught a static closure after DI replacement, corrected RED→GREEN; all CSV/XLS/XLSX positive uploads + negative validation: 28 tests / 86 assertions PASS.
- PR body update via gh pr edit failed due deprecated Projects Classic GraphQL; corrected with GitHub REST PATCH, no approval needed.

- Stage 3 DONE: 984 tests /3800 assertions PASS, focused PHPStan/CS clean, 74 route contracts unchanged. External 1 round, 1 IMPORTANT fixed without re-run (half-pair draft cannot execute), 3 MINOR fixed. Internal IMPORTANT4 fixed; no open blockers.
- Ruling: draft save remains structurally permissive as owner requires; actual Prepare/Confirm impose complete date+amount/full pair to preserve financial intake invariant. Row normalizer unchanged.

- 4.1/4.2 implemented: atomic journal/job (nullable confirmation included), HTTP transport-failure queued/session recovery; recovery command dry-run default, company leases, keyset100, one queue snapshot, status/failed recheck, no network row locks. RED→GREEN atomic rollback, dispatch failure; real queue paging1201 stream+1201 delayed: old unbounded zrange2 calls vs expected6 RED, paged GREEN. 202 candidates /two companies /200 skipped-head tested one probe.
- 4.3/4.4 implemented: remaining Cash controllers final __invoke/readonly constructor DI, shared page helpers; entity drafts in Save Actions UUIDv7, existing new-plan/category IDs UUIDv7 only. JSON listener explicitly includes create-from with404/403/422 contract; successful response unchanged.
- 4.5 implemented: four scoped Pagerfanta lists page1/limit50/max200; invalid/nested/out-of-range422. 51-record functional traversal + forecast equality +unbounded form choices PASS8 tests/66 assertions. Templates pending Stage5.
- Stage4 verification in progress: initial Cash module1030 tests had3 expected exception-type mismatches after introducing concrete operation exception; adapted assertions/catch, observable denial/row-count checks preserved. Focused PHPStan now clean. No migration/dependency/schedule change.

- Stage4 pre-review module GREEN1036 tests/3949 assertions;74 route contracts unchanged; focused PHPStan clean.
- ExternalStage4 round1: reportedIMPORTANT1 rejected (Symfony RequestEvent::setResponse stops propagation; real403 log test passes noERROR+ withoutconfigchange); BLOCKER0. MINOR3 addressed: delayed ZSCAN cursor instead ofrank offset, duplicatecasts, obsoleteimport. InstalledSymfony transport verified and real Redisconnectfailure test added. Review fixes being verified; no openmustfix.

- Stage4 real transport follow-up uncovered IMPORTANT: eager sender initialization throws raw RedisException before Symfony wrapping; Dispatch Action catches concrete Redis transport errors too. Real eager RED→GREEN and lazy outage tested. Delayed cursor scan guards non-array/error responses; no queue rank offsets.
- Stage4 DONE: post-review Cash module1038 tests/3956 assertions PASS; focused review73 tests/126 assertions PASS; focusedPHPStan/CS clean;74 route contracts retained. External1 round accepted withoutre-run (reportedIMPORTANT1 rejected with evidence; MINOR3fixed). InternalIMPORTANT1 eagerRedis failure fixedRED→GREEN. Next: Stage5 templates, baseline1038/3956; commitStage4 then recordStage5base.
