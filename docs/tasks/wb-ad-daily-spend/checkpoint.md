# Checkpoint

Current phase: Implementation
Current Stage: Stage 2
Stage base commit: to be recorded after the Stage 1 commit

## Completed

- Read repository instructions and relevant MarketplaceAds architecture and
  patterns.
- Inspected existing WB/Ozon clients, raw parsers, projections, connection
  queries, cron, Messenger, Money, and migration conventions.
- Verified current WB Promotion API contracts from official documentation.
- Selected the existing MarketplaceAds projection and a cron-driven daily
  loader; no new queue transport or parallel job model is needed.
- Recorded a green focused baseline: 27 tests / 121 assertions.
- Completed Stage 1: current WB API client, exact JSON number decoding, error
  classification, batching, and tests.
- Full MarketplaceAds unit suite is green: 316 tests / 1956 assertions.
- Stage 1 internal review is green.
- Stage 1 external review is `REVIEW_GREEN`.

## Current Definition of Done

See Stage 2 in `plan.md`.

## Notes

- Host PHP is unavailable; use the Docker PHP CLI service.
- No live API or production operation is authorized.
- Unrelated untracked files present before this task remain untouched.
