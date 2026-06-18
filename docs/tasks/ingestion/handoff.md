# Ingestion Blocks 4-7 Final Handoff

## Status

**Final state:** DONE for `TASK-04` through `TASK-07`.

**Next action:** STOP, owner review and acceptance required.

`TASK-08` and `TASK-09` were not executed because their task files are absent and were explicitly kept out of this implementation scope.

## Stage Summary

- Stage 1 - Block 4 schema/domain: added `IngestCursor`, `SyncJob`, enums, transition exceptions, migration, entity tests.
- Stage 2 - Block 4 orchestration: added repositories, transition policy, sync actions, `SyncFacade`, fetch messages/handler, rate-limit guard, Messenger routing.
- Stage 3 - Block 5 canon schema: added shared `Money`, canonical Ingestion transaction/counterparty/issue model, migration, repositories, tenant-leak coverage.
- Stage 4 - Block 5 normalization pipeline: added connector/mapper contracts, registries, normalization/upsert/issue actions, event, messages/handlers, fake test pipeline, `IngestionFacade`.
- Stage 5 - Block 6 reconnaissance: inspected legacy Ozon integration and saved findings before implementation.
- Stage 6 - Block 6 Ozon connector: added legacy adapter, Ozon connector, daily/realization mappers, fixtures, mapping docs, unit/integration tests.
- Stage 7 - Block 7 P&L dirty-period schema: added `PLDirtyPeriod`, enums, repository, migration, `rebuiltAt` on P&L aggregates, delete helpers, tests.
- Stage 8 - Block 7 rebuild infrastructure: added P&L dirty marking, event subscriber, close guard, rebuild action/messages/handlers, CLI dispatch command, `PnlFacade`, tests.
- Stage 8 review fix: renamed `PnlDirtyPeriod*` PHP types to `PLDirtyPeriod*` for consistency with existing `PLDailyTotal` / `PLMonthlySnapshot` naming.
- Stage 9 correction: moved `PLDirtyPeriod*` ownership from `App\Finance` to `App\Ingestion` and added `TenantOwnedInterface` to the entity.
- Stage 10 correction: updated shared `Money` to support signed minor units, negative subtraction results, and real sign negation.

Detailed per-stage reports are in `docs/tasks/ingestion/stages/stage-1.md` through `stage-10-correction-money-signed.md`.

## Files Changed

### Documentation

- `ARCHITECTURE.md`
- `docs/tasks/ingestion/INDEX.md`
- `docs/tasks/ingestion/TASK-04-cursor-syncjob.md`
- `docs/tasks/ingestion/TASK-05-connector-canon.md`
- `docs/tasks/ingestion/TASK-06-ozon-connector.md`
- `docs/tasks/ingestion/TASK-07-pnl-projection.md`
- `docs/tasks/ingestion/plan.md`
- `docs/tasks/ingestion/FOLLOWUP-finance-source-linking.md`
- `docs/tasks/ingestion/stages/stage-1.md` through `stage-10-correction-money-signed.md`
- `docs/ingestion/ozon-mapping.md`

### Configuration

- `site/config/packages/messenger.yaml`
- `site/config/packages/test/messenger.yaml`
- `site/config/services.yaml`
- `site/config/services_test.yaml`

### Migrations

- `site/migrations/Version20260618120000.php`
- `site/migrations/Version20260618130000.php`
- `site/migrations/Version20260618140000.php`

### Shared

- `site/src/Shared/Domain/ValueObject/Money.php`
- `site/src/Shared/Domain/Exception/MoneyMismatchException.php`

### Ingestion

- New Ingestion entities, enums, repositories, actions, commands, DTOs, contracts, events, services, facades, messages, and handlers under `site/src/Ingestion/`.
- Ozon adapter/connector/mapper implementation under `site/src/Ingestion/Application/Source/Ozon/` and `site/src/Ingestion/Infrastructure/Api/Ozon/`.
- Existing `site/src/Ingestion/Entity/IngestRawRecord.php` and `site/src/Ingestion/Repository/IngestRawRecordRepository.php` were extended with normalization support.

### Finance

- New P&L rebuild orchestration classes under `site/src/Finance/Application/`, `site/src/Finance/Command/`, `site/src/Finance/Domain/`, `site/src/Finance/EventSubscriber/`, `site/src/Finance/Exception/`, `site/src/Finance/Facade/`, `site/src/Finance/Message/`, `site/src/Finance/MessageHandler/`.
- New dirty-period pipeline state under `site/src/Ingestion/Entity/PLDirtyPeriod.php`, `site/src/Ingestion/Enum/PLDirtyPeriodReason.php`, `site/src/Ingestion/Enum/PLDirtyPeriodStatus.php`, `site/src/Ingestion/Repository/PLDirtyPeriodRepository.php`.
- Existing `PLDailyTotal`, `PLMonthlySnapshot`, and their repositories were extended with `rebuiltAt` and rebuild delete/upsert support.

### Tests And Fixtures

- New unit/integration coverage under `site/tests/Unit/Ingestion/`, `site/tests/Integration/Ingestion/`, `site/tests/Unit/Finance/`, `site/tests/Integration/Finance/`.
- New Ozon fixtures under `site/tests/Fixtures/Ingestion/Ozon/`.

