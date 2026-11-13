# Cash context refactor checkpoint

**Phase:** Stage 2 / Work item 2.1
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
