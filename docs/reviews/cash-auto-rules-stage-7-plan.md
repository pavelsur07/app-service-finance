# Cash auto rules — Stage 7 CFO model plan

## Status

- Phase: **Stage 7.6.1 accepted in production; Stage 7.9.1a is in schema-only PR #2187**
- Overall risk: **HIGH** because the model adds company master data and new financial dimensions to Cash, documents, P&L aggregates, and auto rules
- Next action: **owner review of schema-only PR #2187**
- Further production mutations: **forbidden until a separately approved migration or backfill step**
- Historical recalculation: **forbidden by default**

## Goal

Add an independent financial responsibility center dimension (user-facing name: **ЦФО**) so reports support both directions:

- ЦФО → projects, for example `Краснодар → Продажа компьютеров / Сервисные услуги`;
- project → ЦФО, for example `Продажа компьютеров → Краснодар / Ростов`.

Each financial fact stores one project and one ЦФО. A project may be allowed for several ЦФО, but one fact is not split between several ЦФО.

## Approved business contract

1. ЦФО is distinct from the root of `ProjectDirection`.
2. ЦФО is an independent company-owned dimension stored on each financial fact.
3. One transaction/document operation has at most one project and one ЦФО; split allocations are out of scope.
4. One project may be paired with several ЦФО. Only explicitly allowed project/ЦФО pairs may be selected.
5. A system pair is available in every company:
   - default project with stable system code `PROJECT_GENERAL`;
   - ЦФО with stable code `CFO_GENERAL` and display name `Общий`.
6. New facts without an explicit project use the system pair. Existing facts are not backfilled during deployment.
7. Internal transfers use the system pair.
8. A document has a default ЦФО; each document operation stores its own ЦФО and may override the document default.
9. Cash-to-document conversion copies the Cash transaction ЦФО.
10. P&L supports filtering/comparison by ЦФО and projects within ЦФО without changing formulas, signs, or period semantics.
11. The first version reuses current `ROLE_USER` access. Owner/financial-director permissions require a separate auth stage.
12. The ЦФО auto-rule target is independent, defaults to safe `FILL`, preserves manual values, and validates the resulting project/ЦФО pair.
13. A rule may set project and ЦФО together. A ЦФО-only target requires an already compatible project.
14. System values cannot be deleted, archived, or have their stable codes changed.
15. The main navigation contains `Справочники → ЦФО`, linking to the company-scoped ЦФО list.
16. Every newly created company atomically receives:
   - the system project `PROJECT_GENERAL`;
   - the active system ЦФО `CFO_GENERAL` with display name `Общий`;
   - the allowed system project/ЦФО pair.
   A company must not be persisted in a partially bootstrapped state.

## Current implementation and reusable patterns

### Existing facts and reports

- `CashTransaction` stores an optional `ProjectDirection` but no ЦФО.
- `Document`, `DocumentOperation`, and `PLDailyTotal` already carry project identity.
- `PLDailyTotal` is uniquely aggregated by company, category, date, and project; ЦФО must be part of the future aggregation key to preserve the project × ЦФО matrix.
- `CashTransactionAutoRuleService` already resolves independent per-field winners and has preview/audit provenance. ЦФО must reuse this path rather than add a second matcher.

### Similar project patterns inspected

1. `ProjectDirection` — company-owned flat/tree master used across Cash and Finance; useful for project choices but currently has no stable code, archive state, or optimistic version.
2. `CashflowCategory` — stable company-scoped system codes and guarded system values; use the same reserved-code concept for `PROJECT_GENERAL` and `CFO_GENERAL`.
3. `Counterparty` — active/archive lifecycle that preserves referenced history; ЦФО should archive rather than delete.
4. New-module conventions in `PATTERNS.md` — scalar `companyId`, IDOR-safe repositories, cross-module choices through a Facade/DTO, and optimistic locking for editable settings.

### Existing navigation and company bootstrap

- The main sidebar already has a `Справочники` dropdown. The ЦФО link belongs there next to `Проекты`; no new top-level menu or frontend framework is needed.
- Main production company-creation paths converge on `CompanyOwnerMembershipCreator::persistCompanyWithOwnerMembership()`. This is the single bootstrap boundary for creating `PROJECT_GENERAL`, `CFO_GENERAL`, and their allowed pair in the same transaction.
- Test fixtures that construct companies directly must create the same invariant explicitly; production code must not depend on fixtures.

### Default-project compatibility risk

The current default project is identified inconsistently by names such as `Общий`, `Основной`, and `Общие операции`. Name matching is not a safe long-term key.

