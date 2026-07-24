# Plan: WB daily advertising spend hardening

Owner brief: add an alert for unresolved `review_required`, refresh the WB
listing catalog and retry projection once for unknown `nmId`, reconcile the
WB `/upd` total with persisted advertising documents, add bounded Promotion
API retries, and remove the PHP CLI opcache startup warning.

Task base commit: `44b28304d27c5e1025e8c29230d75b928a36681f`

## Production evidence

The idempotent rerun for `2026-07-23` and company
`3f4d87cc-a967-43a7-9b5e-8a9113c0a910` loaded the same raw document and
financial total:

- raw document: `019f92e6-2ad6-7112-85bd-e88c630072a2`;
- `/upd`-derived actual total: `1552.00`;
- status: `DRAFT`, `review_required=1`, `failed=0`;
- unmapped `nmId`: `385530433` and `1217989939`, campaign `35814101`;
- the repeated advertising import did not refresh the listing catalog, so the
  two mapping warnings remained unchanged;
- PHP CLI emitted an opcache load warning before the command started.

The current projection intentionally retains the complete `1552.00` in
`AdDocument`; only listing-level analytics are incomplete for documents
without `AdDocumentLine`.

## Phase 0 baseline

Command:

```bash
docker compose run --rm -T site-php-cli \
  php -d memory_limit=512M bin/phpunit \
  tests/Unit/MarketplaceAds/WildberriesAdClientTest.php \
  tests/Unit/MarketplaceAds/Application/LoadWbAdSpendDayActionTest.php \
  tests/Unit/MarketplaceAds/Command/WbAdDailySpendCommandTest.php \
  tests/Unit/Marketplace/Application/RefreshWbListingCatalogActionTest.php
```

Result: green, 22 tests / 134 assertions on PHP 8.3.31.

The working tree contains unrelated owner changes. They are outside this task
and must not be edited, staged, committed, or removed.

## Stage 1: Persisted financial reconciliation

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `44b28304d27c5e1025e8c29230d75b928a36681f`

Definition of Done:

- After projection, the loader reads persisted aggregates scoped by
  `companyId + rawDocumentId`.
- The operational result exposes:
  - `/upd` source total;
  - `AdDocument.totalCost` total;
  - `AdDocumentLine.cost` total;
  - total expense in documents without any line;
  - intentional `__unallocated__` expense;
  - real unmapped-`nmId` expense and count;
  - exact `reconciled=yes|no`.
- Exact decimal/Money arithmetic is used; no `float`.
- The following invariants are checked:

  ```text
  source total = AdDocument total
  AdDocument total = AdDocumentLine total + documents-without-lines total
  documents-without-lines total = __unallocated__ total + unmapped-nmId total
  source __unallocated__ total = persisted __unallocated__ total
  ```

- Any reconciliation invariant violation is a terminal
  connection failure: the raw payload remains available, the command reports
  the mismatch, logs one actionable error, and exits non-zero.
- Intentional `__unallocated__` is visible but does not cause
  `review_required`.
- No database migration or public HTTP API change is introduced.

Work items:

- 1.1 — add a tenant-scoped DBAL reconciliation query and a compact result DTO.
- 1.2 — extend `WbAdSpendLoadResult` and the command output/log context with
  persisted totals, missing-line amounts/counts, and reconciliation status.
- 1.3 — enforce the exact invariants after projection and add regression tests
  for mapped, intentionally unallocated, unmapped, zero, negative-correction,
  and mismatch cases.
- 1.4 — document the persisted reconciliation contract in
  `ARCHITECTURE.md` and the operations guide.

Stage checks:

- Focused unit tests for the loader, command, and result formatting.
- Integration tests for the reconciliation query against real
  `AdDocument`/`AdDocumentLine` rows.
- Bounded MarketplaceAds unit and integration suites.
- PHP lint, task-scoped CS check, Symfony container lint, and
  `git diff --check`.

Reviewer focus:

- Company/raw-document isolation.
- Signed Money arithmetic and exact equality.
- No double counting when one `AdDocument` has multiple lines.
- Clear distinction between intentional unallocated and real unmapped spend.

## Stage 2: Self-recovery, bounded API retry, and actionable alert

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `26cb5ff0b3faa36340bc6b259accfd7fcf0f9e06`

Definition of Done:

- A real unmapped `nmId` is detected from the persisted reconciliation result,
  not from log text or generic `DRAFT` status.
- The loader calls the existing
  `MarketplaceFacade::refreshWbListingCatalog(companyId, connectionId)` once,
  then reprocesses the same persisted raw document once.
- The recovery path does not fetch `/upd` or `/fullstats` a second time.
- If refresh resolves all mappings, the final raw status becomes `PROCESSED`,
  unmapped spend becomes zero, and no review alert is emitted.
- If refresh fails or an `nmId` remains unknown, the original financial
  projection remains recoverable in `DRAFT`, the final result remains
  `review_required`, and the refresh outcome is present in structured logs.
- `WildberriesAdClient` retries each individual Promotion API request at most
  three total attempts for HTTP 429 and 5xx:
  - `Retry-After` integer seconds and valid HTTP-date are supported;
  - a retry delay supplied by WB is never shortened;
  - if it exceeds the configured maximum wait, the request fails instead of
    blocking the cron process for an unbounded period;
  - absent/invalid `Retry-After` uses a short bounded backoff;
  - retry attempts are `WARNING`, and only exhaustion becomes a connection
    failure;
  - authentication and other 4xx responses are never retried.
- After all connections finish, `review_required > 0` emits exactly one
  aggregated `ERROR` through the normal app logger with stable marker
  `wb_ad_spend_review_required`, a count, date, and a bounded sample of
  company/connection/raw IDs and unmapped totals.
