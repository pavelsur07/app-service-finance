# TASK-UI-CLIENT-BACKEND — Final Handoff

## Summary

Implemented backend support for the client Ingestion verification UI:

- Stage 1: DTOs, formatter, period validation, API exceptions.
- Stage 2: DBAL read queries and `IngestionFacade` methods.
- Stage 3: 4 public REST endpoints, OpenAPI annotations, request logging, unified exception listener.
- Stage 4: tenant-leak functional coverage for all endpoints.
- Stage 5: generated `schema.d.ts`, architecture documentation, API type checks.

## Files changed

- `site/src/Ingestion/Application/DTO/*` — verification response DTOs.
- `site/src/Ingestion/Application/Service/IssueDescriptionFormatter.php` — human issue descriptions.
- `site/src/Ingestion/Application/Service/VerificationPeriodValidator.php` — period/date validation.
- `site/src/Ingestion/Infrastructure/Query/*Query.php` — DBAL read models.
- `site/src/Ingestion/Controller/Api/Verification/*Controller.php` — new API endpoints.
- `site/src/Ingestion/Infrastructure/Http/IngestionExceptionListener.php` — unified 422 errors.
- `site/src/Ingestion/Facade/IngestionFacade.php` — 4 verification methods.
- `site/src/Marketplace/Repository/OzonTransactionTotalsCheckRepository.php` — latest company-period checksum lookup.
- `site/assets/api/schema.d.ts` — regenerated API types.
- `ARCHITECTURE.md` — Ingestion verification API documentation.
- `site/tests/.../Ingestion/...` — unit, integration, functional, tenant-leak tests.
- `docs/tasks/ingestion/fix/plan.md`, `docs/tasks/ingestion/fix/stages/*.md` — task documentation.

## Public API changes

Added 4 authenticated endpoints:

- `GET /api/ingestion/verification/coverage`
- `GET /api/ingestion/verification/reconciliation`
- `GET /api/ingestion/verification/issues`
- `GET /api/ingestion/verification/financial-summary`

Errors from Ingestion period validation return HTTP 422:

```json
{"error":{"code":"invalid_period_range","message":"Некорректный диапазон периода"}}
```

## Migrations

None. No database schema changes and no destructive migration.

## Checks

- Focused unit:
  - `docker compose run --rm site-php-cli ./vendor/bin/phpunit -c phpunit.xml --testsuite=unit --filter 'IssueDescriptionFormatterTest|VerificationPeriodValidatorTest|IngestionExceptionListenerTest'` — passed, 12 tests.
- Focused integration:
  - `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli ./vendor/bin/phpunit -c phpunit.xml --filter VerificationQueriesTest` — passed, 4 tests; PHPUnit reported 3 deprecations without detail.
- Focused functional / tenant-leak:
  - `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli ./vendor/bin/phpunit -c phpunit.xml --filter VerificationApiControllerTest` — passed, 3 tests; PHPUnit reported 3 deprecations without detail.
- Full unit:
  - `make site-test-unit` — passed, 1082 tests; PHPUnit reported 1 warning and 1 deprecation.
- Full integration:
  - `make site-test-integration` — failed in unrelated existing tests: 6 errors, 30 failures across Cash, MarketplaceAds, Telegram, Finance, Inventory, Marketplace.
- CS:
  - `make site-cs-check` — failed on 669 pre-existing files outside this task.
  - Scoped php-cs-fixer dry-run on changed task files — passed.
- API:
  - OpenAPI dump with `php -d memory_limit=-1 bin/console nelmio:apidoc:dump --format=json` in container — passed.
  - `openapi-typescript` generation and `/tmp/schema.check.d.ts` diff — passed.
  - Spectral lint — not run to completion because no Spectral ruleset exists.
  - `make api-types` failed in this environment due Makefile `exec` dependency on running `site-php-cli` and exit 137 during dump; equivalent container `run` workflow passed.
- Diff:
  - `git diff --check` — passed.

## Risks and limitations

- `financial-summary` accepts `shop_ref`, but current `pl_monthly_snapshots` has no shop dimension; it returns company-level rebuilt P&L and documents this in `ARCHITECTURE.md`.
- Reconciliation filters canonical transactions by requested `shop_ref`, while legacy `OzonTransactionTotalsCheck` is company-period scoped.
- Ozon control total is read only from `ozonTotals["total_minor"]`; missing/invalid values return `null`.
- Full project CS/integration suites are not clean in the current branch/environment independently of this task.

## Follow-ups intentionally left out

- Frontend implementation (`TASK-UI-CLIENT-FRONTEND`).
- XLSX export.
- Independent reconciliation recalculation from raw.
- Admin screens/functions.
- Shop-level P&L snapshots or Finance source/shop linking.

## Reviewer focus

- Public API response shapes and `schema.d.ts` output.
- Explicit `company_id` filters in DBAL queries.
- Tenant-leak functional test coverage.
- Reconciliation assumptions around legacy checksum scope and JSON shape.
