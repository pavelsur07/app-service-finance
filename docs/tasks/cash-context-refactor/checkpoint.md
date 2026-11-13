# Cash context refactor checkpoint

**Phase:** Stage 3 / Work item 3.1
**Status:** implementing
**Task/stage base commit:** `1f874580f7b54722f24d9d99117b337f3756e963`
**Branch:** `refactor/cash-contexts`
**Workspace:** `/home/deploy/.codex/worktrees/0fb9/app-service-finance`

## Completed
- Docs PR #2977 merged; workflow success, deploy skipped (docs-only); production read-only container health checked.
- Isolated test container `cash-refactor-cli` mounts this checkout and existing local vendor, local Compose network.
- B1 baseline: 40 tests, 157 assertions, green.
- Exact-name search confirms generator and document method declaration-only; wrapper has two test consumers.
- Migrated wrapper assertions to match()->rule; old implementation: 39 tests, 152 assertions, green.

## Checks and baseline
- B0/B2/B3 combined module/mixed guard baseline: 1025 tests, 4093 assertions, green. `/tmp/cash-baseline-module.log`.

## Rulings
- Characterization for behavior-preserving refactor stays green before/after (TASK NFR overrides generic red-first skill).
- Existing shared 1C dependencies remain frozen; transfers requiring protected caller edits will be excluded.

## Review
- Stage internal pending; external not needed for Stage 1.

## Exact next action
- Check module baseline, test migrated assertions before removing wrapper, remove two dead elements and wrapper; run B1 and protected check.

## Stage 1 closed
B1 40/157 PASS; focused PHPStan exit 0; PHP lint PASS; protected paths unchanged. Internal 0/0; external not required.
Next: mechanical context moves via /tmp/cash-move-contexts.py; preserve AddCashTransactionAction due protected caller.

## Stage 2 progress
- Mechanical map executed: moves.json (all eligible actions/services); AddCashTransactionAction excluded due protected test.
- DI lint first exposed sibling FundBalanceService reference; fixed explicit imports. Container lint PASS.
- Focused PHPStan initially 15 errors, all from relative DTO\MoneyAccountBankLinkInput after move; fixed to explicit DTO import, narrow rerun pending/completing.
- CS fixer changed import ordering in 60/211 changed PHP files; no behavior changes.
- Module test session 53834 still running: /tmp/cash-stage2-tests.log (no failures through 793/1025).
- PHPStan initial session 33526 exit1 recorded; corrected narrow session 99992 /tmp/cash-stage2-stan-fix.log.
- file_refactor subagent read-only exploration complete, waiting for explicit authorization to implement Stage3 after Stage2 closes.
- Protected paths check still PASS.
- PR #2978 Draft base master.
Next: finish Stage2 tests/static + review, commit/push/report; then authorize file_refactor Stage3 edits.

## Stage 2 verification update
Module tests PASS 1025 tests / 4093 assertions. Focused PHPStan 15 errors traced to relocated relative DTO; corrected narrow rerun exit0. Focused CS PASS including final SaveMoneyAccountAction. DI lint PASS; AI consumer tagged registration confirmed. 73 moves / 1 protected-caller exclusion; baseline 19 path/class lines replaced without count growth. Independent stage reviewer review_context_moves running.

Stage2 CLOSED: independent review 0/0; report stages/stage-2.md. Next authorize Stage3 file_refactor implementation; keep mapping validator/Page frozen.
