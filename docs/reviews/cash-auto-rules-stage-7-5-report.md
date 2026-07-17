# Stage 7.5: Expand Cash and Finance schema — DONE

**Risk:** HIGH
**Next action:** DONE; production accepted, proceed only through the approved Stage 7.6 Phase 0 gate

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

## Production acceptance

- PR #2185 merged to `master` as merge commit `3a47cc8673b5312f79f8138ffc862aa9737f8e5a`.
- Unit, API type, empty-database migration, image build, frontend lint, rolling deployment, and production migration jobs passed.
- Production migrated successfully to `DoctrineMigrations\\Version20260717120000`: one migration, 13 SQL statements, no backfill.
- Post-deploy production containers were running; application workers, PHP-FPM, nginx, PostgreSQL, and Redis reported healthy where health checks exist.
- The restricted `codex-psql-ro` wrapper became intermittent again after deployment, so the separate post-deploy column/null SQL check was not counted as passed. The successful migration job log is the deployment evidence; wrapper reliability remains operational follow-up work.
- Deployment logs also reported four old executed migrations absent from the current migration registry and existing environment deprecation/warning noise. These warnings did not fail Stage 7.5 and are outside the financial stage scope.
