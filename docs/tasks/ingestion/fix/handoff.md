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

---

# Frontend Final Handoff — TASK-UI-CLIENT-FRONTEND

## Summary

Implemented 4 Twig + React island pages for Ingestion verification:

- `/ingestion/verification/coverage`
- `/ingestion/verification/reconciliation`
- `/ingestion/verification/issues`
- `/ingestion/verification/financial-summary`

The frontend uses the existing `useAbortableQuery` + `httpJson` pattern, generated `schema.d.ts` aliases, flat Vite entries, and no new npm dependencies.

## Files changed

- `site/assets/react/ingestion-verification/` — new feature slice with API hooks, generated-type aliases, shared UI, widgets, views, and date helpers.
- `site/assets/react/ingestion-verification-*-page.tsx` — 4 new flat Vite entries.
- `site/vite.config.js` — 4 new rollup inputs.
- `site/templates/ingestion/verification/` — 4 Twig pages plus shared tabs partial.
- `site/src/Ingestion/Controller/Page/` — 4 thin page controllers with `ROLE_COMPANY_USER`.
- `site/tests/Functional/Ingestion/Controller/VerificationPageControllerTest.php` — focused page route tests.
- `site/assets/react/shared/http/client.ts` — 422 responses now surface backend `error.message` when present.
- `ARCHITECTURE.md`, `docs/tasks/ingestion/fix/plan.md`, `docs/tasks/ingestion/fix/stages/stage-F*.md` — docs updated.

## Public API / contract changes

- No backend API changes.
- No `config/routes.yaml` changes.
- Added frontend page routes only:
  - `GET /ingestion/verification/coverage`
  - `GET /ingestion/verification/reconciliation`
  - `GET /ingestion/verification/issues`
  - `GET /ingestion/verification/financial-summary`

## Migrations

None. No database schema changes.

## Checks

- `docker compose run --rm site-php-cli ./vendor/bin/phpunit -c phpunit.xml --filter VerificationPageControllerTest` — passed, 2 tests / 14 assertions; PHPUnit reported 3 deprecations.
- `make site-test-unit` — passed, 1082 tests / 6457 assertions; PHPUnit reported 1 warning and 1 deprecation.
- `cd site && npx vite build --configLoader runner --outDir /tmp/app-service-finance-vite-build --emptyOutDir` — passed and generated all 4 `ingestion_verification_*_page` chunks.
- `docker compose run --rm site-php-cli php bin/console lint:twig templates/ingestion/verification` — passed.
- `docker compose run --rm site-php-cli php -l src/Ingestion/Controller/Page/*PageController.php` — passed for all 4 controllers.
- `rg -n "byType|byMonth|byCategory" site/assets/react/ingestion-verification site/assets/react/ingestion-verification-*.tsx` — no matches.
- `cd site && npx tsc --noEmit` — failed on existing non-ingestion TS errors:
  - `assets/react/Dashboard/DashboardGrid.tsx`
  - `assets/react/dashboard_started.tsx`
  - `assets/react/marketplace_analytics_kpi.tsx`
  - `assets/react/reconciliation/widgets/ReconciliationWidget.tsx`
- `cd site && npm run build` — failed in this workspace because `site/node_modules/.vite-temp` and `site/public/build` are root-owned; equivalent Vite build to `/tmp/app-service-finance-vite-build` passed.

## Risks and limitations

- Reconciliation requires a concrete shop; the UI does not call the reconciliation API until a shop is selected.
- Shop options are loaded through the coverage endpoint because the backend verification API has no standalone shop-options endpoint.
- Financial summary still reflects the backend limitation documented earlier: `shop_ref` is accepted but current P&L snapshots are company-level.
- Normal build output ownership should be fixed outside this code change so `npm run build` can write to `site/public/build`.

## Follow-ups intentionally left out

- New backend endpoints or response fields.
- New npm dependencies.
- XLSX export.
- Legacy P&L page changes.
- Global layout/sidebar changes.

## Reviewer focus

- New Vite entry keys and Twig mount ids.
- Generated OpenAPI type alias usage in `types.ts`.
- Reconciliation no-shop empty guidance.
- Error handling for backend 422 `error.message`.
