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
- attributed, unallocated, and actual totals;
- duration.

No token or response body is logged.

Recommended alerts/checks:

- command exit code is non-zero;
- no source-keyed raw document exists for an active connection by D+1;
- a WB raw document remains `DRAFT` because one or more `nmId` values are
  unmapped;
- unallocated amount or ratio increases materially;
- the logged invariant `attributed + unallocated = actual` does not hold.

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
