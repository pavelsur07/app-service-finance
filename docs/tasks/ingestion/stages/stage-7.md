### Stage 7: Block 7 P&L dirty-period schema — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Added Ingestion-side `PLDirtyPeriod` entity for month-level dirty markers keyed by `companyId`, `periodYear`, `periodMonth`, and `shopRef`.
- Added `PLDirtyPeriodStatus` and `PLDirtyPeriodReason` enums with explicit transition matrix and labels.
- Added `PLDirtyPeriodRepository` methods for tenant-scoped lookup, pending worker reads, company pending reads, and status counters.
- Added nullable `rebuiltAt` audit fields to `PLDailyTotal` and `PLMonthlySnapshot`.
- Added `deleteByCompanyShopAndMonth()` methods to P&L aggregate repositories.
- Kept delete methods conservative: current P&L aggregate tables do not store `shop_ref` and should not receive it, so `shopRef = ''` deletes only the requested company/month and non-empty `shopRef` is rejected with `LogicException`.
- Captured the post-deploy Finance source-linking decision as `docs/tasks/ingestion/FOLLOWUP-finance-source-linking.md`.
- Added additive Doctrine migration `Version20260618140000`.
- Added unit and integration coverage for entity invariants/transitions, repository tenant scope, pending reads, counters, and safe P&L month deletes.
- Updated `ARCHITECTURE.md` with the new Finance dirty-period infrastructure and the current `shopRef` limitation.

#### Files changed
- `ARCHITECTURE.md` — modified
- `site/migrations/Version20260618140000.php` — new
- `site/src/Ingestion/Entity/PLDirtyPeriod.php` — new
- `site/src/Finance/Entity/PLDailyTotal.php` — modified
- `site/src/Finance/Entity/PLMonthlySnapshot.php` — modified
- `site/src/Ingestion/Enum/PLDirtyPeriodReason.php` — new
- `site/src/Ingestion/Enum/PLDirtyPeriodStatus.php` — new
- `site/src/Ingestion/Repository/PLDirtyPeriodRepository.php` — new
- `site/src/Finance/Repository/PLDailyTotalRepository.php` — modified
- `site/src/Finance/Repository/PLMonthlySnapshotRepository.php` — modified
- `site/tests/Unit/Ingestion/Entity/PLDirtyPeriodTest.php` — new
- `site/tests/Unit/Ingestion/Enum/PLDirtyPeriodStatusTest.php` — new
- `site/tests/Integration/Ingestion/Repository/PLDirtyPeriodRepositoryTest.php` — new
- `site/tests/Integration/Finance/Repository/PLRebuildAuditRepositoryTest.php` — new
- `docs/tasks/ingestion/FOLLOWUP-finance-source-linking.md` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'PLDirtyPeriod'` — OK, 31 tests / 49 assertions
- `docker compose run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test` — OK, migrated test DB to `DoctrineMigrations\Version20260618140000`
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'PLDirtyPeriodRepositoryTest|PLRebuildAuditRepositoryTest'` — OK, 6 tests / 17 assertions, existing 2 PHPUnit runner deprecations remain:
  - `App\Tests\Integration\Marketplace\CloseMonthStageActionPreliminaryTest::testCloseCostsThrowsWhenEntriesAreEmptyEvenIfPreflightAllowsClose`
  - `App\Tests\Integration\MarketplaceAds\AdLoadJobRepositoryTest::testIncrementRejectsNonPositiveDelta`
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK, mapping valid; DB sync intentionally skipped
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.stage7.cache --path-mode=intersection ...` — OK, 0 fixable Stage 7 files
- `make site-test-unit` — OK, 1059 tests / 6417 assertions, existing 1 warning and 1 deprecation remain in the project unit suite
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --env=test` — FAILED on existing project-wide schema drift; mapping is valid. `schema:update --dump-sql` includes many unrelated diffs and Doctrine timestamp precision normalization (`TIMESTAMP(6)` to `TIMESTAMP(0)`) for new and earlier migration-created timestamp columns.
- `git diff --check` — OK

#### Risks / reviewer focus
- Migration is additive in `up()`: creates `pnl_dirty_periods` and adds nullable `rebuilt_at` to existing P&L aggregate tables. `down()` drops those additions.
- Existing P&L aggregate tables must not have a shop/source dimension. The repository methods deliberately reject non-empty `shopRef` to prevent accidental all-shop deletion behind a shop-scoped call.
- Source linkage should be designed after Ingestion acceptance/deploy at the `Document` / `DocumentOperation` boundary or in a dedicated link table; the exact business field name is still open.
- This stage does not wire `NormalizationCompletedEvent`, Messenger workers, Redis locks, close-period checks, or canonical rebuild execution. Those remain for later stages.
- Full Doctrine schema sync is already noisy in this project; reviewer should focus on the new migration SQL and the explicit `TIMESTAMP(6)` precision decision.

#### Open questions
- What is the correct business name and storage boundary for source/origin links in Finance documents: `Document`, `DocumentOperation`, both, or a dedicated link table?
