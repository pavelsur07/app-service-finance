## Current checkpoint

**Phase:** Stage 2 / Work item 2.1
**Status:** implementing
**Task base:** 22b07f6ba
**Stage base commit:** record Stage 1 commit before implementation

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
- Implement atomic SaveMoneyAccountAction and current parameter reload under balance lock; add restore post-commit event; split Stage 2 controllers and extract calendar query/Company facade reads.
- Keep Stage 2 red test files outside Stage 1 commit.

### Files to inspect first on resume
- plan.md, git status; SaveMoneyAccountAction, AccountBalanceService, MoneyAccountRepository, RestoreCashTransactionAction; Stage 2 red tests.

### Rulings
- Closed-period checks in both document Actions are authorized by owner §1.2; rejected reviewer scope objection.
- Full handoff gates run once after Stage 5; Stage module checks are narrowed to Cash.