The read-only production preflight found 10 companies with exactly one recognizable default project and no ambiguous company. Those 10 projects can receive the stable `PROJECT_GENERAL` code. The remaining 1333 companies have no recognizable candidate: 1332 are empty, while one has Cash facts but no project records at all. For this cohort, the proposed migration creates a new system project without changing or inferring classifications on existing facts.

The migration must not identify any other project by name, update existing Cash/document/P&L rows, or silently map an unrelated project. The one initialized company without projects remains an explicit reviewer-focus case even though creating a new unused system project is additive.

## Recommended domain model

### Company module owner

Create `FinancialResponsibilityCenter` in the `Company` module with:

- UUID identity;
- scalar `companyId`;
- required stable code unique within the company;
- editable name and sort order;
- `ACTIVE` / `ARCHIVED` status;
- optimistic-lock version;
- created/updated timestamps;
- guards for the reserved `CFO_GENERAL` system record.

Keep the first version flat. Do not add parent/children, budgets, responsible users, colors, external mappings, or effective-date history.

### Allowed project/ЦФО pairs

Use one company-owned join table with a unique `(project_direction_id, responsibility_center_id)` pair and restrictive foreign keys.

- Removing a pair affects only future selection.
- Historical facts keep their directly stored ЦФО IDs.
- Cross-company pairs are rejected in the application layer and covered by integration tests.
- No effective dates are required because the fact stores the selected ЦФО snapshot.

### Cross-module contract

Expose company-scoped scalar DTOs through a minimal `FinancialResponsibilityCenterFacade`:

- list active choices for a company;
- find one by ID and company;
- list/validate allowed project pairs.

Cash and Finance forms must use scalar IDs/DTO choices rather than a cross-module `EntityType`.

### New-company bootstrap invariant

`CompanyOwnerMembershipCreator` must persist the company, owner membership, system project, system ЦФО, and allowed pair before the caller's successful flush.

- All main company creation flows reuse this service; do not duplicate the invariant in controllers.
- The system ЦФО is active immediately and available in `Справочники → ЦФО` after company creation.
- Bootstrap is idempotent at the database-constraint level: one reserved project code, one reserved ЦФО code, and one system pair per company.
- A failed bootstrap rolls back the whole company creation transaction; no repair job or eventual-consistency queue is introduced.

### Fact storage

Add nullable ЦФО IDs with restrictive foreign keys to:

- `cash_transaction`;
- `documents`;
- `document_operations`;
- `pl_daily_totals`;
- `cash_transaction_auto_rule` when the auto-rule target stage is approved.

Columns remain nullable during rollout. Existing rows stay unchanged and appear as `Не распределено` until an explicit backfill is approved.

## Application behavior

### New and edited Cash transactions

- Explicit project + ЦФО must be an allowed same-company pair.
- Missing both values resolves to the system pair for new facts.
- A non-system project without ЦФО is invalid.
- Changing project revalidates the current ЦФО; the UI must not submit a stale incompatible pair.
- Existing legacy rows with both values absent remain readable/editable without an implicit historical write.

### Documents and P&L

- Document ЦФО is the default for new operations.
- Operation ЦФО is the authoritative P&L fact dimension.
- Cash-to-document conversion copies the Cash pair.
- P&L aggregation keys include both project and ЦФО.
- Reports offer ЦФО filtering/comparison and project drill-down without changing existing formulas.
- Rebuilding historical aggregates is a separate production mutation and is never a deployment side effect.

### Auto rules

- Add ЦФО as a fourth independent output target only after the Company, Cash, and Finance model is stable.
- Reuse the existing winner, safe-fill, conflict, preview, audit, revision, and correlation contracts.
- Manual ЦФО wins over `FILL`.
- `UPDATE` for ЦФО remains disabled in the first rollout.
- Validate the final project/ЦФО pair after combining all winning fields; invalid combinations are skipped with an explicit preview reason.
- Do not add ЦФО conditions or candidate generation in v1.

## Staged implementation

### Stage 7.1 — Read-only default-project and data-shape preflight

**Risk:** LOW locally; production access remains read-only

**Result:** anonymized production counts identify the default-project migration cohorts, current null coverage, project/transaction/document volumes, and the sole initialized exception.

Work:

1. Prepare reviewed read-only SQL only; do not add a production command.
2. Count default-project name candidates per company without exposing company names or transaction data.
3. Count current project-null coverage in Cash/documents/operations and P&L totals.
4. Record conflicts and the exact migration policy.

Production result:

- 1343 companies: 1332 empty and 11 initialized;
- 10 initialized companies have exactly one recognizable default project;
- one initialized company has 2751 active Cash facts and zero projects;
- no company has multiple recognizable candidates;
- existing fact null coverage is documented in the Stage 7.1 report and remains untouched.

