### Stage 8: Block 7 P&L rebuild infrastructure — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Saved the owner follow-up decision task: `docs/tasks/ingestion/FOLLOWUP-finance-source-linking.md`.
- Added Finance P&L application commands:
  - `MarkPnlPeriodDirtyCommand`
  - `RebuildPnlPeriodCommand`
- Added `PnlPeriodResolver` with `Europe/Moscow` period boundaries.
- Added `PnlCategoryResolver` with canonical transaction type/direction mapping and fallback Finance categories:
  - `INGESTION_OTHER_INCOME`
  - `INGESTION_OTHER_EXPENSE`
- Added `PnlProjectDirectionResolver`, using the existing default/first project direction for canonical rebuild rows.
- Added `MarkPnlPeriodDirtyAction`, idempotent by dirty-period natural key.
- Added `MaybeBlockByClosePeriodAction` for `Company.financeLockBefore` and `MarketplaceMonthClose` closed-stage checks.
- Added `RebuildPnlPeriodAction` with Symfony Lock, explicit DB transaction, close guard, delete-then-upsert rebuild, and `rebuiltAt` audit writes.
- Added `PnlClosedPeriodTouchedEvent`.
- Added `NormalizationCompletedSubscriber`, which dispatches mark-dirty messages and handles old/new month changes.
- Added `MarkPnlPeriodDirtyMessage`, `RebuildPnlPeriodMessage`, and handlers.
- Added `RebuildDirtyPnlPeriodsCommand`; it dispatches rebuild messages only and does not execute rebuild inline.
- Added `PnlFacade` with `markPeriodDirty`, `rebuildPeriod`, `getDirtyPeriods`, and `getProgress`.
- Added Messenger routing:
  - `MarkPnlPeriodDirtyMessage` → `ingest_normalize`
  - `RebuildPnlPeriodMessage` → `pnl_rebuild`
- Added test-only in-memory `pnl_rebuild` transport and in-memory lock factory override.
- Extended `PLDailyTotalRepository::upsert()` and `PLMonthlySnapshotRepository::upsert()` to write `rebuiltAt`; monthly upsert can accumulate for rebuild aggregation.
- Updated `ARCHITECTURE.md`.

