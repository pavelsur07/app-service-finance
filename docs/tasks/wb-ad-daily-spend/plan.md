# Plan: Wildberries advertising daily spend

Task base commit: `d29398e967fe56be7bce7dfb0acb43adcde2d582`

## Phase 0 baseline

- Command:
  `docker compose run --rm -T site-php-cli php -d memory_limit=512M bin/phpunit tests/Unit/MarketplaceAds/WildberriesAdRawDataParserTest.php tests/Unit/MarketplaceAds/AdCostDistributorTest.php`
- Result: green, 27 tests / 121 assertions.
- Host PHP is unavailable; Docker PHP 8.3.31 is sufficient.
- Existing unrelated untracked files are excluded from this task.

## Stage 1: Current WB Promotion API boundary

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `d29398e967fe56be7bce7dfb0acb43adcde2d582`

Definition of Done:

- `WildberriesAdClient` uses current `GET /adv/v1/upd` and
  `GET /adv/v3/fullstats`.
- Credentials are resolved by exact company and connection ID.
- `/fullstats` accepts at most 50 campaign IDs per request.
- Authentication, rate-limit, transport, server, invalid JSON, and unexpected
  HTTP responses are classified without logging credentials or bodies.
- JSON decimal numbers needed for money are preserved as decimal strings; no
  `float` enters financial normalization.
- Unit tests cover request shape, tenant/connection scoping, response
  validation, error classes, and exact numeric decoding.
- No live Wildberries requests are made.

Work items:

- 1.1 — add exact WB JSON decoding and response DTOs.
- 1.2 — implement `/upd` and `/fullstats` HTTP methods on the existing client.
- 1.3 — add focused client and decoder tests.

Stage checks:

- Targeted WB API unit tests.
- MarketplaceAds unit tests affected by service construction.
- PHP lint for changed PHP files.

Reviewer focus:

- Current endpoint contract and limits.
- Credential/company isolation.
- Decimal precision and safe error logging.

## Stage 2: Exact campaign-to-SKU allocation and idempotent projection

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: to be recorded immediately before Stage 2

Definition of Done:

- Daily `/upd` rows are aggregated by campaign using exact RUB minor units.
- Nested `/fullstats` day/app/`nmId` values are aggregated once and used only
  as allocation weights.
- Allocated SKU amounts plus an explicit unallocated amount equal actual
  campaign expense exactly.
- Missing/zero analytics produce unallocated expense, not equal distribution.
- Canonical WB raw payload is processed by the existing
  `AdRawDocument -> AdDocument -> AdDocumentLine` path.
- Intentional unallocated expense remains in total reports without creating a
  listing line; unknown real `nmId` also remains in totals and leaves the raw
  document reviewable.
- A nullable raw `source_key` provides idempotency per
  company/connection/day without changing Ozon's multi-document behavior.
- Migration and entity/repository changes are covered by tests and documented.

Work items:

- 2.1 — implement exact allocation and canonical payload builder.
- 2.2 — add raw source-key idempotency and migration.
- 2.3 — preserve intentional/unmapped expense in the existing projection.
- 2.4 — add allocation, parser, action, entity, and repository tests.

Stage checks:

- Targeted allocation and MarketplaceAds projection tests.
- Doctrine mapping/schema validation in the test environment when available.
- Migration SQL review.
- Bounded MarketplaceAds unit suite.

Reviewer focus:

- Financial invariant and rounding residue.
- No double counting of nested WB statistics.
- Re-run idempotency, tenant isolation, and preservation of unallocated spend.

## Stage 3: Daily orchestration, recovery controls, and operations

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: to be recorded immediately before Stage 3

Definition of Done:

- A locked CLI command loads yesterday in `Europe/Moscow` by default and
  accepts an explicit completed date for safe reruns/backfill.
- All active WB seller connections are handled independently; one failure does
  not stop the remaining connections.
- `/fullstats` requests are chunked by 50 and spaced by at least 20 seconds
  between requests without occupying a Messenger worker.
- Logs include company, connection, date, campaign/SKU counts, attributed and
  unallocated totals, status, and duration, but no token or response body.
- Cron schedules one daily load after WB's completed-day window.
- Architecture and operational documentation describe data sources,
  allocation semantics, idempotency, rerun procedure, and reconciliation
  invariant.
- Command/action tests cover default date, explicit date, locking,
  multi-connection isolation, chunk spacing, empty spend, and failure
  continuation.

Work items:

- 3.1 — add the daily load action with chunk pacing and persistence.
- 3.2 — add locked CLI command and cron schedule.
- 3.3 — add orchestration tests and update architecture/operations docs.
- 3.4 — run final relevant checks and prepare the Release Gate.

Stage checks:

- Targeted command/action/client tests.
- Full MarketplaceAds unit suite.
- Relevant integration tests and container/service validation.
- PHP lint and Symfony container lint.

Reviewer focus:

- Schedule/date timezone behavior.
- Rate-limit compliance and no Messenger worker blocking.
- Per-connection fault isolation and rerun safety.
- Logs/metrics sufficient for financial reconciliation.

## Release and Production Gates

- Release Gate: after Stage 3 is green, reviewed, committed, pushed, and the
  Draft PR is updated, owner decides whether to mark the PR ready/merge.
- Production Gate (not authorized by this task): deploy code, apply migration
  outside local/test, activate production cron, run live WB API imports, or
  execute historical backfill.

## Expected change areas

- `site/src/MarketplaceAds/Infrastructure/Api/Wildberries/`
- `site/src/MarketplaceAds/Application/`
- `site/src/MarketplaceAds/Command/`
- `site/src/MarketplaceAds/Entity/`
- `site/src/MarketplaceAds/Repository/`
- `site/tests/Unit/MarketplaceAds/`
- `site/migrations/`
- `site/config/services.yaml`
- `docker/cron/app.cron`
- `ARCHITECTURE.md`
- `docs/tasks/wb-ad-daily-spend/`

## Must not change

- Ozon advertising orchestration and endpoint behavior.
- Financial signs or P&L formulas outside advertising expense ingestion.
- Production data, credentials, workers, deployment, or CI/CD.
- Existing unrelated working-tree files.
