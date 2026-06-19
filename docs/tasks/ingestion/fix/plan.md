# План TASK-UI-CLIENT-BACKEND

## Summary

Подготовить backend для 4 клиентских Ingestion verification API без миграций и без frontend-работ: DBAL read-model queries, DTO/View слой, расширение `IngestionFacade`, 4 REST-контроллера с OpenAPI, единый listener ошибок, обновление `schema.d.ts`, functional/integration/unit тесты и документация.

Так как добавляются публичные эндпоинты, этапы с API-контрактом классифицируются как HIGH и требуют owner review по правилам репозитория.

## Key Changes

- Добавить read-модели в `App\Ingestion\Application\DTO`: coverage, shops, reconciliation summary/by-type, issues, financial summary, pagination meta.
- Добавить DBAL queries в `App\Ingestion\Infrastructure\Query`:
  - `CoverageQuery`: raw/tx/open-issue counts через `ingest_raw_records` как базу, `shop_ref` optional, дата по `fetched_at::date`, tx/issues через `raw_record_id`.
  - `ReconciliationQuery`: canon sum по `ingest_financial_transactions.occurred_at` за месяц и `shop_ref`; legacy control по latest `marketplace_ozon_transaction_totals_checks` за период компании.
  - `IssuesQuery`: только unresolved issues, без technical fields в ответе, Pagerfanta limit max 200.
  - `FinancialSummaryQuery`: `pl_monthly_snapshots` с `rebuilt_at IS NOT NULL`, join `pl_categories`, суммы decimal -> minor units.
- Расширить `IngestionFacade` 4 методами из ТЗ; контроллеры обращаются только к фасаду и берут `companyId` из `ActiveCompanyService`.
- Добавить `IssueDescriptionFormatter` и exceptions `InvalidPeriodException`, `InvalidPeriodRangeException`.
- Добавить `IngestionExceptionListener` на `/api/ingestion/*` с ответом `{"error":{"code": "...", "message": "..."}}`.
- Добавить 4 контроллера в `App\Ingestion\Controller\Api\Verification`:
  - `GET /api/ingestion/verification/coverage`
  - `GET /api/ingestion/verification/reconciliation`
  - `GET /api/ingestion/verification/issues`
  - `GET /api/ingestion/verification/financial-summary`
- Добавить OpenAPI attributes и сгенерировать `site/assets/api/schema.d.ts` через `make api-types`.
- Логировать каждое обращение через `LoggerInterface` на уровне `info`: endpoint, `companyId`, нормализованные query params, без secrets/PII.

## Stages

1. **Stage 0: Persist plan — LOW**
   - В execution mode сохранить этот план в `docs/tasks/ingestion/fix/plan.md`.
   - STOP для owner approval перед кодом.

2. **Stage 1: DTO, formatter, validation exceptions — MEDIUM**
   - Добавить DTO/View классы, `IssueDescriptionFormatter`, period validation helpers/exceptions.
   - Unit tests на formatter и validation.
   - No API routes yet.

3. **Stage 2: DBAL queries and facade — MEDIUM**
   - Реализовать 4 query-класса и расширить `IngestionFacade`.
   - Integration tests на aggregation, filters, pagination, empty states.
   - Reconciliation control parser ожидает `ozonTotals["total_minor"]`; если ключа нет или записи нет, `ozonControlTotalMinor = null`.

4. **Stage 3: Controllers, OpenAPI, exception listener — HIGH**
   - Добавить 4 public endpoints, OpenAPI annotations, `#[IsGranted('ROLE_USER')]`, `ActiveCompanyService`, logging.
   - Добавить listener ошибок.
   - Functional tests на 200/422 responses.
   - STOP after green self-review because public API is introduced.

5. **Stage 4: Tenant-leak and contract hardening — HIGH**
   - Functional tenant-leak tests for all 4 endpoints: company A never sees company B data.
   - Проверить отсутствие `raw_record_id`, `operation_group_id`, `details` в API responses.
   - STOP after green self-review because this validates public tenant boundary.

6. **Stage 5: API types and docs — LOW**
   - Run `make api-types`, commit generated `site/assets/api/schema.d.ts`.
   - Update `ARCHITECTURE.md` with new Ingestion verification endpoints and facade methods.
   - Run API checks.

7. **Final Handoff — HIGH STOP**
   - Run relevant full checks, review diff, save `docs/tasks/ingestion/fix/handoff.md`.
   - STOP for final owner review.

## Test Plan

