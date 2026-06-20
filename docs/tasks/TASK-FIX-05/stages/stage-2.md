### Stage 2: Incremental CLI — DONE

**Risk:** MEDIUM
**Next action:** STOP before Stage 3 cron, owner review required

#### What was done
- Added incremental application DTO/action and `SyncFacade::startIncremental()`.
- Added cursor lookup by `(companyId, connectionRef, resourceType)`.
- Added `app:ingestion:run-incremental` for active Ozon seller connections with existing non-empty cursors.
- Skips Wildberries incremental as out of scope.
- Updated `ARCHITECTURE.md` for the new `SyncFacade` method.

#### Files changed
- `site/src/Ingestion/Application/Action/StartIncrementalAction.php` — new
- `site/src/Ingestion/Application/Command/StartIncrementalCommand.php` — new
- `site/src/Ingestion/Command/RunIncrementalCommand.php` — new
- `site/src/Ingestion/Facade/SyncFacade.php` — modified
- `site/src/Ingestion/Repository/IngestCursorRepository.php` — modified
- `site/tests/Integration/Ingestion/Command/RunIncrementalCommandTest.php` — new
- `ARCHITECTURE.md` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `make site-test-prepare` — failed on pre-existing dirty test DB migration state (`bot_links.updated_at` already existed)
- `make site-test-db-rebuild` — passed
- `docker compose run --rm -e APP_ENV=test -e APP_DEBUG=1 site-php-cli php -d memory_limit=1G bin/phpunit --testsuite integration --filter 'StartBackfillCommandTest|RunIncrementalCommandTest'` — passed, 8 tests / 49 assertions
- `make site-test-unit` — passed, PHPUnit reported 1 warning and 1 deprecation
- `make site-cs-check` — failed on pre-existing project-wide CS drift outside this task
- `docker compose run --rm site-php-cli ./vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.php <touched-files>` — passed
- `docker compose run --rm site-php-cli php bin/console lint:container --env=prod` — passed with existing deprecation notices

#### Risks / reviewer focus
- Incremental dispatch starts only when a cursor row exists and `cursorValue` is non-empty; this avoids accidental hot-rewind pulls.
- `ActiveBackfillExistsException` is reused as the existing active resource guard exception, including for incremental jobs.

#### Open questions
- Stage 3 cron entry needs owner approval before implementation.
