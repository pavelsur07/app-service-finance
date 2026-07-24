# Checkpoint

Current phase: Final Release Gate
Current Stage: Stage 3 complete
Stage base commit: `b21a8e99802bffbd8744ba4e03d6ed7c404e6292`

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
- Stage 2 work items implemented:
  - exact `/upd` campaign aggregation and `nms`-only allocation;
  - deterministic rounding residue and explicit unallocated entries;
  - preservation of unknown WB `nmId` in totals without listing lines;
  - company/marketplace/source-key idempotency and local migration;
  - current `/fullstats` query names corrected to `beginDate` / `endDate`.
- Stage 2 checks completed:
  - MarketplaceAds unit: 321 tests / 2025 assertions, green;
  - targeted repository/projection integration: 20 tests / 61 assertions, green;
  - full MarketplaceAds integration: 169 tests / 681 assertions, green;
  - container lint and Doctrine mapping validation: green.
- Full test-schema sync remains red from pre-existing repository drift; the
  current migration was executed directly in the isolated test DB and its
  integration tests are green.
- Stage 2 internal review is green.
- Stage 2 external review is `REVIEW_GREEN` after the safe MINOR fix cycle.
- Stage 3 work items implemented:
  - idempotent day-load Action that persists raw before projection;
  - locked CLI with Moscow D-1 default, completed-date rerun and UUID filters;
  - per-connection failure isolation and financial completion summaries;
  - 06:15 MSK daily cron and operations runbook;
  - raw reruns clear stale processing errors and refresh loaded-at time.
- Stage 3 checks completed:
  - MarketplaceAds unit: 332 tests / 2105 assertions, green;
  - full MarketplaceAds integration: 169 tests / 681 assertions, green;
  - command registration/help, container lint and Doctrine mapping: green.
- Stage 3 internal review is green.
- Stage 3 external review is `REVIEW_GREEN`.

## Current Definition of Done

See Stage 3 in `plan.md`.

## Notes

- Host PHP is unavailable; use the Docker PHP CLI service.
- No live API or production operation is authorized.
- Unrelated untracked files present before this task remain untouched.
