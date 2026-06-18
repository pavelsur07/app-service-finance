# Fix Tasks Handoff

## Summary

Implemented tasks from `docs/tasks/fix` in order.

- FIX-01: verified `PLDirtyPeriod` is already owned by `App\Ingestion`.
- FIX-02: added stale pending raw normalization recovery command, cron, and index.
- FIX-03: replaced old ingestion counterparties with global system counterparties.
- FIX-04: added listing attribution to canonical financial transactions and Ozon listing resolver.

## Migrations

- `Version20260619100000`: adds index `idx_ingest_raw_normalization_status_fetched`.
- `Version20260619110000`: creates/seeds `system_counterparties` and drops `ingest_counterparties`.
- `Version20260619130000`: adds `listing_id`, `listing_sku`, and listing lookup indexes.

`Version20260619110000` is guarded: it aborts if `ingest_counterparties` is not empty.

## Public / Operational Changes

- New CLI command: `app:ingestion:normalize-pending`.
- New cron entry every 10 minutes for stale pending raw normalization recovery.
- New public facade: `App\Ingestion\Facade\MarketplaceListingFacade`.
- New service tag: `app.ingestion.listing_resolver`.

## Checks Run

- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK
- `make site-test-migrations` — OK, 3 migrations
- focused integration filter — OK, 12 tests / 69 assertions, 2 existing PHPUnit deprecations
- broader Ingestion/P&L integration filter — OK, 58 tests / 262 assertions, 2 existing PHPUnit runner deprecations
- `make site-test-unit` — OK, 1070 tests / 6442 assertions, 1 existing warning and 1 existing deprecation
- `git diff --check` — OK

## Not Completed / Environment Notes

- Local cron syntax check via `scheduler` service could not be run because the current compose file has no `scheduler` service.
- Migrations were applied only to the test database. No staging/production migration was run.

## Reviewer Focus

- Confirm `ingest_counterparties` is empty before deployment migration.
- Review `FinancialTransaction.counterpartyId` semantics: now points to system counterparties when available.
- Review Ozon listing resolution fallback from `offer_id` / `item_code` to marketplace `sku`.
- Confirm WB listing resolver remaining as warning-only stub is acceptable for this stage.
