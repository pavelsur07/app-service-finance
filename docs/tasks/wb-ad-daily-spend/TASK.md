# Wildberries advertising daily spend

## Owner brief

Implement a daily import of Wildberries advertising expenses with SKU (`nmId`)
attribution.

## Business rules

- The financial source of truth is `GET /adv/v1/upd` and its `updSum`.
- `GET /adv/v3/fullstats` is analytics only; its per-`nmId` `sum` values are
  weights for allocating the campaign's actual `updSum`.
- The import runs for a completed calendar day, normally yesterday in
  `Europe/Moscow`.
- Allocation must use exact decimal arithmetic. Do not use `float`.
- For every campaign/day:
  `attributed SKU spend + unallocated spend = actual updSum`.
- If analytics are absent or have zero weight, keep the whole actual amount as
  unallocated. Never invent an equal allocation.
- If an `nmId` is not mapped to an internal listing, keep its amount in the
  campaign total and expose the missing attribution through the existing raw
  document processing state.
- Re-running the same company/connection/day replaces the previous projection
  idempotently.

## Scope

- Current Wildberries Promotion API endpoints only.
- Daily command, one cron launch per day.
- Refresh/backfill can be run explicitly for a selected date through the same
  command.
- Existing `MarketplaceAds` raw/document/line projection and reports.

## Out of scope

- Intraday/current-day loading.
- Campaign management or bid changes.
- UI changes.
- Automatic historical backfill beyond the requested day.
- Production migration, deployment, cron activation, or live API calls.
