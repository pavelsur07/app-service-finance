# Cash auto rules — Stage 7.5 Phase 0: expand Cash and Finance schema

## Status

- Phase: **Stage 7.5 implemented and verified locally**
- Risk: **HIGH** — production schema migration across Cash and Finance facts
- Next action: **STOP; owner review is required before PR/deployment work**
- Production writes: **not performed**
- Historical recalculation/backfill: **forbidden in Stage 7.5**

## Focused result

Deploy nullable ЦФО storage and restrictive foreign keys before any application code reads or writes the new fields.

Stage 7.5 is schema-only:

- no Entity mapping;
- no DTO, Facade, form, controller, import, document, P&L writer, report, or auto-rule change;
- no default assignment or data inference;
- no update of existing facts;
- no P&L rebuild.

Existing rows remain `NULL` and continue to mean `Не распределено` in later stages.

## Baseline inspected

| Fact | Table | Current project storage | Current ЦФО storage |
|---|---|---|---|
| Cash transaction | `cash_transaction` | nullable `project_direction_id` | none |
| Document default | `documents` | nullable `project_direction_id` | none |
| Document operation | `document_operations` | nullable `project_direction_id` | none |
| P&L daily total | `pl_daily_totals` | required `project_direction_id` | none |

`CashTransaction`, `Document`, `DocumentOperation`, and `PLDailyTotal` currently use Doctrine relations for projects. Later ЦФО application fields must follow the current module-boundary rule and store scalar `?string $responsibilityCenterId`; Stage 7.5 deliberately does not add those mappings yet.

`PLDailyTotalRepository::upsert()` currently uses:

```sql
ON CONFLICT (company_id, pl_category_id, date, project_direction_id)
```

Production deploys application images before running Doctrine migrations (`migrations.needs: [changes, deploy]`). Replacing this conflict target in Stage 7.5 would therefore break the active P&L writer after migration. The current four-column key must remain available until a later compatibility release.

## Similar patterns inspected

1. `Version20260716170000` — PostgreSQL guard, explicit restrictive FK names, system-data invariant, and intentionally irreversible migration.
2. `Version20260618140000` — additive nullable audit columns that old application code safely ignores.
3. `Version20260511120000` / `InventorySchemaTest` — PostgreSQL 15 `NULLS NOT DISTINCT` unique indexes and schema-definition assertions.
4. `Version20251212160000` — project-dimension rollout for the same Finance tables; useful schema map, but its immediate backfill/`NOT NULL` cutover must not be repeated for ЦФО.

## Proposed schema target for Stage 7.5

Add exactly one nullable UUID column to each fact table:

| Table | Column | FK | Index |
|---|---|---|---|
| `cash_transaction` | `responsibility_center_id UUID DEFAULT NULL` | `fk_cash_transaction_responsibility_center` | `idx_cash_transaction_responsibility_center` |
| `documents` | `responsibility_center_id UUID DEFAULT NULL` | `fk_documents_responsibility_center` | `idx_documents_responsibility_center` |
| `document_operations` | `responsibility_center_id UUID DEFAULT NULL` | `fk_doc_ops_responsibility_center` | `idx_doc_ops_responsibility_center` |
| `pl_daily_totals` | `responsibility_center_id UUID DEFAULT NULL` | `fk_pl_daily_responsibility_center` | `idx_pl_daily_responsibility_center` |

All four foreign keys reference `financial_responsibility_centers(id)` with `ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE`.

Use simple FK indexes in this expand stage. Composite report indexes are deferred until Stage 7.8 query shapes exist; adding speculative permutations now would increase write cost without evidence.

Database FKs guarantee that a referenced ЦФО exists. Same-company and allowed project/ЦФО-pair validation remains an application invariant for Stages 7.6–7.7; `document_operations` has no direct `company_id`, so one uniform composite FK cannot enforce it across all facts.

## P&L aggregation-key compatibility

### Problem

