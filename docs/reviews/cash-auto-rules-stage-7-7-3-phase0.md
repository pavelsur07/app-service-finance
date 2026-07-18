# Cash auto rules — Stage 7.7.3 Phase 0: P&L daily totals Project × ЦФО key

## Scope

Stage 7.7.3 changes only new P&L daily total writes from documents so `pl_daily_totals` is keyed by `Project × ЦФО`.

## Definition of Done

- `PLRegisterUpdater` aggregates rows by `date × project × responsibilityCenterId × category`.
- `PLDailyTotalRepository::upsert()` accepts nullable `responsibilityCenterId`.
- Categorized rows use deterministic `ON CONFLICT` on `company_id, pl_category_id, date, project_direction_id, COALESCE(responsibility_center_id, zero-uuid) WHERE pl_category_id IS NOT NULL`.
- Uncategorized rows use deterministic partial `ON CONFLICT` on `company_id, date, project_direction_id, COALESCE(responsibility_center_id, zero-uuid) WHERE pl_category_id IS NULL`.
- `pl_category_id` keeps PostgreSQL default nullable semantics; no `NULLS NOT DISTINCT`.
- Category deletion with `ON DELETE SET NULL` still succeeds.
- Legacy `responsibility_center_id IS NULL` rows remain valid.
- No historical rebuild and no production mutation are performed by this stage.

## Risk

HIGH-LOCAL. Production migration/apply/acceptance remains HIGH-EXTERNAL and is excluded.

## Existing patterns inspected

- `PLRegisterUpdater` clears and rewrites daily totals for the affected day/range before upserting aggregated document rows.
- `PLDailyTotalRepository::upsert()` owns raw SQL `INSERT ... ON CONFLICT`.
- `ResponsibilityCenterFactSchemaTest` already documents Stage 7.5 fact schema and must be updated from “P&L uniqueness unchanged” to the Stage 7.7.3 uniqueness contract.
- `PLDailyTotalRepositoryTest` covers nullable category semantics and category deletion.
- `PLRegisterUpdaterStornoSymmetryTest` covers signed marketplace PL semantics that must not change.

## Implementation plan

1. Add a forward switch migration for new P&L uniqueness contracts:
   - rely on the existing production pipeline order: full app image rollout finishes before the migration job starts;
   - support the required `new code / old schema` window through repository runtime detection;
   - do not support `old code / new schema` as a rollback mode; rollback after this migration requires a reviewed forward-fix or redeploying Stage 7.7.3-compatible code;
   - drop legacy `uniq_pl_daily_company_cat_date` in this switch point because keeping it would block separate ЦФО buckets for the same company/category/date/project;
   - drop redundant non-unique `idx_pl_daily_company_cat_date`;
   - add a partial expression unique index for categorized rows;
   - add a partial expression unique index for uncategorized rows;
   - lock `pl_daily_totals` before duplicate guard if the migration performs a guard.
2. Extend `PLDailyTotalRepository::upsert()` with `?string $responsibilityCenterId` and route conflict targets by nullable category path.
3. Extend `PLRegisterUpdater` aggregation key to include operation/document ЦФО fallback:
   - operation ЦФО wins;
   - document ЦФО is used when operation ЦФО is empty;
   - `NULL` remains a valid legacy bucket.
4. Update `PLDailyTotal` mapping/index metadata to match the new query/index contract.
5. Add/adjust tests:
   - repository upsert accumulates by same ЦФО and splits by different ЦФО;
   - uncategorized same key upserts instead of duplicates;
   - category deletion still succeeds;
   - register writes separate daily totals for different ЦФО;
   - existing marketplace signed semantics still passes.
6. Update `ARCHITECTURE.md` Stage 7.7.3 notes.

## Baseline

- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php tests/Integration/Finance/PLRegisterUpdaterStornoSymmetryTest.php tests/Integration/Finance/CreatePLDocumentActionTest.php tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php tests/Functional/Finance/PLCategoryEditControllerTest.php` — OK, 14 tests, 113 assertions.

## Excluded

- P&L report UI/API filtering by ЦФО.
- Marketplace source-level ЦФО mapping.
- Ingestion rebuild source/shop/CFO semantics.
- Historical P&L rebuild.
- Production/staging migration execution.
