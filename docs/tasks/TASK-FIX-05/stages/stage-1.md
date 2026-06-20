### Stage 1: Backfill CLI — DONE

**Risk:** MEDIUM
**Next action:** continue to Stage 2

#### What was done
- Added `app:ingestion:start-backfill`.
- Added UUID/source/resource validation, `--days-back` bounds, `--dry-run`, Ozon shop discovery, and active-backfill warning behavior.
- Wired the command to existing `SyncFacade::startBackfill()`.

#### Files changed
- `site/src/Ingestion/Command/StartBackfillCommand.php` — new
- `site/tests/Integration/Ingestion/Command/StartBackfillCommandTest.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm -e APP_ENV=test -e APP_DEBUG=1 site-php-cli php -d memory_limit=1G bin/phpunit --testsuite integration --filter 'StartBackfillCommandTest|RunIncrementalCommandTest'` — passed
- `docker compose run --rm site-php-cli ./vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.php <touched-files>` — passed

#### Risks / reviewer focus
- The command supports Ozon resources only because no production WB ingestion connector/resource list exists in this task.

#### Open questions
- none
