### Stage 7.9.1a: Schema-only Cash auto-rule CFO target — DONE

**Risk:** HIGH
**Next action:** owner review of schema-only PR #2187

#### What was done

- Added one additive migration for nullable `cash_transaction_auto_rule.responsibility_center_id`.
- Added the supporting index and restrictive foreign key to `financial_responsibility_centers(id)`.
- Kept all existing rule rows unchanged; there is no backfill or target inference.
- Made rollback available only while all target values are `NULL`; configured data makes the migration irreversible.
- Acquired the table lock before the rollback guard so concurrent writers cannot race the data-loss check.
- Verified the migration only on local `app_test` with `up -> down -> up`; the final local state is `up`.
- Kept Entity mapping, authoring UI, matcher, preview, workers, and transaction behavior outside the schema-only deliverable.

#### Files changed

- `site/migrations/Version20260717130000.php` — additive target column, index, FK, and guarded rollback.
- `docs/reviews/cash-auto-rules-stage-7-9-plan.md` — local acceptance and next gate.
- `docs/reviews/cash-auto-rules-stage-7-9-preflight.sql` — aggregate-only configuration and pair-validity checks.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — Stage 7 status.
- `docs/reviews/cash-auto-rules-stage-7-9-1a-report.md` — this Stage Report.

#### Self-review

- [x] Scope compliance: the future Stage 7.9.1a PR must contain schema/documentation only
- [x] Existing PostgreSQL migration patterns followed
- [x] No destructive forward SQL, backfill, inference, or historical processing
- [x] Restrictive FK and data-loss rollback guard checked
- [x] Tests/checks run
- [x] Documentation updated

#### Checks

- PHP syntax for the migration and prepared Stage 7.9 files — passed.
- Targeted PHP CS Fixer — passed, zero fixable files.
- Local `app_test` exact-version migration `up -> down -> up` — passed; ordinary `migrate` was intentionally not used because the local migration ledger is empty while the schema already exists.
- Schema inspection — passed: nullable UUID column, index, and `ON DELETE RESTRICT` FK exist after final `up`.
- Configured-data rollback guard — passed using a temporary shadow table inside a rolled-back transaction; no fixture or persistent data remained.
- Focused Cash/Company integration, functional, and unit set — passed: 17 tests, 83 assertions.
- Full unit suite — passed: 1506 tests, 8853 assertions.
- Twig lint — passed: 7 templates.
- Symfony container lint — passed.
- `git diff --check` and no-debug scan — passed.

Two initial rollback-guard harness attempts stopped before executing the migration because the direct PHP bootstrap and migration loader were incomplete. The corrected project test bootstrap passed; the failed attempts did not change the database.

#### Risks / reviewer focus

- Production deploy publishes application code before running migrations. The schema PR must therefore exclude the locally prepared Entity/form/controller/Twig Stage 7.9.1b changes.
- The forward migration takes short PostgreSQL locks for one column, one index, and one FK. It does not scan or rewrite rule data beyond PostgreSQL constraint/index work.
- Once any rule has a non-null target, database rollback is intentionally blocked; an older application may continue while leaving the additive column in place.
- Local `app_test` has an existing schema but an empty Doctrine migration ledger, so only the exact migration version was executed during this acceptance.
- The user-owned untracked `reports/` directory remains untouched.

#### Open questions

- none for Stage 7.9.1a; PR #2187 is the schema-only review unit.