Changing `uniq_pl_daily_company_cat_date` immediately to include `responsibility_center_id` makes the current four-column `ON CONFLICT` invalid. Leaving only the old key forever prevents two ЦФО rows for the same project/category/day.

The existing key also treats nullable `pl_category_id` values as distinct. Before introducing the future nullable ЦФО key, duplicate legacy groups must be detected explicitly.

### Stage 7.5 solution

After a zero-duplicate guard:

1. Recreate the existing `uniq_pl_daily_company_cat_date` constraint on the same four columns with `NULLS NOT DISTINCT`.
2. Keep its name and column set so the current writer remains valid.
3. Add the future unique index `uniq_pl_daily_company_cat_date_project_center` on:

```text
company_id, pl_category_id, date, project_direction_id, responsibility_center_id
```

with `NULLS NOT DISTINCT`.

Both keys coexist temporarily. The old key intentionally prevents multiple ЦФО buckets until application compatibility is deployed.

The migration must abort before schema changes if any current four-column aggregation group contains more than one row when `NULL` categories are treated as equal. Stage 7.5 must not merge, delete, or recalculate duplicates.

### Later cutover — outside Stage 7.5

Because production deploys code before migrations, Stage 7.7 must be split:

1. **Stage 7.7a compatibility:** change the P&L writer to include nullable `responsibility_center_id` and target the prepared five-column unique index, while all callers still pass `NULL`. Its migration then drops the legacy four-column constraint.
2. **Stage 7.7b behavior:** propagate document/operation ЦФО and start producing project × ЦФО totals only after the old key is gone.

This prevents both deployment windows:

- new code querying a column/index that is not deployed yet;
- new multi-ЦФО writes being blocked by the old four-column constraint.

## Migration outline

The future migration must:

1. Abort unless PostgreSQL is used.
2. Count duplicate `pl_daily_totals` aggregation groups with `GROUP BY company_id, pl_category_id, date, project_direction_id`; abort if the count is non-zero.
3. Add the four nullable UUID columns without defaults other than `NULL`.
4. Create the four simple FK indexes.
5. Add four restrictive FKs to `financial_responsibility_centers(id)`.
6. Recreate the current four-column P&L unique constraint as `NULLS NOT DISTINCT` without renaming it.
7. Create the future five-column `NULLS NOT DISTINCT` unique index.
8. Assert all four new columns contain zero non-null values.

No `UPDATE`, data-copy, default-fill, trigger, queue, command, or report rebuild belongs in the migration.

The prior production volumes were small, so regular transactional indexes are preferred over non-transactional `CREATE INDEX CONCURRENTLY`. The production preflight must be rerun before approval; if table sizes have materially increased, index/lock strategy must be reviewed again.

## Rollback policy

Recommended: make the migration explicitly irreversible.

Old application code safely ignores extra nullable columns, while a future rollback after Stages 7.6–7.7 could silently destroy real ЦФО classifications if `down()` dropped them. Application rollback should therefore leave the expanded schema in place. A physical schema rollback requires a reviewed forward migration or database restore.

## Read-only preflight

Prepared script: `docs/reviews/cash-auto-rules-stage-7-5-preflight.sql`.

It returns only aggregate counts:

- rows in the four fact tables;
- companies violating the exact system project/ЦФО-pair invariant;
- duplicate current P&L aggregation groups and extra rows.

It selects no company names, fact descriptions, amounts, counterparties, or external identifiers. It runs inside a read-only transaction with a statement timeout and explicit rollback.

The restricted production `codex-psql-ro` wrapper hung during Stage 7.4 acceptance, including on `SELECT 1`. Before production migration approval, the wrapper must work again or the owner must run the reviewed preflight manually through an approved read-only path. Do not bypass it with direct Docker or write-capable PostgreSQL access.

## Expected files in Stage 7.5