## Migrations

- `Version20260618120000`: creates `ingest_cursors` and `ingest_sync_jobs`.
- `Version20260618130000`: creates `ingest_counterparties`, `ingest_financial_transactions`, and `ingest_normalization_issues`.
- `Version20260618140000`: creates `pnl_dirty_periods`, adds nullable `rebuilt_at` to `pl_daily_totals` and `pl_monthly_snapshots`.

`up()` migrations are additive. No existing table or column is dropped or renamed in `up()`.

`down()` migrations are destructive by nature: they drop the new Ingestion/P&L tables and remove the new `rebuilt_at` columns.

## Public Contracts

- New internal facades: `SyncFacade`, `IngestionFacade`, `PnlFacade`.
- New connector contracts: `SourceConnectorInterface`, `SourceMapperInterface`, `RawRecordAwareControlSumMapperInterface`.
- New Messenger messages: `RunSyncChunkMessage`, `NormalizeRawRecordMessage`, `MarkPnlPeriodDirtyMessage`, `RebuildPnlPeriodMessage`.
- New Messenger transports/routes: `ingest_fetch`, `ingest_normalize`, `pnl_rebuild`.
- New CLI command: `finance:pnl:rebuild-dirty`.
- No HTTP API endpoint was added or changed.
- No production cron/worker entry was enabled.
- No live external Ozon API call was made during implementation or verification.

## Checks

- `make site-test-unit` - OK, 1070 tests / 6438 assertions; existing 1 warning and 1 deprecation remain in the project unit suite.
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'Ingestion|PLDirtyPeriod|MarkPnlPeriodDirtyAction|NormalizationCompletedSubscriber|RebuildPnlPeriodAction|RebuildDirtyPnlPeriodsCommand|PLRebuildAuditRepository' --display-phpunit-deprecations` - OK, 47 tests / 206 assertions; existing 2 PHPUnit runner deprecations remain in Marketplace/MarketplaceAds tests.
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` - OK.
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` - OK for mapping; DB sync check intentionally skipped.
- Scoped `php-cs-fixer` for changed Ingestion/Finance files - OK, 0 fixable files after local review fix.
- `git diff --check` - OK.
- `rg -n "PnlDirtyPeriod" site/src site/tests ARCHITECTURE.md docs/tasks/ingestion/INDEX.md docs/tasks/ingestion/TASK-*.md docs/tasks/ingestion/plan.md` - OK, no active code/task references.

`make site-cs-check` was also run and failed on existing project-wide style drift: 659 of 1728 files are fixable, mostly outside this task scope. This dry-run did not modify files.

No Makefile PHPStan target exists (`make site-phpstan` is unavailable).

## Risks / Reviewer Focus

- Stage 7 and Stage 8 are financial/P&L changes and should be reviewed carefully before deploy.
- `PLDirtyPeriod` is owned by Ingestion and implements `TenantOwnedInterface`; Finance consumes it only for P&L rebuild orchestration.
- `RebuildPnlPeriodAction` deletes and rebuilds all-source P&L aggregate rows for a company/month. Source-scoped rebuilds are intentionally blocked until Finance source-linking is decided.
- `shop_ref` was not added to `pl_daily_totals` or `pl_monthly_snapshots`.
- `PLDirtyPeriod.shopRef` remains a dirty marker dimension only; the post-deploy decision task asks where source/origin links should live in Finance.
- `PnlCategoryResolver` creates fallback categories (`INGESTION_OTHER_INCOME`, `INGESTION_OTHER_EXPENSE`) when mapped Finance categories do not exist.
- Rebuild requires an existing default or first `ProjectDirection`; it does not create one silently.
- Existing legacy Ozon and P&L flows remain active. This implementation adds the Ingestion path but does not switch production traffic.

## Known Limitations

- Blocks 8 and 9 are not implemented because task specs are absent.
- No UI verification page, shadow comparison dashboard, admin workflow, or support runbook was added.
- No real encrypted credential codec was introduced; existing plaintext credential behavior from earlier blocks remains.
- Full Doctrine DB sync validation was not used for final acceptance because the project has broader schema drift; mapping validation passes.
- Project-wide CS check is red because of pre-existing style drift outside this change set.

## Follow-Up Tasks

- Decide Finance source/origin linking after Ingestion acceptance/deploy: see `docs/tasks/ingestion/FOLLOWUP-finance-source-linking.md`.
- Add and execute `TASK-08` UI pilot/verification when its task file is available.
- Add and execute `TASK-09` shadow/admin when its task file is available.
- Decide whether `RebuildPnlPeriodAction` should be feature-flagged before real production use.
- Decide final fallback P&L category names/codes before enabling live rebuilds.
- Plan legacy Ozon/P&L switch-off only after shadow comparison succeeds.

## Owner Review Checklist

- Review migrations before running them in any shared environment.
- Review Messenger transport/routing changes and worker deployment expectations.
- Review P&L rebuild semantics, category mapping, close-period guard, and dirty-period retry behavior.
- Review Ozon mapper output against representative real reports before shadow mode.
- Review the source-linking decision task before adding any Finance source reference fields.