#### Files changed
- `ARCHITECTURE.md` — modified
- `docs/tasks/ingestion/FOLLOWUP-finance-source-linking.md` — new
- `docs/tasks/ingestion/INDEX.md` — modified
- `site/config/packages/messenger.yaml` — modified
- `site/config/packages/test/messenger.yaml` — modified
- `site/config/services.yaml` — modified
- `site/config/services_test.yaml` — modified
- `site/src/Finance/Application/Action/MarkPnlPeriodDirtyAction.php` — new
- `site/src/Finance/Application/Action/MaybeBlockByClosePeriodAction.php` — new
- `site/src/Finance/Application/Action/RebuildPnlPeriodAction.php` — new
- `site/src/Finance/Application/Command/MarkPnlPeriodDirtyCommand.php` — new
- `site/src/Finance/Application/Command/RebuildPnlPeriodCommand.php` — new
- `site/src/Ingestion/Application/DTO/PLDirtyPeriodView.php` — new
- `site/src/Finance/Application/DTO/PnlProgressView.php` — new
- `site/src/Finance/Application/DTO/PnlPeriodBlockResult.php` — new
- `site/src/Finance/Application/Service/PnlCategoryResolver.php` — new
- `site/src/Finance/Application/Service/PnlPeriodResolver.php` — new
- `site/src/Finance/Application/Service/PnlProjectDirectionResolver.php` — new
- `site/src/Finance/Command/RebuildDirtyPnlPeriodsCommand.php` — new
- `site/src/Finance/Domain/Event/PnlClosedPeriodTouchedEvent.php` — new
- `site/src/Finance/EventSubscriber/NormalizationCompletedSubscriber.php` — new
- `site/src/Finance/Exception/PnlCategoryResolveException.php` — new
- `site/src/Finance/Exception/PnlProjectDirectionResolveException.php` — new
- `site/src/Finance/Exception/PnlRebuildLockTimeoutException.php` — new
- `site/src/Finance/Facade/PnlFacade.php` — new
- `site/src/Finance/Message/MarkPnlPeriodDirtyMessage.php` — new
- `site/src/Finance/Message/RebuildPnlPeriodMessage.php` — new
- `site/src/Finance/MessageHandler/MarkPnlPeriodDirtyHandler.php` — new
- `site/src/Finance/MessageHandler/RebuildPnlPeriodHandler.php` — new
- `site/src/Ingestion/Repository/PLDirtyPeriodRepository.php` — modified
- `site/src/Finance/Repository/PLDailyTotalRepository.php` — modified
- `site/src/Finance/Repository/PLMonthlySnapshotRepository.php` — modified
- `site/tests/Unit/Finance/Application/Service/PnlPeriodResolverTest.php` — new
- `site/tests/Unit/Finance/Message/PnlMessageTest.php` — new
- `site/tests/Integration/Finance/Application/MarkPnlPeriodDirtyActionTest.php` — new
- `site/tests/Integration/Finance/Application/RebuildPnlPeriodActionTest.php` — new
- `site/tests/Integration/Finance/Command/RebuildDirtyPnlPeriodsCommandTest.php` — new
- `site/tests/Integration/Finance/EventSubscriber/NormalizationCompletedSubscriberTest.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'PnlPeriodResolverTest|PnlMessageTest|PLDirtyPeriod'` — OK, 35 tests / 55 assertions
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'MarkPnlPeriodDirtyActionTest|NormalizationCompletedSubscriberTest|RebuildPnlPeriodActionTest|RebuildDirtyPnlPeriodsCommandTest|PLDirtyPeriodRepositoryTest|PLRebuildAuditRepositoryTest' --display-phpunit-deprecations` — OK, 11 tests / 37 assertions, existing 2 PHPUnit runner deprecations remain:
  - `App\Tests\Integration\Marketplace\CloseMonthStageActionPreliminaryTest::testCloseCostsThrowsWhenEntriesAreEmptyEvenIfPreflightAllowsClose`
  - `App\Tests\Integration\MarketplaceAds\AdLoadJobRepositoryTest::testIncrementRejectsNonPositiveDelta`
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'RebuildPnlPeriodActionTest' --display-phpunit-deprecations` — OK, 2 tests / 8 assertions, same existing PHPUnit runner deprecations
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK, mapping valid; DB sync intentionally skipped
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.stage8.cache --path-mode=intersection ...` — OK, 0 fixable Stage 8 files
- `make site-test-unit` — OK, 1063 tests / 6423 assertions, existing 1 warning and 1 deprecation remain in the project unit suite
- `git diff --check` — OK

#### Risks / reviewer focus
- `RebuildPnlPeriodAction` is intentionally destructive for all-source/all-shop scope: it deletes `PLDailyTotal` and `PLMonthlySnapshot` rows for the company/month before rebuilding from canonical Ingestion transactions.
- Source-scoped rebuild (`shopRef !== ''`) is not executed. The dirty period is marked `FAILED` with a message pointing to the source-linking decision, because `shop_ref` must not be added to P&L aggregate tables.
- Canonical rebuild requires an existing default or first `ProjectDirection` for the company. It does not silently create Finance project directions.
- `PnlCategoryResolver` creates fallback Finance categories if no mapped category exists. Reviewer should confirm the fallback category names/codes before enabling real rebuilds.
- No production cron entry was added for `RebuildDirtyPnlPeriodsCommand`.
- Existing legacy P&L writers are still active. This stage adds infrastructure but does not switch production P&L population away from legacy flows.

#### Open questions
- Owner decision after Ingestion acceptance/deploy: where should Finance store source/origin links: `Document`, `DocumentOperation`, both, or a dedicated link table? See `docs/tasks/ingestion/FOLLOWUP-finance-source-linking.md`.
- Should `RebuildPnlPeriodAction` remain callable before shadow-mode, or should a feature flag hard-block execution outside test/admin contexts?

### Stage 8 Review Fix: PL dirty-period naming — DONE

**Risk:** LOW
**Next action:** continue to final handoff

#### What was done
- Renamed dirty-period PHP types from `PnlDirtyPeriod*` to `PLDirtyPeriod*` for consistency with `PLDailyTotal` and `PLMonthlySnapshot`.
- Kept the database table name unchanged: `pnl_dirty_periods`.
- Updated code, tests, task docs, architecture notes, and stage reports to the new naming.

#### Files changed
- `site/src/Ingestion/Entity/PLDirtyPeriod.php` — renamed
- `site/src/Ingestion/Enum/PLDirtyPeriodReason.php` — renamed
- `site/src/Ingestion/Enum/PLDirtyPeriodStatus.php` — renamed
- `site/src/Ingestion/Repository/PLDirtyPeriodRepository.php` — renamed
- `site/src/Ingestion/Application/DTO/PLDirtyPeriodView.php` — renamed
- `site/tests/Unit/Ingestion/Entity/PLDirtyPeriodTest.php` — renamed
- `site/tests/Unit/Ingestion/Enum/PLDirtyPeriodStatusTest.php` — renamed
- `site/tests/Integration/Ingestion/Repository/PLDirtyPeriodRepositoryTest.php` — renamed

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

#### Checks
- `rg -n "PnlDirtyPeriod" site/src site/tests ARCHITECTURE.md docs/tasks/ingestion/INDEX.md docs/tasks/ingestion/TASK-*.md docs/tasks/ingestion/plan.md` — OK, no active code/task references
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'PLDirtyPeriod|PnlPeriodResolverTest|PnlMessageTest'` — OK, 35 tests / 55 assertions
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'PLDirtyPeriodRepositoryTest|MarkPnlPeriodDirtyActionTest|RebuildPnlPeriodActionTest|RebuildDirtyPnlPeriodsCommandTest' --display-phpunit-deprecations` — OK, 6 tests / 23 assertions, existing 2 PHPUnit runner deprecations remain
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK, mapping valid; DB sync intentionally skipped
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.rename-pldirty.cache --path-mode=intersection ...` — OK, 0 fixable review-fix files
- `git diff --check` — OK

#### Risks / reviewer focus
- This is a PHP/API naming change inside the unmerged Ingestion stage work. The DB table and migration SQL remain unchanged.

#### Open questions
- none
