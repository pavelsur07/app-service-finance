# Current checkpoint

**Phase:** Stage 3 / Work items 3.1–3.2
**Status:** implementing
**Task base / Stage 1 base:** `e0b5ac0d276ccb58d38c069722a2fad90358edda`
**Branch:** `feat/moysklad-listing-mapping`
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3016 (Draft, base master)
**Stage 2 base:** `bd3b59bf4`
**Stage 3 base:** `fb218990a`
**Workspace:** `/home/deploy/.codex/worktrees/0d8f/app-service-finance`

## Completed
- Approved TASK/plan/review read; all four Stages authorized.
- Existing clean managed worktree reused with a fresh branch from origin/master.
- Owner changes in primary checkout left untouched.
- Isolated local/test Compose environment prepared in ignored .superpowers/sdd/plan.

## Checks and baseline
- Unit baseline: make site-test-unit (isolated Compose) — PASS:
  4807 tests, 26743 assertions, 13.324 s.
- Initial Docker network allocation failed (default pools exhausted);
  fixed only ignored task Compose with a verified unused dedicated subnet.
- Existing 318 migrations applied to the empty isolated test DB — PASS.
- Narrow baseline: PASS, 112 tests, 514 assertions.
- Work item 1.1: RED — 8 new cases fail because the Facade method is absent.
- Work item 1.2: GREEN — 31 tests, 78 assertions (Facade + listing query).
- Focused PHPStan: PASS, no errors; project CS fixer applied only task files.
- Stage 2 RED: revision unit 5 cases / storage 15 cases fail on absent feature.
- Stage 2 module GREEN after review fixes: 280 tests / 1067 assertions.
- New migration up/down/up on empty isolated DB — PASS; non-empty down guard
  covered in integration test. No backfill or changes to existing tables.
- Schema dump: no mapping table/FK/index changes; unrelated test_money_holder
  test fixture remains in global schema diff.
- Focused Stage 2 PHPStan — PASS after fixing test typing errors (initial 6,
  then 2 remaining); new migration added to existing scanFiles for regression test.
- Project CS fixer applied to the Stage files; full gates reserved for handoff.

## Review status
- Stage 1 internal: B/I 0/0; report stages/stage-1.md, commit bd3b59bf4.
- Implementation external: pending.
- Stage 2 external Claude CLI: 1 round, REVIEW_GREEN; B/I 0/0,
  3 MINOR fixed and verified (see stages/stage-2.md).
- Stage 3 RED: 13 Action/catalog cases and 3 read-model cases on absent services.
- Stage 3 GREEN module: 302 tests / 1160 assertions; actual PostgreSQL
  concurrency cases 4 tests / 30 assertions (two processes, observed lock waits).
- Stage 3 first PHPStan: 17 typing issues; fixed nullable legacy Company ID
  calls/test fixture and proven non-null current revision. Last focused run PASS.
- Test-only public roots added in services_test.yaml so pre-UI services remain
  reachable in integration/child-process tests; production service visibility unchanged.
- Stage 3 external: Claude 1 round, B/I 0/1, 4 MINOR. Confirmed test coverage
  IMPORTANT fixed with foreign company/listing/connection/product/variant,
  same external UUID under two connections, missing-source set/clear, 100-ID
  boundary, backslash/out-of-range and concurrent parent archive cases.
- MINOR fixes: expected domain errors returned from transaction and thrown
  outside (EM remains open), canonical batch keys, validated history IDs.
  Three regression cases proven RED then GREEN (26 tests/81 assertions).
- Archive race check PASS (real worker waits on parent's FOR SHARE); first
  version of this new test used unavailable Product::getId, corrected to
  tenant/external UUID lookup. Focused PHPStan PASS.
- Plan review is historical, not evidence of implementation correctness.

## Exact next action
- Stage 2 committed/pushed fb218990a.
- Stage 3 final module PASS: 316 tests / 1209 assertions.
- Commit/push Stage 3 and continue Stage 4 tests first; Stage Report ready.

## Resume files
- TASK.md, plan.md, this checkpoint; .superpowers/sdd/plan/progress.md.
- git status and git log to verify actual progress.
