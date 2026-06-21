# Ozon Ingestion accrual cutover plan

## Goal

Replace only the Ingestion canonical Ozon expense records with data from the new Ozon accrual endpoints. The legacy Marketplace module remains untouched.

## Scope

- Disable legacy Ingestion Ozon resources:
  - `ozon_seller_daily_report`
  - `ozon_seller_realization`
- Normalize `ozon_finance_accrual_by_day` into `ingest_financial_transactions`.
  - `sale`
  - `refund`
  - `commission`
  - `fee`
  - `other`
- Use the existing backfill window mechanism for month-to-date refresh.
- Do not create new tables or new update modes.
- Use accrual by-day as the single Ozon Ingestion canonical source.

## Implementation stages

1. Disable legacy Ozon Ingestion entry points in commands and connector dispatch.
2. Add accrual by-day mapper for expense components already verified by parity:
   - `commission`
   - `fee`
   - `other`
3. Reuse current backfill flow for month-to-date load:
   - on 2026-06-21, `--days-back=20` means `2026-06-01 -> 2026-06-20`.
4. For already loaded `done` raw records, use an operational one-off reset to `pending` only for scoped `ozon_finance_accrual_by_day` raw records if re-normalization is needed.
5. Enable sale/refund normalization from accrual by-day after production preview confirms the canonical replacement strategy.
6. Re-normalize scoped month-to-date accrual raw records after enabling sale/refund because already `done` raw records are skipped by normal workers.
7. After production parity is confirmed, remove remaining legacy Ingestion code.

## Operational notes

The existing raw store deduplicates unchanged payloads by company/source/resource/external id/hash. The existing canonical upsert deduplicates transactions by company/source/external id/type.

If old Ingestion canonical rows exist for the same company/month, replacement must be scoped to Ingestion Ozon records only. Marketplace legacy tables and reports are outside this task.

After deploying sale/refund canonical mapping, already normalized accrual raw records must be re-normalized because `done` raw records are skipped by normal workers. For the verified production company/month:

```bash
docker exec -it symfony-postgres psql -U app -d app -P pager=off -c "
  UPDATE ingest_raw_records r
  SET normalization_status = 'pending',
      updated_at = NOW()
  FROM ingest_sync_jobs j
  WHERE r.company_id = '19621cff-b028-45d9-9193-11f47ad9a8b2'
    AND r.source = 'ozon'
    AND r.resource_type = 'ozon_finance_accrual_by_day'
    AND r.normalization_status = 'done'
    AND j.id::text = r.sync_job_id
    AND j.company_id = r.company_id
    AND j.window_from <= '2026-06-20'
    AND j.window_to >= '2026-06-01'
  RETURNING r.id, r.external_id, r.normalization_status;
"

docker exec -it scheduler php /app/bin/console app:ingestion:normalize-pending \
  --limit=10 \
  --threshold-minutes=1
```

Expected result for `2026-06-01..2026-06-20`: accrual canonical rows increase from `4854` to `5354` by adding the `500` sale/refund rows that were confirmed by preview.

## Validation

- Unit tests for accrual by-day mapper.
- Integration flow test for accrual raw to canonical transactions.
- Command tests for backfill/incremental resource selection.
- `make site-test-unit`.
- `make site-cs-check`.
