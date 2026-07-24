# Final handoff: Wildberries daily advertising spend

Task base commit: `d29398e967fe56be7bce7dfb0acb43adcde2d582`
Branch: `codex/wb-ad-daily-spend`
Draft PR: <https://github.com/pavelsur07/app-service-finance/pull/2232>

## Summary

- Stage 1 added the current read-only Wildberries Promotion API boundary for
  `/adv/v1/upd` and `/adv/v3/fullstats`, exact JSON-number decoding, request
  limits, pacing, and safe error classification.
- Stage 2 added exact campaign-to-`nmId` allocation, explicit unallocated
  expense, raw-to-report projection, and idempotency by
  company/marketplace/source key.
- Stage 3 added the locked daily D-1 loader, per-connection fault isolation,
  operational summaries, cron schedule, and recovery documentation.

## Work items completed

- 1.1–1.3 — WB response decoding, API client, and unit coverage.
- 2.1–2.4 — financial allocation, source-key persistence, projection behavior,
  migration, and integration coverage.
- 3.1–3.4 — day-load action, CLI/cron orchestration, tests, documentation, and
  Release Gate preparation.

## Financial and public contracts

- `GET /adv/v1/upd` field `updSum` is the actual expense source.
- `GET /adv/v3/fullstats` nested `nms[].sum` values are allocation weights
  only.
- The invariant is exact in RUB kopecks:
  `attributed nmId expense + unallocated expense = actual updSum`.
- Missing or zero analytics never cause invented equal allocation.
- New CLI contract:
  `app:marketplace-ads:wb-daily-spend [--date=YYYY-MM-DD]
  [--company-id=UUID] [--connection-id=UUID]`.
- The default date is the previous completed `Europe/Moscow` calendar day.

## Migration

- `site/migrations/Version20260724100000.php`
  - up: adds nullable `source_key` and a unique
    `(company_id, marketplace, source_key)` constraint;
  - down: removes the constraint and column;
  - no data deletion or transformation;
  - nullable keys preserve existing Ozon multi-document behavior.
- The migration was executed only in the isolated test database. No staging or
  production migration was run.

## Checks

- MarketplaceAds unit: 332 tests / 2105 assertions, green.
- MarketplaceAds integration: 169 tests / 681 assertions, green.
- Symfony container lint: green.
- Doctrine mapping validation with `--skip-sync`: green.
- Command registration and help: green.
- PHP syntax and task-scoped PHP CS Fixer (26 files): green.
- `git diff --check`: green.
- The full test-schema sync remains red because of pre-existing repository
  drift at `bot_links.updated_at`; the task migration itself executed
  successfully in the isolated test database.
- The repository-wide CS check remains red on 591 pre-existing files; all PHP
  files changed by this task pass the same formatter configuration.

## Reviews

- Stage 1 internal review: green; external review: `REVIEW_GREEN` after one
  confirmed performance fix.
- Stage 2 internal review: green; external review: `REVIEW_GREEN` after safe
  MINOR fixes.
- Stage 3 internal review: green; external review: `REVIEW_GREEN`.
- Final complete-task internal review: green.
- Final external review of
  `d29398e967fe56be7bce7dfb0acb43adcde2d582...HEAD` plus task-owned working
  changes: `REVIEW_GREEN`; no BLOCKER or IMPORTANT findings.
- Accepted MINOR observations:
  - malformed non-authoritative analytics currently leave the raw document
    recoverable in `DRAFT` instead of silently falling back to unallocated;
  - a 429 fails the affected connection and relies on the visible non-zero
    command result plus an idempotent rerun rather than an in-process retry;
  - zero-spend SKU analytics rows are omitted because they do not contribute
    to financial allocation.
- Rejected FOLLOW-UP: `/adv/v3/fullstats` is intentionally `GET` with query
  parameters `ids`, `beginDate`, and `endDate`. The current official WB
  Promotion API documentation confirms this contract, the 50-ID limit, and
  the 20-second request interval:
  <https://dev.wildberries.ru/en/docs/openapi/promotion?locale=ru%2F>.
- Accepted FOLLOW-UP: before reprocessing any pre-task WB advertising raw
  document, confirm whether legacy payloads exist because the new parser
  requires the versioned `wb-ad-daily-spend-v1` schema.

## Compatibility and operational risk

- Ozon advertising behavior and public endpoints are unchanged.
- The loader is read-only toward Wildberries and never changes campaigns or
  bids.
- A real but unmapped `nmId` remains visible through `DRAFT` /
  `review_required`; intentional `__unallocated__` expense is retained in
  totals without a listing line.
- The cron change is only in this branch and has not been deployed or
  activated.
- Live WB import, production migration, deployment, backfill, and production
  cron activation remain separate Production Gate actions.

## Known limitations and follow-ups

- `attributedTotal` means allocated to WB `nmId`; an internal listing-mapping
  gap is reported separately through raw status.
- `skuCount` counts campaign-SKU projection rows, not distinct `nmId` values.
- Rolling 7-day and month-close refresh policies are intentionally out of
  scope until observed WB correction rates justify them.
- If another caller is added outside the CLI, completed-date validation should
  also be enforced at the application-action boundary.

## Owner review focus

- Confirm the financial invariant and the decision to retain missing analytics
  as explicit unallocated expense.
- Confirm the D-1-only 06:15 MSK operating policy and manual single-date rerun
  contract.
- Confirm that production migration, deployment, cron activation, and live
  import will be approved separately after merge planning.

## Expected owner response

Recommended Release Gate response:

`Разрешаю перевести Draft PR #2232 в Ready for review. Production Gate не разрешаю.`