- Unit:
  - `IssueDescriptionFormatter` maps all `NormalizationIssueKind` values.
  - Invalid month/year/range exceptions produce expected codes/messages through listener.
- Integration:
  - `CoverageQuery` counts raw records, canonical transactions, unresolved issues, shops distinct last 90 days, and `shop_ref` filtering.
  - `ReconciliationQuery` sums signed canonical minor amounts by type and month; returns null control when no checksum or no `total_minor`.
  - `IssuesQuery` returns unresolved issues only, max limit 200, no technical fields.
  - `FinancialSummaryQuery` includes only rebuilt snapshots and converts decimal money to minor units.
- Functional:
  - 4 endpoints return expected JSON shape and snake_case keys.
  - 422 errors use unified `{error:{code,message}}`.
  - Tenant-leak test per endpoint with two companies.
- Commands:
  - `make site-test-unit`
  - `make site-test-integration`
  - `make site-cs-check`
  - PHPStan command if available in composer/make scripts
  - `make api-doc-lint`
  - `make api-types-check`

## Assumptions

- No database migrations, no entity field changes, no new dependencies.
- `schema.d.ts` is generated, not hand-edited.
- Reconciliation: canon is filtered by requested `shop_ref`; legacy Ozon control is company-period scoped because current `OzonTransactionTotalsCheck` has no `shop_ref`.
- `ozon_control_total_minor` is read from `OzonTransactionTotalsCheck::getOzonTotals()["total_minor"]`; missing/invalid value returns `null`.
- Financial summary remains a DBAL read model in Ingestion and does not extend `PnlFacade`, because current `PnlFacade` has no summary read contract.
- `financial-summary.by_category` uses the last month of the requested range, as specified.
- Existing user changes in `docs/tasks/fix/*`, `.mimocode/`, and `docs/tasks/s3/` are unrelated and must not be touched.

---

# План TASK-UI-CLIENT-FRONTEND

## Summary

Реализовать 4 React island-страницы для Ingestion verification поверх готовых backend API:

- `/ingestion/verification/coverage`
- `/ingestion/verification/reconciliation`
- `/ingestion/verification/issues`
- `/ingestion/verification/financial-summary`

## Implementation Decisions

- Не добавлять `@tanstack/react-query`; использовать текущий repo pattern: `useAbortableQuery` + `httpJson`.
- Не создавать новую директорию `assets/react/entrypoints/`; использовать существующий flat Vite pattern: `site/assets/react/*-page.tsx`.
- Backend API не менять.
- `config/routes.yaml` не менять: `src/Ingestion/Controller/` уже импортируется attribute routing.

## Stages

1. **Stage F0 — Plan docs, LOW**
   - Добавить frontend plan addendum в `docs/tasks/ingestion/fix/plan.md`.

2. **Stage F1 — Types/API hooks, MEDIUM**
   - Добавить type aliases из `schema.d.ts`.
   - Добавить 4 hooks поверх `useAbortableQuery`, safe defaults, retry callback через `reload`.

3. **Stage F2 — Shared UI, MEDIUM**
   - Добавить `ShopSelector`, `PeriodPicker`, money/delta/status/state components.
   - Использовать существующий Tabler/class pattern and `shared/components/Pagination`.

4. **Stage F3 — Widgets/Views, MEDIUM**
   - Реализовать 4 smart widgets and dumb views.
   - Loading/empty/error/success states for each page.
   - Debounce 500ms перед запросами после изменения периода.

5. **Stage F4 — Page controllers, Twig, Vite entries, HIGH**
   - Добавить controllers, templates, flat entry files, Vite inputs.
   - No backend API/routes.yaml changes.

6. **Stage F5 — Tests/docs/final, LOW/MEDIUM**
   - Functional page tests.
   - Frontend build/type checks.
   - Architecture docs, stage reports, final handoff.

## Test Plan

- PHP functional page tests for authenticated and unauthenticated access.
- Rendered HTML contains correct mount ids and Vite entry keys.
- `cd site && npx tsc --noEmit`.
- `cd site && npm run build`; if root-owned build artifacts block it, verify bundling with temporary Vite outDir.
- `make site-test-unit`.
- No stale `byType`, `byMonth`, or `byCategory` references in the ingestion verification slice.

## Assumptions

- Backend API from `TASK-UI-CLIENT-BACKEND` remains the source of truth.
- Coverage API response is the only current source of shop options and can be reused by the other pages.
- No npm dependencies are added.
- Existing P&L legacy page, global layout, auth flow, CSRF flow, and backend financial logic are unchanged.