**Next action:** DONE; mandatory STOP for owner review of the Stage 7.2 migration policy.

### Stage 7.2 — Company ЦФО master data and project mapping

**Risk:** HIGH (new tables, project system-code migration, system master rows)

**Result:** company-scoped flat ЦФО master data, `PROJECT_GENERAL`, `CFO_GENERAL`, and allowed project/ЦФО pairs exist without changing financial facts; every newly created company receives the system pair atomically.

Work:

1. Add domain entity/status, repository, minimal facade DTOs, and company-isolation tests.
2. Add the reviewed additive migration and restrictive indexes/foreign keys.
3. Assign `PROJECT_GENERAL` to the 10 unambiguous existing candidates from Stage 7.1; create a new system project for the 1333 companies without a candidate.
4. Create one `CFO_GENERAL` per company and the system allowed pair, without updating existing financial facts.
5. Update `CompanyOwnerMembershipCreator` and direct test fixtures to create the same system pair.
6. Add integration coverage proving all supported company-creation flows produce exactly one system project, one system ЦФО, and one allowed pair in a single transaction.

Rollback policy: the migration is explicitly irreversible because it creates system projects for companies without a candidate. A Doctrine `down` aborts before changing the schema; a full data rollback requires restoring a database backup.

Implementation status: DONE; the migration and Stage 7.2 tests passed on local `app_test`, and `Version20260716170000` was applied successfully in production on 2026-07-17. Existing production financial facts remained unchanged.

The Stage 7.2 production gate was completed before Stage 7.3 began.

### Stage 7.3 — Company management backend

**Risk:** MEDIUM

**Result:** thin company-scoped actions support create/edit/archive and project-pair configuration with optimistic locking.

No public route or UI is added in this stage.

Implementation status: DONE; company-scoped create/edit/archive and project-pair actions use explicit expected versions, protect the system values, and passed the full unit/integration checks.

**Next action:** mandatory STOP before Stage 7.4 protected routes/UI.

### Stage 7.4 — Protected Company management UI

**Risk:** HIGH (new protected routes)

**Result:** existing users can open `Справочники → ЦФО` and manage flat ЦФО records and allowed project pairs using existing Twig/UI-kit patterns.

Add the route-aware `ЦФО` item to the existing `Справочники` dropdown in the main sidebar. Do not add a second menu, new top-level navigation item, role, voter, API, React entrypoint, UI dependency, or design-system component.

Implementation status: DONE; protected company-scoped Twig routes support list/create/edit/archive and allowed-project configuration through the Stage 7.3 Actions. Navigation and cross-company access are covered by functional tests.

Production status: ACCEPTED; deployment, `Version20260716170000`, container health, and manual `Справочники → ЦФО` verification passed. Existing financial facts were not changed.

**Next action:** mandatory STOP before Stage 7.5 fact-schema migration.

### Stage 7.5 — Expand Cash and Finance schema

**Risk:** HIGH (additive nullable fact columns and restrictive FKs)

**Result:** nullable ЦФО columns and foreign keys are deployed before application code reads them.

This is an expand-only migration unit. It does not update existing facts, rebuild P&L, or change reports.

Implementation status: DONE and accepted in production. PR #2185 was merged, the deployment and production migration jobs passed, and `Version20260717120000` was applied without backfill or historical recalculation. The detailed contract, read-only preflight, and Stage Report are in `cash-auto-rules-stage-7-5-plan.md`, `cash-auto-rules-stage-7-5-preflight.sql`, and `cash-auto-rules-stage-7-5-report.md`.

Stage 7.5 leaves the current four-column P&L uniqueness and conflict target byte-for-byte unchanged. A review found that `NULLS NOT DISTINCT` would break the existing category-deletion path (`ON DELETE SET NULL`), so the future project × ЦФО key and its concurrency/deployment contract are deferred to a separate Stage 7.7 Phase 0.

**Next action:** DONE; mandatory STOP for owner approval of the Stage 7.6 Phase 0 contract.

### Stage 7.6 — Cash transaction integration

**Risk:** HIGH (financial classification behavior)

**Result:** Cash create/edit/import paths persist one validated project/ЦФО pair; new missing values use the system pair while existing history is untouched.

Include manual forms, DTO/facade resolution, company/pair guards, audit behavior, and focused import regression tests.

Phase 0 status: PREPARED. Stage 7.6.1 scalar mapping/resolution is DONE and accepted in production without connecting runtime writers. The remaining implementation is split into core writers, manual UI, and import cutover. Under approved Option A, Stage 7.9 schema/authoring, explicit existing-rule configuration, and complete-pair planner acceptance must happen before runtime/import defaults are enabled. See `cash-auto-rules-stage-7-6-plan.md` and `cash-auto-rules-stage-7-9-plan.md`.

