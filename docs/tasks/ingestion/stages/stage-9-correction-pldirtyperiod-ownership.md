### Stage 9: PLDirtyPeriod ownership correction — DONE

**Risk:** MEDIUM
**Next action:** STOP, owner review required

#### What was done
- Moved dirty-period ownership from `App\Finance` to `App\Ingestion`.
- Moved `PLDirtyPeriodView` to Ingestion as the read model for the Ingestion-owned dirty-period state.
- Kept Finance orchestration classes in `App\Finance`: mark-dirty action/message/handler, rebuild action/message/handler, `PnlFacade`, and `NormalizationCompletedSubscriber`.
- Added `TenantOwnedInterface` to `PLDirtyPeriod`, so the Ingestion company filter can apply to the entity.
- Kept database table name unchanged: `pnl_dirty_periods`.
- Confirmed Doctrine already maps `App\Ingestion\Entity`; no `doctrine.yaml` change was required.

#### Files changed
- `site/src/Ingestion/Entity/PLDirtyPeriod.php` — moved from Finance, implements `TenantOwnedInterface`
- `site/src/Ingestion/Enum/PLDirtyPeriodReason.php` — moved from Finance
- `site/src/Ingestion/Enum/PLDirtyPeriodStatus.php` — moved from Finance
- `site/src/Ingestion/Repository/PLDirtyPeriodRepository.php` — moved from Finance
- `site/src/Ingestion/Application/DTO/PLDirtyPeriodView.php` — moved from Finance
- `site/tests/Unit/Ingestion/Entity/PLDirtyPeriodTest.php` — moved from Finance, tenant marker assertion added
- `site/tests/Unit/Ingestion/Enum/PLDirtyPeriodStatusTest.php` — moved from Finance
- `site/tests/Integration/Ingestion/Repository/PLDirtyPeriodRepositoryTest.php` — moved from Finance
- Finance P&L actions, facade, messages, handlers, subscriber, and command — imports updated
- Documentation and reports — updated to reflect Ingestion ownership

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

#### Checks
- `grep -rn "Finance\\\\.*PLDirtyPeriod" site/src/` — OK, no matches
- `rg -n -F 'class PLDirtyPeriod implements TenantOwnedInterface' site/src/Ingestion/Entity/PLDirtyPeriod.php` — OK
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK
- `make site-test-unit` — OK, 1063 tests / 6424 assertions, existing 1 warning and 1 deprecation remain
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'Ingestion|PLDirtyPeriod|MarkPnlPeriodDirtyAction|NormalizationCompletedSubscriber|RebuildPnlPeriodAction|RebuildDirtyPnlPeriodsCommand|PLRebuildAuditRepository' --display-phpunit-deprecations` — OK, 47 tests / 206 assertions, existing 2 PHPUnit runner deprecations remain
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.pldirty-ingestion.cache --path-mode=intersection ...` — OK, 0 fixable correction files
- `git diff --check` — OK

#### Risks / reviewer focus
- This is a namespace and ownership move only; table `pnl_dirty_periods` and entity behavior were intentionally kept unchanged.
- Review that Finance now imports only Ingestion dirty-period types and does not own the dirty-period state.

#### Open questions
- none
