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
- Each individual request gets at most three total attempts for HTTP 429/5xx.
- Integer and HTTP-date `Retry-After` values are honored without shortening.
  A value above 120 seconds fails immediately; absent or invalid values use
  bounded 2/4-second delays.
- Authentication and other 4xx responses are not retried.
- `event=wb_ad_spend_request_retry` marks each scheduled retry in the
  `marketplace_ads` channel.
- `event=wb_ad_spend_request_retry_abandoned` records a WB-supplied delay above
  the 120-second bound before the request fails without waiting.

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
`unmapped_count`, `reconciled`, `catalog_refresh_attempted`,
`catalog_refreshed`, and `projection_retries`. `catalog_refreshed=yes` means
the refresh call completed successfully; it may still leave unknown IDs.
A successful line always has
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
for a persisted reconciliation failure.

If one or more connections still return `review_required`, the command emits
exactly one `ERROR` through the normal application logger with
`event=wb_ad_spend_review_required`, the date, total count, and at most ten
company/connection/raw IDs with remaining unmapped totals. This reaches the
configured Sentry/GlitchTip handler; detailed request/recovery logs remain in
the excluded `marketplace_ads` channel.

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

For real unmapped `nmId`, the loader first refreshes the WB listing catalog
once and reprocesses the same persisted raw document once. It does not fetch
advertising endpoints again. A refresh failure leaves the first financial
projection intact in `DRAFT`; unresolved IDs after the retry also remain
`DRAFT` and trigger the aggregated review alert.
`event=wb_ad_spend_catalog_recovery_finished` records a completed refresh and
same-raw reprojection, including the remaining unmapped totals.

Manual recovery:

1. Inspect `wb_ad_spend_review_required`,
   `wb_ad_spend_catalog_refresh_failed`, and the remaining unmapped sample.
2. Fix token scope, catalog API availability, or the listing mapping.
3. Rerun the affected completed date with the narrowest company/connection
   filters.
4. Confirm the raw status, refresh fields, and exact totals in the completion
   log.
5. Do not run a broad historical backfill until its date range and API budget
   are explicitly approved.

WB may revise recent history. The initial production scope remains D-1 only;
rolling 7-day refresh and month-close refresh should be added as a separately
approved operational policy after observed correction rates justify them.
