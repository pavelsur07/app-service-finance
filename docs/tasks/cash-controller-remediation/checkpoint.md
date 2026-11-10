## Current checkpoint

**Phase:** Stage 3 / Work item 3.1
**Status:** implementing
**Task base:** 22b07f6ba
**Stage base commit:** record Stage 2 commit before implementation

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
- Implement company-scoped import session guard before storage, shared structural mapping validator and file/date validation; extract preparation/confirmation Actions then split import controllers.
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