- `site/migrations/Version<timestamp>.php` — one schema-only, irreversible migration.
- `site/tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php` — column, nullability, FK, index, and P&L unique-key definitions.
- `site/tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php` or the closest existing repository test — repeated upserts for non-null and null categories remain compatible after expansion.
- `docs/reviews/cash-auto-rules-stage-7-5-report.md` — Stage Report.
- `ARCHITECTURE.md` — deployed fact-schema and deferred cutover contract.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — stage status.

No Entity, Repository production code, DTO, Facade, controller, form, template, API, message, worker, or configuration file is expected to change.

## Required checks

Before owner review of the migration:

1. Run the preflight locally; production preflight must report zero invalid companies and zero duplicate P&L groups.
2. Review generated SQL manually; no DML may target fact tables.
3. Apply the full migration chain to an empty local `app_test` database.
4. Apply the Stage 7.5 migration to the current local `app_test` schema.
5. Assert all four columns are UUID and nullable.
6. Assert all four FK definitions use `ON DELETE RESTRICT` and all four indexes exist.
7. Assert both temporary P&L unique keys use `NULLS NOT DISTINCT`.
8. Assert all pre-existing fact row counts are unchanged and every new column remains `NULL`.
9. Run current Cash/document/P&L writer smoke tests, including repeated P&L upserts with nullable category.
10. Run `make site-test-unit`, relevant integration tests, full `make site-test-integration`, and `php bin/console lint:container --env=test`.
11. Restore local `app_test` migration metadata after tests if the test reset clears it.
12. Review `git diff --check`, migration reversibility, debug output, secrets, and unrelated files.

## Must not change

- existing fact rows or classifications;
- existing project values or project hierarchy;
- financial formulas, signs, periods, category semantics, or report results;
- P&L writer conflict target in Stage 7.5;
- Entity mappings or public application contracts;
- Cash/document defaulting or pair validation behavior;
- auto-rule schema or behavior;
- `pl_monthly_snapshots`;
- auth/RBAC, routes, UI, Messenger, workers, cron, production configuration, CI/CD, or dependencies;
- production schema before a separate immediate owner approval and successful preflight.

## Phase 0 checks

- `cash-auto-rules-stage-7-5-preflight.sql` against local `app_test` — OK; all statements ran in a read-only transaction and rolled back, with zero local invariant/duplicate failures.
- PostgreSQL 15 temporary-table compatibility probe — OK; the current four-column `ON CONFLICT` produced one row with the expected accumulated value while both `NULLS NOT DISTINCT` unique keys coexisted; transaction rolled back.
- Repository search confirmed `PLDailyTotalRepository::upsert()` is the only production SQL writer with the current four-column conflict target.
- No application, persistent local schema, production schema, or fact data was changed.

## Phase 0 self-review

- [x] Owner brief and approved Stage 7 contract inspected
- [x] `ARCHITECTURE.md`, `PATTERNS.md`, and relevant backend rules inspected
- [x] Current Cash, document, operation, and P&L entities/repositories traced
- [x] Four similar migration/schema patterns inspected
- [x] Production deploy-before-migrate ordering accounted for
- [x] Historical mutations and report recalculation excluded
- [x] Company/pair validation boundary identified
- [x] Migration, lock, uniqueness, and rollback risks classified HIGH
- [x] Expected files, tests, and forbidden areas listed
- [x] No application, schema, queue, or production mutation performed

## Owner decision gate

Owner approved the schema contract, migration creation, and local `app_test` application on 2026-07-17. The migration and integration checks passed locally; production remains unchanged.

Approval is required for all of the following before migration creation:

1. Four nullable scalar UUID columns and restrictive FKs exactly as listed.
2. No Entity mapping or fact backfill in Stage 7.5.
3. Temporary coexistence of the old four-column and future five-column P&L unique keys, both `NULLS NOT DISTINCT`.
4. Split Stage 7.7 compatibility/behavior cutover.
5. Explicitly irreversible migration.
