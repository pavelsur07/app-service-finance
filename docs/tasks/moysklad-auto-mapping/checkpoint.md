# Current checkpoint

**Phase:** Stage 4 / Work item 4.1
**Status:** Stage4 code complete; handoff gates/browser in progress
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
- Materialize Stage4 legacy controllers/forms/templates after RED4, then functional/security/browser tests and handoff.

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

Stage3 base30a98e5a7. Targeted45/194, concurrency7/66, module469/1988, focused CS/Stan PASS. Mutation locks RED2/RED1 expected; restored. Stage4 prep only in ignored scratch while review pending.

Stage3 external1 round IMPORTANT3+MINOR1 fixed without re-run; regression25/138; finalmodule474/2006; focused CS/Stan PASS. No openB/I. Stage4 scratch RED4 missing UI.

Stage4 RED4→GREEN8/68; focused CS/Stan46 PHP files PASS. Legacy controllers/forms/Twig, RBAC/CSRF/tampered selection/characteristic scope/partial apply+fresh EM retry/cache-after-commit503 tested. Full gates running once. Browser test fixture cookie wiring corrected for MockFileSessionStorageFactory (ignores HTTP cookies by design).
