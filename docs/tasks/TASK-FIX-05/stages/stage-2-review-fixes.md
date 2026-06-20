### Stage 2 Review Fixes: Ingestion CLI correctness — DONE

**Risk:** MEDIUM
**Next action:** STOP before Stage 3 cron, owner review required

#### What was done
- Fixed Ozon non-windowed incremental pulls so daily report and realization return the next cursor after fetched work.
- Changed incremental company limiting to collect eligible cursor work first and select oldest cursor groups, avoiding deterministic starvation of later companies.
- Removed `ozon_seller_realization` from default/manual backfill support until month-aligned chunking is implemented separately.

#### Files changed
- `site/src/Ingestion/Application/Source/Ozon/OzonSellerReportConnector.php` — modified
- `site/src/Ingestion/Command/RunIncrementalCommand.php` — modified
- `site/src/Ingestion/Command/StartBackfillCommand.php` — modified
- `site/tests/Integration/Ingestion/Command/*CommandTest.php` — modified
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonSellerReportConnectorTest.php` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm -e APP_ENV=test -e APP_DEBUG=1 site-php-cli php -d memory_limit=1G bin/phpunit --testsuite integration --filter 'StartBackfillCommandTest|RunIncrementalCommandTest'` — passed, 10 tests / 47 assertions
- `docker compose run --rm site-php-cli php -d memory_limit=1G bin/phpunit --testsuite unit --filter OzonSellerReportConnectorTest` — passed, 4 tests / 17 assertions
- `docker compose run --rm site-php-cli ./vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.php <touched-files>` — passed

#### Risks / reviewer focus
- Daily incremental cursor is capped at yesterday; repeated same-day cron runs skip daily work that is not due yet.
- Realization backfill is intentionally disabled in this PR until monthly chunking is designed.

#### Open questions
- Stage 3 cron entry still needs owner approval before implementation.