- The dedicated `marketplace_ads` channel remains the detailed INFO/WARNING
  stream; it is not used for the alert because production excludes that
  channel from Sentry/GlitchTip.
- `DRAFT` alone does not change the existing command exit-code contract;
  actual load or reconciliation failures still return non-zero.

Work items:

- 2.1 — add one-shot catalog refresh and projection retry to
  `LoadWbAdSpendDayAction`, driven only by real unmapped documents.
- 2.2 — preserve the first projection when catalog refresh fails and expose
  `catalog_refreshed`, retry count, and remaining unmapped totals.
- 2.3 — implement the bounded per-request 429/5xx retry loop in
  `WildberriesAdClient` using `ClockInterface`.
- 2.4 — add one aggregated GlitchTip/Sentry alert after unresolved
  `review_required` results.
- 2.5 — add regression tests for successful repair, unresolved IDs, refresh
  failure, no-refresh cases, retry exhaustion, `Retry-After`, no retry on
  auth/other 4xx, and multi-connection alert aggregation.

Stage checks:

- Focused client/action/command unit tests with `MockClock`.
- Marketplace catalog refresh unit and integration tests.
- Bounded MarketplaceAds unit and integration suites.
- Symfony container lint, PHP lint, task-scoped CS check, and
  `git diff --check`.

Reviewer focus:

- Exactly one catalog refresh and one projection retry.
- No duplicate external advertising requests or persisted rows.
- Retry delay/attempt bounds and cron runtime.
- Alert reaches the production Sentry handler without alert storms or secrets.
- A catalog-recovery failure never discards already loaded financial actuals.

## Stage 3: Production PHP CLI opcache cleanup and release readiness

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `597c5514bba6832ab81d4cea4de9fbe62c907dae`

Definition of Done:

- The final production PHP CLI image no longer attempts to load the broken
  Alpine/musl opcache extension.
- The existing development-image fix pattern is reused; PHP-FPM opcache
  configuration and behavior are unchanged.
- `php -v`, `php -m`, and a Symfony console smoke command in the built
  production CLI image produce no opcache startup warning.
- Worker and scheduler entrypoints still run as before.
- Operations documentation includes the new result fields, alert behavior,
  bounded retries, catalog recovery, and production acceptance commands.
- Full task checks and both reviews are green; one Draft PR contains all three
  stages.

Work items:

- 3.1 — disable the opcache dynamic-load entry only in the production CLI
  runtime stage, following the existing development Dockerfile pattern.
- 3.2 — add/build smoke validation for the production CLI image.
- 3.3 — update operations/handoff documentation and run the final verification
  cascade.
- 3.4 — complete internal review, external read-only Claude review to
  `REVIEW_GREEN`, commit/push task-owned changes, and create/update one Draft
  PR.

Stage checks:

- Build the production `site-php-cli` image.
- Run `php -v`, `php -m`, and
  `php bin/console app:marketplace-ads:wb-daily-spend --help` in that image,
  asserting no opcache warning on stderr.
- Full relevant MarketplaceAds unit/integration tests.
- `make site-test` and `make site-cs-check` when supported; document only
  proven unrelated pre-existing failures.
- Symfony container lint and `git diff --check`.

Reviewer focus:

- CLI-only scope; no regression to production FPM.
- Reproducible image build and absence of hidden stderr warnings.
- No changes to cron schedule, financial formulas, Ozon Ads, or unrelated
  owner files.

## Release Gate

After Stage 3 is green, reviewed, committed, pushed, and the Draft PR and CI
status are updated, the owner decides whether to mark the PR ready and merge
it. Merge/release is HIGH-EXTERNAL and is not authorized by this planning
request.

## Production Gate

Each mutating production action requires explicit approval immediately before
execution:

1. Merge the approved PR and allow the automatic production deployment/image
   rollout.
2. No Doctrine migration is expected for this task.
3. With a separate explicit production-check request, Codex verifies
   container health through `codex-docker-ps`; the owner/DevOps operator
   checks the opcache warning on the host as documented in `operations.md`.
4. With separate approval, rerun only `2026-07-23` for company
   `3f4d87cc-a967-43a7-9b5e-8a9113c0a910`.
5. Accept only if the output shows exact reconciliation. The preferred result
   is `status=processed`, `review_required=0`, and zero real unmapped spend.
   If the two `nmId` values remain missing after catalog refresh, keep the raw
   document in `DRAFT`, confirm Content API/token scope, and do not invent a
   manual listing mapping.

## Expected change areas

- `site/src/MarketplaceAds/Application/`
- `site/src/MarketplaceAds/Application/DTO/`
- `site/src/MarketplaceAds/Command/WbAdDailySpendCommand.php`
- `site/src/MarketplaceAds/Infrastructure/Api/Wildberries/WildberriesAdClient.php`
- `site/src/MarketplaceAds/Infrastructure/Query/`
- `site/tests/Unit/MarketplaceAds/`
- `site/tests/Integration/MarketplaceAds/`
- `site/docker/production/php-cli/Dockerfile`
- `ARCHITECTURE.md`
- `docs/tasks/wb-ad-daily-spend/operations.md`
- `docs/tasks/wb-ad-daily-spend-hardening/`

## Must not change

- `/upd` financial-source semantics, allocation formulas, signs, or rounding.
- Ozon advertising behavior.
- The 06:15 MSK D-1 cron schedule.
- Marketplace listing mappings by guess or synthetic fallback.
- Production data, credentials, workers, deployment, or live API state during
  local implementation.
- Existing unrelated working-tree files.
