# Stage 3 Report: Daily orchestration and operations

Stage base commit: `b21a8e99802bffbd8744ba4e03d6ed7c404e6292`
Risk: HIGH-LOCAL
Status: complete

## Delivered

- Added `LoadWbAdSpendDayAction`:
  - fetches one company/connection/day;
  - upserts `AdRawDocument` by deterministic source key;
  - flushes raw before projection for recovery;
  - processes the existing MarketplaceAds projection;
  - returns exact attributed, unallocated, and actual totals.
- Added locked `app:marketplace-ads:wb-daily-spend`:
  - defaults to D-1 in `Europe/Moscow`;
  - rejects today, future dates, malformed dates, and invalid UUID filters;
  - supports idempotent completed-date reruns;
  - continues after an individual connection failure;
  - returns a non-zero exit status when any connection fails;
  - reports `DRAFT` rows as `review_required`.
- Added 06:15 MSK daily cron execution.
- Added operations documentation for source semantics, rate limits, reruns,
  monitoring, recovery, and Production Gate boundaries.
- Updated WB client logs to use the MarketplaceAds channel.
- Raw payload reruns now clear stale processing errors and refresh `loadedAt`.

## Checks

- MarketplaceAds unit suite: green, 332 tests / 2105 assertions.
- Full MarketplaceAds integration suite:
  green, 169 tests / 681 assertions.
- Targeted command/action/entity suite before the final empty-day addition:
  green, 22 tests / 106 assertions.
- Symfony container lint: green.
- Doctrine ORM mapping validation: green.
- Command registration and `--help`: green.
- PHP syntax checks for the new action and command: green.
- `git diff --check`: green.

## Internal review

- Moved operational-summary parsing after projection so raw is persisted before
  any parser/projection failure.
- Added a regression test proving failed projection leaves recoverable DRAFT
  raw data.
- Added a processed empty-day coverage test with zero totals.
- Verified lock, Moscow midnight boundary, completed-date validation,
  connection filtering, partial failure continuation, and error exit code.
- No unresolved BLOCKER or IMPORTANT findings.

## External Claude Code review

- Result: `REVIEW_GREEN`.
- MINOR accepted with technical reason: payload is parsed a second time only to
  build an operational summary. Avoiding it would broaden the shared
  `ProcessAdRawDocumentAction` return contract; the bounded daily cost is the
  safer tradeoff.
- FOLLOW-UP observations:
  - `attributedTotal` means allocated to `nmId`, while mapping gaps are signaled
    by `DRAFT`/`review_required`;
  - `skuCount` is campaign-SKU line count, not distinct nmId cardinality;
  - add date validation to the Action if a future caller bypasses the command.

## Compatibility and operations

- Existing Ozon command, messages, and projections are unchanged.
- No Messenger worker is used for WB pacing.
- No live WB request was made.
- Cron was changed only in the branch; it has not been deployed or activated.
- No production migration, data change, deployment, release, or backfill was
  performed.
