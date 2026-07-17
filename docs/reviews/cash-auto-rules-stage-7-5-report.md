# Stage 7.5: Expand Cash and Finance schema — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

## What was done

- Added one expand-only migration for nullable responsibility-center storage on four financial fact tables.
- Added restrictive foreign keys and simple FK indexes without changing Entity mappings or application behavior.
- Preserved the current P&L writer conflict target and distinct-NULL uniqueness without adding a future key.
- Added schema-definition coverage and a regression test proving category deletion still works when an uncategorized total already exists.
- Removed the pre-DDL duplicate guard, so Stage 7.5 has no concurrent-writer check/lock race.
- Deferred P&L uniqueness, locking, nullable-category semantics, and writer cutover to a separate Stage 7.7 Phase 0.

## Files changed

- `site/migrations/Version20260717120000.php` — new irreversible schema-only migration.
- `site/tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php` — new column, FK, index, null-state, and unchanged P&L-key assertions.
- `site/tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php` — existing writer and category-deletion compatibility regression.
- `ARCHITECTURE.md` — schema expansion and deferred P&L cutover contract.
- `docs/reviews/cash-auto-rules-stage-7-5-plan.md` — approval and implementation status.
- `docs/reviews/cash-auto-rules-stage-7-5-preflight.sql` — aggregate-only production preflight without an obsolete duplicate gate.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — current Stage 7 gate.

## Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden application or production action
- [x] Security/company access checked; same-company pair validation remains deferred application behavior
- [x] Migration application and integration tests passed on local `app_test`
- [x] Documentation updated

## Checks

- PHP syntax for migration and both tests — passed.
- Doctrine migration discovery before application — passed; migration was listed as `not migrated`.
- Doctrine migration `--dry-run` on local `app_test` — passed; 13 migration DDL statements generated and no DDL executed.
- Stage 7.5 read-only preflight before migration — passed: zero invalid companies and aggregate fact counts captured.
- `Version20260717120000` applied only to local `app_test` — passed.
- Post-migration row-count/null-state verification — passed: fact row counts unchanged and zero classified rows.
- Full clean-database migration chain — passed: 217 migrations through `Version20260717120000`.
- Focused Stage 7.5 integration tests — passed: 3 tests, 61 assertions.
- `make site-test-integration` — passed on the standard empty test state: 714 tests, 3465 assertions. The first run after fixture-loading rebuild had two expected-empty-database failures; no Stage 7.5 assertion failed.
- `make site-test-unit` — passed: 1506 tests, 8853 assertions.
- `php bin/console lint:container --env=test` — passed.
- Targeted PHP CS Fixer check for the migration and both new tests — passed.
- `make site-cs-check` — repository-wide baseline failed on 608 pre-existing files; the three Stage 7.5 PHP files are clean when checked separately.
- Integration reset cleared test migration metadata; `doctrine:migrations:version --add --all` restored all 217 versions after physical schema verification.
- `git diff --check` — passed.

## Risks / reviewer focus

- P&L uniqueness is intentionally unchanged; Stage 7.5 cannot yet persist multiple ЦФО buckets for the same aggregation key.
- Stage 7.7 requires a new Phase 0 before any uniqueness/data guard so category deletion and concurrent writers remain safe.
- The migration is irreversible because later stages may store real financial classifications in the added columns.
- Production preflight remains pending; the restricted read-only wrapper must be operational before production approval.

## Open questions

- none
