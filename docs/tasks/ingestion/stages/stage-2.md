### Stage 2: Block 4 orchestration — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Added cursor and sync job repositories with explicit `companyId` read methods.
- Added sync job transition policy, command DTOs, orchestration actions, `SyncFacade`, progress view, and rate-limit guard.
- Added tenant-aware `RunSyncChunkMessage`.
- Added `ingest_fetch` and `ingest_normalize` Messenger transports plus test in-memory overrides.
- Updated architecture notes for cursor/sync jobs and updated `AGENTS.md` with owner stop-notification guidance.
- Added repository, facade, and message tests for Block 4 orchestration.

#### Files changed
- `AGENTS.md` — updated autonomous stop/options guidance
- `ARCHITECTURE.md` — documented Ingestion cursor/sync jobs
- `site/config/packages/messenger.yaml` — added ingestion transports and `RunSyncChunkMessage` routing
- `site/config/packages/test/messenger.yaml` — added in-memory ingestion transports
- `site/config/services.yaml` — made `SyncFacade` public for current/future integration use
- `site/src/Ingestion/Application/*` — new commands, actions, DTO, rate-limit service
- `site/src/Ingestion/Domain/Service/SyncJobTransitionPolicy.php` — new transition policy
- `site/src/Ingestion/Facade/SyncFacade.php` — new facade
- `site/src/Ingestion/Message/RunSyncChunkMessage.php` — new tenant-aware message
- `site/src/Ingestion/Repository/*` — new cursor/job repositories
- `site/tests/Unit/Ingestion/Message/RunSyncChunkMessageTest.php` — new unit test
- `site/tests/Integration/Ingestion/*` — new repository/facade integration tests
- `docs/tasks/ingestion/stages/stage-2.md` — new stage report

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test` — OK, test DB migrated to `Version20260618120000`
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'RunSyncChunkMessageTest|IngestCursorTest|SyncJobTest|SyncJobStatusTest'` — OK, 42 tests / 71 assertions
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite integration --filter 'IngestCursorRepositoryTest|SyncJobRepositoryTest|SyncFacadeTest'` — OK, 7 tests / 30 assertions, PHPUnit deprecation notices present
- scoped `php-cs-fixer --dry-run` on Stage 2 files — OK
- `docker compose run --rm site-php-cli php bin/console lint:yaml --parse-tags config/packages/messenger.yaml config/packages/test/messenger.yaml config/services.yaml --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK, mapping valid

#### Risks / reviewer focus
- `messenger.yaml` now defines two new transports and routes `RunSyncChunkMessage` to `ingest_fetch`; this is a mandatory review point.
- Parent backfill job is marked `RUNNING` immediately after split so child completion can legally finalize the parent under the state machine.
- `MarkJobCompletedAction` and `MarkJobFailedAction` flush child state before parent aggregate counting; this avoids stale DB counts during parent finalization.
- `IngestRateLimitGuard` returns an acquired lock and throws when the same key is already locked.

#### Open questions
- none
