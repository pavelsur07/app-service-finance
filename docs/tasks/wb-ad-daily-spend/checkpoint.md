# Checkpoint

Current phase: Final Release Gate
Status: done — owner decision required
Current Stage: Stage 3 complete
Stage base commit: `b21a8e99802bffbd8744ba4e03d6ed7c404e6292`
Owner gate: yes

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
- Final MarketplaceAds unit suite is green: 332 tests / 2105 assertions.
- Final MarketplaceAds integration suite is green:
  169 tests / 681 assertions.
- Task-scoped PHP CS Fixer is green: 26 files / 0 findings.
- Final internal review of the complete task diff is green.
- Final external review of the complete task diff is `REVIEW_GREEN`;
  no BLOCKER or IMPORTANT findings remain.
- Current official WB documentation re-confirms that `/adv/v3/fullstats` is a
  GET endpoint with `ids`, `beginDate`, and `endDate` query parameters.

## Current Definition of Done

See Stage 3 in `plan.md`.

## Notes

- Host PHP is unavailable; use the Docker PHP CLI service.
- No live API or production operation is authorized.
- Unrelated untracked files present before this task remain untouched.
- Full `make site-test` remains blocked by pre-existing test-schema drift at
  `bot_links.updated_at`; the task migration passed in the isolated test DB.
- Repository-wide `make site-cs-check` remains red on 591 pre-existing files;
  task-scoped style is green.

## Exact next action

- Commit and push the final task-scoped formatter and handoff updates.
- Update Draft PR #2232 and record its CI status.
- STOP before changing Draft status, merge, release, deployment, production
  migration, production cron activation, or a live WB import.
- Expected owner response:
  `Разрешаю перевести Draft PR #2232 в Ready for review. Production Gate не разрешаю.`
