# Ozon accrual by-day compare plan

## Goal

Build a read-only audit for already loaded `ozon_finance_accrual_by_day` raw data, so we can decide how to normalize new Ozon accrual data without duplicating existing canonical transactions.

## Scope

- Use stored `ingest_raw_records` only.
- Extend `app:ingestion:ozon-accrual:compare`.
- Aggregate Ozon by-day raw rows by:
  - day and `accrued_category`;
  - delivery service `type_id`;
  - commission money fields;
  - item fee `type_id`;
  - non-item fee `type_id`;
  - container fee `type_id`.
- Compare raw daily net amount with current Ozon `ingest_financial_transactions` daily net.

## Out of scope

- No writes to `ingest_financial_transactions`.
- No new database tables or columns.
- No schema migrations.
- No changes to production jobs or scheduled ingestion.
- No legacy marketplace cost formula changes.
- No live Ozon API calls.

## Execution stages

1. Add raw aggregate DTO and service for `ozon_finance_accrual_by_day`.
2. Extend compare command with by-day aggregate sections and raw-vs-canonical daily comparison.
3. Add focused unit tests for nested accrual structures.
4. Run syntax, style, unit checks, and self-review.

## Testing

- Focused unit test for raw by-day aggregation.
- Symfony command container/autowire check via `--help`.
- PHP CS Fixer dry-run for touched PHP files.
- `make site-test-unit`.
