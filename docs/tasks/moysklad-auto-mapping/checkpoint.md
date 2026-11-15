# Current checkpoint

**Phase:** Stage 3 / Work item 3.1
**Status:** Stage2 complete; Stage3 tests next
**Task base / Stage 1 base:** `0658c9fc6a3c4c3519f6e254d6669200b6a38753`
**Branch:** feat/moysklad-auto-mapping
**PR:** #3023 https://github.com/pavelsur07/app-service-finance/pull/3023
**Workspace:** /home/deploy/.codex/worktrees/0d8f/app-service-finance

## Completed
- TASK/plan read; owner authorized all four Stages.
- Clean managed worktree reused on fresh branch from latest master.
- Three existing patterns inspected: manual mapping writer/UI, catalog sync lock, preview/apply composition (no financial code reused).
- Pre-flight interfaces/rulings saved in ignored plan ledger.
- Isolated test Compose prepared; existing owner checkout left untouched.

## Checks
- Baseline unit PASS: 4822 tests / 26773 assertions.
- Existing scoped module PASS: 395 tests / 1668 assertions.
- Empty isolated DB migrations PASS: 319 versions.
- Policy RED: 35 cases fail on missing AutomaticListingMappingPolicy, as expected.

## Stage 1 Definition of Done
- Exact article/parent+explicit size policy and bounded preview queries.
- Tenant isolation, archive/truncation/unknown-size conservatism.
- Unit/integration module checks, focused PHPStan/CS, local 50k EXPLAIN.
- Complete Stage diff self-review; required external review if >500 HIGH-LOCAL.
- Stage Report, commit/push Draft PR, continue Stage 2 automatically.

## Exact next action
- Stage3 RED for guarded writer/batch/locking; reuse single writer.

## Resume
- This checkpoint, TASK.md/plan.md, .superpowers/sdd/plan/progress.md; verify git status/log.

## Stage1 progress
- Initial pure policy GREEN35/98; expanded policy+queries/manual target regression GREEN49/171.
- New query RED6 after test-fixture corrections (immutable marketplace, unique internal IDs/names).
- Static analysis first found6 test array-shape errors, fixed; focused PHPStan PASS.
- Shared CatalogTargetDTO row factory preserves manual target semantics; ruler recorded.
- Self-review IMPORTANT1: Cyrillic characteristic search under C collation, proven RED; fixed; regression GREEN1/9.

- Stage1 module PASS439/1817; focused PHPStan/CS PASS; git diff --check PASS.
- Local50k profile: one bounded query, 131.78ms; EXPLAIN151.785ms, rolled back.
- Internal review: BLOCKER0, IMPORTANT1 fixed (Cyrillic characteristic search). External review running.

Stage1 final module442/1827, focused gates PASS; Claude1round IMPORTANT1 fixed without re-run, MINOR1 fixed, openB/I0/0. See stages/stage-1.md.

Stage2 targeted7/41 PASS including real Redis parallel workers. Focused PHPStan/CS PASS. Internal B/I0/0. Stage2 base03bd475df.

Stage2 module449/1868 PASS, no open B/I.
