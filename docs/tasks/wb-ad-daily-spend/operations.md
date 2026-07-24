# Operations: Wildberries daily advertising spend

## Schedule

The scheduler container runs:

```text
15 6 * * * app:marketplace-ads:wb-daily-spend
```

Its timezone is `Europe/Moscow`, so the default load starts at 06:15 MSK and
uses the previous completed Moscow calendar day. Current-day/intraday loading
is intentionally disabled.

The command is protected by a global lock. A second overlapping invocation
exits successfully without making API requests.

## Manual rerun

The same idempotent command supports one completed date:

```bash
php bin/console app:marketplace-ads:wb-daily-spend \
  --date=2026-07-20 \
  --company-id=<company-uuid> \
  --connection-id=<connection-uuid> \
  --no-interaction
```

Company and connection filters are optional. The command replaces the raw
payload and projection for the same company/connection/day rather than adding
a duplicate.

Running this against a live WB token, including a historical rerun, is a
Production Gate action and requires explicit owner authorization.

## Data semantics

- `/adv/v1/upd.updSum` is the actual advertising expense.
- `/adv/v3/fullstats.days[].apps[].nms[].sum` is only an `nmId` allocation
  weight.
- Campaign/day/app aggregate `sum` fields are not added to SKU values.
- All calculations use decimal strings and integer RUB kopecks, never `float`.
- For each campaign:

  ```text
  attributed nmId expense + unallocated expense = actual updSum
  ```

- No positive analytics weights means the actual amount is stored under
  `__unallocated__`; no equal allocation is invented.
- A real `nmId` missing from the internal listing catalog remains in campaign
  totals without an `AdDocumentLine`, and its raw document remains `DRAFT`.

## API load and rate limits

- One `/adv/v1/upd` request per connection/day.
- `/adv/v3/fullstats` uses only campaign IDs returned by `/upd`.
- Campaign IDs are split into batches of 50.
- Consecutive fullstats batches are separated by 20 seconds in the cron
  process. No Messenger worker is occupied.

## Monitoring

Each successful connection logs:

- company ID, connection ID, date, and raw document ID;
- raw status;
- campaign and SKU counts;
- `/upd` source, `AdDocument`, `AdDocumentLine`, without-line, intentional
  unallocated, and real unmapped totals;
- real unmapped document count and exact reconciliation status;
- duration.

No token or response body is logged.

The stdout summary keeps `total` as a compatibility alias for `source` and
prints these reconciliation fields:
`persisted_unallocated`, `documents`, `lines`, `without_lines`, `unmapped`,
`unmapped_count`, and `reconciled`. A successful line always has
`reconciled=yes`; mismatches fail the connection before a success line is
printed.

Recommended alerts/checks:

- command exit code is non-zero;
- no source-keyed raw document exists for an active connection by D+1;
- a WB raw document remains `DRAFT` because one or more `nmId` values are
  unmapped;
- unallocated amount or ratio increases materially;
- any persisted reconciliation invariant does not hold:

  ```text
  source = AdDocument
  AdDocument = AdDocumentLine + without-line
  without-line = __unallocated__ + unmapped-nmId
  source __unallocated__ = persisted __unallocated__
  ```

An `__unallocated__` document carrying a listing line intentionally breaks
reconciliation; the projection never creates such a line, so this is a
fail-closed corruption check.

Use `event=wb_ad_spend_reconciliation_failed` as the stable log-query marker
for a persisted reconciliation failure. Stage 2 alert routing uses the normal
application logger separately.

A reconciliation mismatch resets the raw document to `DRAFT`, fails the
affected connection, and makes the command exit non-zero. Intentional
`__unallocated__` remains visible in `without_lines`, but does not require
review; only a real unmapped `nmId` contributes to the unmapped amount/count.
An entry with an empty `campaignName` is skipped by projection and therefore
also causes the source/document invariant to fail instead of being accepted as
a partial success.
The inconsistent projection rows are retained for auditability and remain
queryable until the next idempotent rerun replaces them; the `DRAFT` raw status
is the operational marker that they require recovery.

## Recovery

1. Fix token scope, API availability, or listing mapping.
2. Rerun the affected completed date with the narrowest company/connection
   filters.
3. Confirm the raw status and exact totals in the completion log.
4. Do not run a broad historical backfill until its date range and API budget
   are explicitly approved.

WB may revise recent history. The initial production scope remains D-1 only;
rolling 7-day refresh and month-close refresh should be added as a separately
approved operational policy after observed correction rates justify them.
