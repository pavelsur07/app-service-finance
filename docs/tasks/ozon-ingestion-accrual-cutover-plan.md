# Ozon Ingestion accrual cutover plan

## Goal

Replace only the Ingestion canonical Ozon expense records with data from the new Ozon accrual endpoints. The legacy Marketplace module remains untouched.

## Scope

- Disable legacy Ingestion Ozon resources:
  - `ozon_seller_daily_report`
  - `ozon_seller_realization`
- Normalize `ozon_finance_accrual_by_day` into `ingest_financial_transactions`.
- Use the existing backfill window mechanism for month-to-date refresh.
- Do not create new tables or new update modes.
- Do not normalize sale/refund from accrual data in this stage.

## Implementation stages

1. Disable legacy Ozon Ingestion entry points in commands and connector dispatch.
2. Add accrual by-day mapper for expense components already verified by parity:
   - `commission`
   - `fee`
   - `other`
3. Reuse current backfill flow for month-to-date load:
   - on 2026-06-21, `--days-back=20` means `2026-06-01 -> 2026-06-20`.
4. For already loaded `done` raw records, use an operational one-off reset to `pending` only for scoped `ozon_finance_accrual_by_day` raw records if re-normalization is needed.
5. After production parity is confirmed, remove remaining legacy Ingestion code.

## Operational notes

The existing raw store deduplicates unchanged payloads by company/source/resource/external id/hash. The existing canonical upsert deduplicates transactions by company/source/external id/type.

If old Ingestion canonical rows exist for the same company/month, replacement must be scoped to Ingestion Ozon records only. Marketplace legacy tables and reports are outside this task.

## Validation

- Unit tests for accrual by-day mapper.
- Integration flow test for accrual raw to canonical transactions.
- Command tests for backfill/incremental resource selection.
- `make site-test-unit`.
- `make site-cs-check`.
