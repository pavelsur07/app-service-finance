# Stage 7.5: Expand Cash and Finance schema — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

## What was done

- Added one expand-only migration for nullable responsibility-center storage on four financial fact tables.
- Added restrictive foreign keys and simple FK indexes without changing Entity mappings or application behavior.
- Preserved the current P&L writer conflict target and added the future project × ЦФО unique key with `NULLS NOT DISTINCT`.
- Added schema-definition coverage and a regression test for repeated P&L upserts with nullable and non-null categories.
- Documented the deferred Stage 7.7a writer cutover and Stage 7.7b behavior activation.

## Files changed

- `site/migrations/Version20260717120000.php` — new irreversible schema-only migration.
- `site/tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php` — new column, FK, index, null-state, and P&L-key assertions.
- `site/tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php` — existing writer compatibility regression.
- `ARCHITECTURE.md` — schema expansion and deferred P&L cutover contract.
- `docs/reviews/cash-auto-rules-stage-7-5-plan.md` — approval and implementation status.
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
- Doctrine migration `--dry-run` on local `app_test` — passed; 16 migration DDL statements generated and no DDL executed.
- Stage 7.5 read-only preflight before migration — passed: zero invalid companies and zero duplicate P&L groups.
- `Version20260717120000` applied only to local `app_test` — passed.
- Post-migration row-count/null-state verification — passed: fact row counts unchanged and zero classified rows.
- Focused Stage 7.5 integration tests — passed: 3 tests, 62 assertions.
- `make site-test-integration` — passed: 714 tests, 3466 assertions.
- `make site-test-unit` — passed: 1506 tests, 8853 assertions.
- `php bin/console lint:container --env=test` — passed.
- Targeted PHP CS Fixer check for the migration and both new tests — passed.
- `make site-cs-check` — repository-wide baseline failed on 608 pre-existing files; the three Stage 7.5 PHP files are clean when checked separately.
- Integration reset cleared test migration metadata; `doctrine:migrations:version --add --all` restored all 217 versions after physical schema verification.
- `git diff --check` — passed.

## Risks / reviewer focus

- The migration aborts if legacy P&L duplicate groups exist and never repairs data implicitly.
- Both P&L unique keys coexist temporarily; the four-column key intentionally prevents multi-ЦФО totals until Stage 7.7a.
- The migration is irreversible because later stages may store real financial classifications in the added columns.
- Production preflight remains pending; the restricted read-only wrapper must be operational before production approval.

## Open questions

- none
