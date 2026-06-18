# Fix Tasks Implementation Plan

## Summary

Implement tasks from `docs/tasks/fix` in order:

1. Verify `PLDirtyPeriod` ownership in `App\Ingestion`.
2. Add cron-backed recovery for stale pending raw normalizations.
3. Replace tenant-scoped ingestion counterparties with global system counterparties.
4. Add marketplace listing attribution to canonical ingestion financial transactions.

## Stages

### Stage 1: FIX-01 PLDirtyPeriod verification

Risk: LOW.

- Verify `PLDirtyPeriod`, enums, and repository live in `App\Ingestion`.
- Verify `PLDirtyPeriod` implements `TenantOwnedInterface`.
- Verify no `App\Finance\*PLDirtyPeriod` imports remain.

### Stage 2: FIX-02 stale pending normalization recovery

Risk: HIGH because it adds a migration and cron entry.

- Add `IngestRawRecordRepository::findStuckPending()`.
- Add `app:ingestion:normalize-pending`.
- Add cron entry running every 10 minutes.
- Add index on `(normalization_status, fetched_at)`.

### Stage 3: FIX-03 system counterparties

Risk: HIGH because it removes `ingest_counterparties`.

- Add global `SystemCounterparty` entity/repository/resolver.
- Seed Ozon and Wildberries deterministic UUID rows.
- Replace old ingestion counterparty upsert in normalization.
- Drop `ingest_counterparties` only if empty.

### Stage 4: FIX-04 listing resolver

Risk: HIGH because it changes canonical transaction schema and touches Marketplace repositories.

- Add nullable `listingId` and `listingSku` to `FinancialTransaction`.
- Add listing resolver registry and Ozon/WB implementations.
- Add `MarketplaceListingFacade` boundary.
- Add worker-safe Marketplace listing repository methods.
- Add schema indexes for listing attribution.

## Checks

- `lint:container --env=test`
- `doctrine:schema:validate --skip-sync --env=test`
- test migrations on `APP_ENV=test`
- focused Ingestion/P&L integration tests
- `make site-test-unit`
- `git diff --check`

## Assumptions

- Production/staging migrations are not run autonomously.
- `ingest_counterparties` must be empty before the drop migration is applied.
- `docs/tasks/s3/` and `.mimocode/` are out of scope.
