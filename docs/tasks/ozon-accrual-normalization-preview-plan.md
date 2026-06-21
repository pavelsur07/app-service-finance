# Ozon accrual normalization preview plan

## Goal

Add a safe dry-run view that shows which canonical financial transactions could be produced from stored `ozon_finance_accrual_by_day` raw records.

## Scope

- Read only stored raw records and existing canonical transactions.
- Do not call Ozon API.
- Do not write to `ingest_financial_transactions`.
- Do not change the shadow mapper used by the normal ingestion pipeline.
- Do not add tables, columns, migrations, queues, or jobs.

## Preview mapping

- `POSTING` commission fields become `commission` transactions.
- `POSTING` delivery service `type_id` rows become `fee` transactions.
- `ITEM` fee `type_id` rows become `fee` transactions.
- `NON_ITEM` fee `type_id` rows become `other` transactions, matching the current legacy aggregate shape.
- `CONTAINER` fee `type_id` rows become `fee` transactions.
- Sale/refund amount fields and non-transaction amount fields such as `bonus`, `coinvestment`, `sale_amount`, and `seller_price` are intentionally omitted from the preview to avoid known duplicate risk.

## Duplicate checks

- Exact candidate: existing canonical transaction with the preview source key and transaction type.
- Potential legacy candidate: existing canonical transaction on the same date with the same type, direction, and amount.
- Aggregate comparison: preview totals vs canonical totals by date, type, and direction.
- Raw rows are filtered by the requested `--from/--to` window before preview mapping, because stored raw records can cover a wider ingestion chunk than the CLI window.

## Checks

- Focused unit tests for the preview mapper.
- Symfony command registration check via `--help`.
- PHP CS Fixer dry-run for touched files.
- Full `make site-test-unit`.