### Stage 7.7 — Document and P&L integration

**Risk:** HIGH (document propagation and report aggregation semantics)

**Result:** documents/operations persist ЦФО, Cash conversion copies it, and new P&L totals retain the project × ЦФО dimension.

No historical rebuild is run. Existing null rows remain in an explicit `Не распределено` bucket.

### Stage 7.8 — Read-only Cash/P&L analytics

**Risk:** HIGH if routes/contracts change; otherwise MEDIUM for internal queries

**Result:** users can view totals by ЦФО, projects within ЦФО, ЦФО within project, and the project × ЦФО matrix.

Backend query/contract work and frontend presentation must remain separate reviewable units if a new endpoint or UI screen is required.

### Stage 7.9 — ЦФО auto-rule target

**Risk:** HIGH (new rule output and nullable rule foreign key)

**Result:** preview and execution can safely fill ЦФО using the existing per-field winner and provenance model.

`UPDATE`, ЦФО conditions, candidate generation, and historical runs remain out of scope.

Phase 0 status: PREPARED under Option A. The proposed contract adds a scalar nullable rule target first, requires explicit configuration of existing active project-target rules, then applies project and ЦФО as one validated safe-fill pair. Category and counterparty remain independent. See `cash-auto-rules-stage-7-9-plan.md` and the aggregate-only `cash-auto-rules-stage-7-9-preflight.sql`.

### Stage 7.10 — Hardening and controlled production acceptance

**Risk:** HIGH for production acceptance

**Result:** full regression, company-isolation review, read-only production verification, and an explicit handoff.

Any fact backfill or P&L rebuild requires a new immediate owner approval with company and date bounds.

## Required checks

- domain/unit tests for reserved codes, archive rules, optimistic locking, and pair validation;
- integration tests for atomic new-company bootstrap and uniqueness of the system project/ЦФО pair;
- integration tests for company isolation and restrictive foreign keys;
- migration review and empty-database migration test for every schema unit;
- Cash create/edit/import tests for system defaults and invalid pairs;
- document propagation and operation-override tests;
- P&L aggregation tests for both report directions and null legacy facts;
- auto-rule matcher/preview/application/audit tests for `FILL`, manual values, conflicts, and invalid pairs;
- Symfony container, Twig, frontend build/lint, targeted CS, full unit suite, and relevant integration suite;
- functional navigation test proving `Справочники → ЦФО` resolves to the active-company list and marks ЦФО routes active;
- complete diff/security/no-debug review after every stage.

## Expected change areas

- `site/src/Company` — ЦФО entity, status, repository, facade/DTO, actions, forms/controllers/templates;
- `site/src/Company/Entity/ProjectDirection.php`, `CompanyOwnerMembershipCreator`, fixtures, and bootstrap/default resolution;
- `site/templates/partials/_sidebar.html.twig` — one `Справочники → ЦФО` link and route-active state;
- additive migrations for Company master data and later Cash/Finance fact columns;
- `site/src/Cash` — transaction DTO/entity/service/forms, document conversion, auto-rule target and preview;
- `site/src/Finance` — documents/operations, daily totals, register updater, report queries/builders;
- focused tests under `site/tests/Unit`, `site/tests/Integration`, and `site/tests/Functional`;
- `ARCHITECTURE.md` after each approved public/domain contract change.

## Must not change

- existing financial formulas, signs, periods, category meanings, or project hierarchy semantics;
- existing transaction/document classifications during migrations or deployment;
- historical P&L aggregates without an explicit bounded rebuild approval;
- current auth/RBAC in Stage 7; owner/financial-director permissions are a separate task;
- Messenger transports, routing, workers, cron, production config, or dependencies;
- existing auto-rule priority, conflict, safe-fill, revision, preview, or audit semantics;
- split allocations, budgets, responsible-user ownership, hierarchy, colors, external integrations, or ЦФО candidate generation;
- automatic inference from project names beyond the owner-reviewed default-project mapping.

## Phase 0 self-review

- [x] Clear owner brief and approved business rules
- [x] Architecture and project patterns inspected
- [x] Similar company/project/category lifecycle patterns inspected
- [x] Company isolation and IDOR boundaries identified
- [x] Schema, financial semantics, auth, route, and production risks classified HIGH where applicable
- [x] Backend/frontend and schema/code rollout separated
- [x] Historical mutation and recalculation excluded
- [x] No application, schema, production, or queue change made in Phase 0
