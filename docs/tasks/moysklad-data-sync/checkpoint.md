## Current checkpoint

**Phase:** handoff
**Status:** local verification and reviews complete
**Task base commit:** `2d23ac36f9c6504743460fc064406a5088b7464f`
**Branch / Draft PR:** `feat/moysklad-counterparty-sync` / #2495

### Completed

- Stage 1 schema/storage (`421908c2`), Stage 2 API/parser (`d1fd044b`), Stage 3 full scan/Messenger (`0d52c55c`), Stage 4 manual UI/status (`84825eb6`) committed and pushed.
- Stage 1 and Stage 3 required external reviews completed; their confirmed findings fixed without re-run.
- First fresh final internal review found 1 BLOCKER, 5 IMPORTANT, 1 MINOR. Fixes are in working tree: locked guarded rollback, composite company/connection FK, DBAL failed-run update after ORM error, bounded run history, retry-exhaustion error log, missing recovery/error tests and millisecond precision. Second review found 1 IMPORTANT: missing Messenger start/terminal logs. Fixed with a red/green regression test. Third fresh read-only review returned `REVIEW_GREEN`.
- Changed migration applied to clean local test-BД (262 migrations), empty `down → up` passed, nonempty `down` refused as designed; synthetic rows were removed.

### Checks

- Baseline: MoySklad unit 46 / 227, integration 16 / 60, both PASS.
- Before final-review fixes: `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit` PASS (2945 / 16289), `make site-test` PASS on clean DB (5079 / 28965). Initial full run had 18 unrelated failures caused by demo fixtures from `site-test-db-rebuild`; clean migration-only DB resolved them, and 22 previously failing tests passed separately.
- After fixes: MoySklad integration/functional plus UTC type 66 tests / 356 assertions PASS; focused PHPStan PASS; Twig lint PASS; Doctrine mapping PASS. Final `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit` (2945 / 16289), `make site-stan`, and `make site-test` (5090 / 29044) PASS on clean migration-only test DB.
- The first final full suite ran 5088 tests / 29030 assertions with one unrelated intermittent `ApiKeyActionsTest` failure: its raw SQL reads two rows without `ORDER BY` but asserts row zero. The exact test passed on targeted rerun (1 / 13). No Api code changed.
- Global `doctrine:schema:validate` synchronicity remains red from pre-existing project-wide drift; metadata mapping is valid. No production state touched.

### Review status

- Internal final: round 1 found 1 BLOCKER / 5 IMPORTANT / 1 MINOR, round 2 found 0 / 1 / 0; all addressed. Round 3 returned `REVIEW_GREEN`.
- External handoff: 1 round, 0 BLOCKER / 1 IMPORTANT / 3 MINOR, all fixed with targeted checks, no re-run required. Stage 1: 0 / 2 / 4; Stage 3: 0 / 2 / 3, all confirmed fixes verified. Fresh internal review of the external fixes returned `REVIEW_GREEN`.

### Exact next action

- After Ready handoff and Owner approval, run the approved production migration dispatch, merge/deploy pipeline and read-only post-deploy acceptance. Until then do not touch production.

### Files to inspect first on resume

- `docs/tasks/moysklad-data-sync/plan.md`
- `site/migrations/Version20260920110000.php`
- `site/src/MoySklad/Application/Action/SyncCounterpartiesAction.php`
- `site/src/MoySklad/Infrastructure/Query/MoySkladCounterpartySyncStatusQuery.php`
