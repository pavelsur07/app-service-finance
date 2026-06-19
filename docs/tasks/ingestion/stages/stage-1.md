### Stage 1: Block 4 schema/domain — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Added `IngestCursor` and `SyncJob` tenant-owned entities for cursor and sync job state.
- Added sync job kind/status enums, state-transition guard behavior, and block 4 exception classes.
- Added additive PostgreSQL migration for `ingest_cursors` and `ingest_sync_jobs`.
- Added unit tests for cursor behavior, sync job invariants/transitions, and the full status transition matrix.
- Saved the approved package plan to `docs/tasks/ingestion/plan.md`.

#### Files changed
- `docs/tasks/ingestion/plan.md` — new Phase 0 plan
- `site/src/Ingestion/Entity/IngestCursor.php` — new entity
- `site/src/Ingestion/Entity/SyncJob.php` — new entity
- `site/src/Ingestion/Enum/SyncJobKind.php` — new enum
- `site/src/Ingestion/Enum/SyncJobStatus.php` — new enum
- `site/src/Ingestion/Exception/*.php` — new block 4 exceptions
- `site/migrations/Version20260618120000.php` — new additive migration
- `site/tests/Unit/Ingestion/Entity/*` — new entity unit tests
- `site/tests/Unit/Ingestion/Enum/SyncJobStatusTest.php` — new transition matrix test
- `docs/tasks/ingestion/stages/stage-1.md` — new stage report

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `make codex-test-unit-filter FILTER='IngestCursorTest|SyncJobTest|SyncJobStatusTest'` — failed before PHPUnit because host `php` is unavailable in PATH
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'IngestCursorTest|SyncJobTest|SyncJobStatusTest'` — OK, 41 tests / 67 assertions
- `make site-cs-check` — failed on pre-existing style drift across 660 unrelated files
- scoped `php-cs-fixer --dry-run` on Stage 1 files — OK after one local formatting fix
- `doctrine:migrations:execute DoctrineMigrations\Version20260618120000 --up --dry-run --env=test` — OK, dry-run only
- `doctrine:migrations:execute DoctrineMigrations\Version20260618120000 --down --dry-run --env=test` — OK, dry-run only
- `doctrine:schema:validate --skip-sync --env=test` — OK, mapping valid

#### Risks / reviewer focus
- Migration creates two new tables and indexes; it was not applied to any database in this stage, only dry-run checked.
- `SyncJobTransitionPolicy` is not introduced yet; Stage 1 keeps transition rules in `SyncJobStatus::canTransitionTo()` and entity guard methods. Stage 2 may extract the policy if still desired.
- No repositories, actions, facade, rate-limit guard, or Messenger routing were added in this stage.

#### Open questions
- none
