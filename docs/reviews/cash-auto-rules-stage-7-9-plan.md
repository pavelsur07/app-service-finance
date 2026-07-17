# Cash auto rules — Stage 7.9 Phase 0: complete project/ЦФО targets

## Status

- Phase: **Stage 7.9.1b accepted in production; Stage 7.9.2 batch command implemented and awaiting owner review**
- Selected transition: **Option A — coordinated cutover**
- Overall risk: **HIGH** because the stage changes financial classification semantics and adds a nullable auto-rule target column
- Current production behavior: **unchanged**; Stage 7.6.1 and rule authoring are deployed, but no Cash writer assigns the system pair and the configuration gate remains open
- Historical application, backfill, and recalculation: **forbidden**
- Next action: **owner review of the Stage 7.9.2 dry-run/execute batch command; production execution remains separately gated**

## Goal

Extend the existing Cash auto-rule planner with a fourth independent winner field, `responsibilityCenterId`, while applying project and ЦФО changes as one company-valid pair.

The coordinated cutover must preserve current category/counterparty behavior, prevent project-only rules from producing an invalid pair, and prepare the rule set before Stage 7.6 enables the `PROJECT_GENERAL × CFO_GENERAL` runtime default.

## Approved baseline

- One Cash fact may contain one project and one ЦФО.
- One project may be allowed for several ЦФО; neither side may be inferred from names, ordering, or a temporary unique match.
- `PROJECT_GENERAL × CFO_GENERAL` is the system unallocated pair.
- Stage 7.6.1 maps nullable `CashTransaction.responsibilityCenterId` and provides company-scoped pair validation, but no writer uses it.
- The current auto-rule service resolves category, project, and counterparty winners independently by priority, specificity, and immutable rule ID.
- Current `FILL` and legacy `UPDATE` both preserve assigned values. Stage 7.9 must not enable overwrites.
- Preview, manual apply, and worker execution share the same application plan and audit provenance.
- Auto-rule conditions and candidate generation do not gain ЦФО in v1.

## Inspected project patterns

1. `CashTransactionAutoRuleService::resolveMatch()` — existing per-field priority/specificity/conflict resolution.
2. `CashTransactionAutoRuleApplicationPlan` — non-mutating plan reused by preview and execution, with per-field rule revision provenance.
3. `CashTransactionAutoRuleType` and controller create/edit paths — existing company-scoped target authoring and Doctrine optimistic revision.
4. `FinancialResponsibilityCenterFacade` — scalar DTO choices, active company lookup, and allowed project/ЦФО pair checks across the module boundary.
5. Stage 7.6 resolver — fail-closed system/explicit pair semantics without importing Company repositories or ЦФО Entities into Cash.

No second matcher, generic classification framework, feature dependency, public endpoint, Messenger message, or new queue is justified.

## Current behavior that creates the cutover dependency

The current project safe-fill condition is `transaction.projectDirection === null`. Once a writer assigns the system pair, an existing project-only rule no longer sees an empty project. Replacing only the system project would also create an invalid `project × CFO_GENERAL` pair for most custom projects.

Therefore:

1. The rule schema and authoring UI must support an explicit ЦФО target first.
2. Active project-target rules must be inventoried and configured with a compatible ЦФО.
3. The shared planner must deploy and pass controlled acceptance.
4. Only then may Stage 7.6 enable system defaults in runtime/import writers.

## Target storage and module boundary

- Add nullable scalar `responsibility_center_id UUID` to `cash_transaction_auto_rule`.
- Add an index and restrictive FK to `financial_responsibility_centers(id)`.
- Do not backfill or infer rule targets.
- Map the target as `?string $responsibilityCenterId`, not as a Doctrine association to a Company Entity.
- Use `FinancialResponsibilityCenterFacade` and scalar DTOs for choices, labels, active-state checks, company isolation, and pair validation.
- Preserve disabled/legacy rules whose target is `NULL` or later becomes archived. They remain readable but cannot newly assign an unavailable ЦФО.

### Rollback contract

- The forward migration is additive and does not modify existing rows.
- `down()` may drop the column only while every value is `NULL`.
- Once any rule stores a ЦФО target, rollback must abort rather than silently discard configuration.
- Application rollback is safe because the nullable column may remain unused by the prior application version.

## Rule authoring contract

### Form choices

- Show active ЦФО values from the current company only.
- Use scalar `ChoiceType` values and stable UUIDs; do not use `EntityType` across the module boundary.
- Preserve duplicate display names by disambiguating labels with the stable code when necessary.
- On edit, include the currently stored archived ЦФО as a disabled/preservation choice; it cannot be newly selected.

### Save validation

| Project target | ЦФО target | Authoring result |
|---|---|---|
| `null` | `null` | allowed; existing non-pair targets remain valid |
| value | value | allowed only for an active same-company ЦФО and configured allowed pair |
| value | `null` | legacy state remains readable; an active rule cannot be newly saved this way after the cutover gate |
| `null` | value | allowed; application requires an already present compatible project |

- A forged, malformed, archived, cross-company, or disallowed target fails with a non-disclosing form/domain error.
- Existing active project-only rules are not auto-disabled or rewritten by migration.
- The owner must configure them explicitly before planner/runtime cutover.
- Rule action remains unchanged. A ЦФО target always uses safe-fill semantics even when the rule carries legacy `UPDATE`.
- Revision, actor metadata, disable-only lifecycle, CSRF, routes, and company lookup remain unchanged.

## Complete-pair application contract

### Current tuple states

| State | Stored tuple | Meaning |
|---|---|---|
| legacy empty | `null × null` | eligible for a complete winning pair |
| system | `PROJECT_GENERAL × CFO_GENERAL` | unallocated placeholder; eligible for atomic replacement |
| legacy partial | exactly one value is `null` | preserve the existing side; fill only a compatible missing side |
| assigned | both values present and not the system pair | manual/current classification wins over `FILL` |

The system pair is deliberately treated as the unallocated placeholder, not as a protected manual classification. Without a separate origin field, a manually reselected system pair is indistinguishable from the default. Adding provenance storage is rejected for v1; owner approval of this limitation is part of the Stage 7.9 gate.

### Winner resolution

- Add `responsibilityCenterId` to the existing per-field winner loop.
- Priority, specificity, immutable-ID tie-break, and same-target non-conflict semantics remain byte-for-byte equivalent.
- A rule may win project and ЦФО together, or separate rules may win each field.
- `rulesByField` records the exact rule/revision for each actual project and ЦФО change.
- Category and counterparty winners remain independent from the pair.

### Pair planning matrix

| Winning targets | Current tuple | Result |
|---|---|---|
| project + ЦФО | legacy empty or system | validate the winning pair, then change both fields atomically |
| project + ЦФО | compatible partial matching one target side | fill the missing side only; do not rewrite the matching side |
| project + ЦФО | assigned custom pair | preserve manual/current pair |
| project only | project missing, ЦФО present | fill project only if the resulting pair is active and allowed |
| project only | both sides missing or system pair | skip pair change; ЦФО cannot be inferred |
| ЦФО only | ЦФО missing, project present | fill ЦФО only if the resulting pair is active and allowed |
| ЦФО only | both sides missing or system pair | skip pair change; project cannot be inferred |
| no pair targets | any | preserve the current tuple |

### Conflict and invalid-pair behavior

- A winner/conflict is still calculated independently for each field.
- A conflict in either `projectDirection` or `responsibilityCenterId` blocks all project/ЦФО changes for that transaction; it does not block category or counterparty changes.
- After combining current protected values and winning targets, validate the complete resulting pair once.
- An incomplete, inactive, missing, cross-company, or disallowed result applies neither paired field.
- Never apply a project first and validate ЦФО later.
- Never choose the first/only ЦФО for a project.

Use stable machine-readable pair issues in the application plan/preview:

- `PAIR_CONFLICT` — at least one paired winner field conflicts;
- `PAIR_INCOMPLETE` — the proposed safe-fill result lacks one side;
- `PAIR_UNAVAILABLE` — the complete result is inactive, cross-company, missing, or not an allowed pair.

These issues describe skipped pair changes, not transaction-level skip reasons such as deleted or locked-period operations.

## Preview and UI contract

- Preview and execution must use the same pair plan and one validation snapshot.
- Add `responsibilityCenterId` to changes-by-field counts and field labels.
- Show current and resulting ЦФО in each row.
- Add a resulting-ЦФО breakdown alongside the existing resulting-project breakdown.
- Show pair issue labels separately from winner conflicts and transaction skip reasons.
- A row may show a blocked pair while still showing a valid category/counterparty change.
- Preview remains read-only and continues to limit only displayed rows, not aggregate counts.
- The modal and one-row apply path use the same plan; no new route or endpoint is added.

## Runtime validation and performance

- Rule save validation is necessary but not sufficient because a ЦФО may be archived or a pair removed later.
- Runtime planning must fail closed against a current company-scoped active-pair snapshot.
- Load the active allowed-pair snapshot once per preview/apply batch, not once per transaction and not as a process-global worker cache.
- Single-transaction apply may load one company snapshot.
- Do not introduce a Twig/repository N+1 or a long-lived mutable cache in Messenger workers.
- The restrictive FK remains the final existence guard; the known application-check/pair-removal race is deferred to Stage 7.10 hardening unless a separate composite-FK migration is approved.

## Audit and worker contract

- Persist one existing explicit `AuditLog` per actual transaction mutation.
- Add `responsibilityCenterId` before/after IDs only when it changes.
- When project and ЦФО come from different rules, preserve both rule IDs and revisions in `autoRules`.
- A blocked pair produces no pair audit entries.
- Category/counterparty audit entries remain when those independent fields change.
- Correlation IDs, message payloads, dispatch guard behavior, retry behavior, and queue routing remain unchanged.
- Do not enqueue or run historical ranges as part of deployment.

## Existing-rule migration and cutover policy

1. Run the read-only preflight before schema work; report aggregate counts only.
2. Deploy the nullable rule target column in a schema-only PR.
3. After production schema acceptance, deploy Entity mapping and authoring UI without changing matcher behavior.
4. Owners configure a ЦФО for every active project-target rule that must classify defaulted/new transactions.
5. Enforce the cutover query: active project-target rules with `responsibility_center_id IS NULL` must be zero, unless an explicitly documented rule is intentionally disabled before cutover.
6. Deploy the shared pair planner/preview/application change.
7. Verify controlled new transactions only; do not run a historical range.
8. Resume Stage 7.6.2/7.6.4 runtime and import defaults only after Stage 7.9 production acceptance.

No migration or command may guess a ЦФО target for existing rules.

## Staged implementation

### Stage 7.9.1a — Schema-only expand

**Risk:** HIGH (database migration)

**Result:** production has the nullable rule-target column before any application version maps or reads it.

Expected work:

- additive guarded migration;
- migration syntax, empty-database, upgrade, and rollback-guard tests;
- no Entity mapping, form, controller, Twig, matcher, preview, worker, or transaction changes in the schema PR.

The split is mandatory because the production workflow deploys application images before its migration job. Mapping the new column in the schema PR would create an `undefined column` rolling-deployment window.

Mandatory STOP before running the migration locally and again after green self-review.

### Stage 7.9.1b — Scalar mapping and authoring

**Risk:** HIGH (rule authoring contract)

**Result:** after production schema acceptance, rules can store and edit an optional company-valid ЦФО target while matcher/worker behavior remains unchanged.

Expected work:

- scalar Entity mapping and accessors;
- facade-backed active/current choices;
- server-side company/active/pair validation;
- list/form display and focused Entity/controller/migration tests;
- no matcher, preview result, worker, or transaction changes.

Mandatory STOP after green self-review and production acceptance.

### Stage 7.9.2 — Existing-rule configuration gate

**Risk:** HIGH for production configuration; read-only inventory is LOW

**Result:** active project-target rules are explicitly paired before runtime semantics change.

Expected work:

- run reviewed aggregate preflight;
- owner configures rules through the protected UI;
- rerun zero-unpaired-active-project-rule gate;
- no SQL writes, auto-disable, inference, or history run by Codex without immediate explicit approval.

Mandatory STOP for owner-controlled production configuration.

### Stage 7.9.3 — Shared planner, preview, and apply cutover

**Risk:** HIGH (financial classification semantics)

**Result:** preview, manual apply, and worker share atomic complete-pair safe-fill behavior.

Expected work:

- fourth winner field and coupled pair planner;
- one company active-pair snapshot per operation/batch;
- application-plan pair issue and ЦФО provenance;
- preview/modal/list labels and breakdown;
- focused unit, integration, functional, audit, and handler regression tests;
- no writer defaults or history dispatch.

Mandatory STOP after green self-review and controlled production acceptance.

### Stage 7.9.4 — Resume Stage 7.6 cutover

**Risk:** HIGH

**Result:** Stage 7.6.2/7.6.4 may assign the system pair without pausing complete configured auto-rules.

This is not Stage 7.9 implementation work. It returns to the separately approved Stage 7.6 writer/import plan and retains its own gates.

## Expected change areas

- `site/migrations/Version*.php` — additive nullable rule target, index, FK, guarded rollback.
- `site/src/Cash/Entity/Transaction/CashTransactionAutoRule.php` — scalar target mapping and validation surface.
- `site/src/Cash/Form/Transaction/CashTransactionAutoRuleType.php` — scalar ЦФО choice.
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php` — facade choices and authoritative save validation.
- `site/src/Cash/Application/DTO/CashTransactionAutoRuleApplicationPlan.php` — ЦФО result/provenance and pair issue.
- `site/src/Cash/Application/DTO/CashTransactionAutoRulePreviewResult.php` — ЦФО counts/breakdown.
- `site/src/Cash/Service/Transaction/CashTransactionAutoRuleService.php` — fourth winner and atomic pair planning.
- `site/src/Company/Facade/FinancialResponsibilityCenterFacade.php` and scalar DTO/query methods only if the current facade cannot provide one batch snapshot.
- existing rule form/index/check/modal Twig templates.
- existing auto-rule unit/integration/functional suites.
- `ARCHITECTURE.md` and Stage reports after approved implementation units.

This is an inspection map, not permission to change all files in one PR.

## Required checks

### Schema and authoring

- empty-database migration and upgrade from Stage 7.5/7.6 schema;
- rollback succeeds while target column is empty and refuses data loss when populated;
- nullable existing rules remain unchanged;
- active/current choices are company-scoped and preserve duplicate display names;
- malformed, archived, cross-company, and disallowed targets fail;
- project + ЦФО authoring validates the exact allowed pair;
- optimistic revision and actor metadata still advance once per committed edit.

### Planner

- independent ЦФО winner uses existing priority/specificity/ID ordering;
- same-target contenders do not conflict; different equal-ranked targets do;
- complete target pair fills legacy empty and replaces the system pair atomically;
- manual custom pair wins over `FILL`;
- compatible legacy partial tuple fills only the missing side;
- project-only and ЦФО-only targets require the compatible other side;
- pair conflict/incomplete/unavailable blocks both paired changes but not category/counterparty;
- archived/removed targets fail closed at execution time;
- no query per transaction in preview/batch;
- no mutation during preview.

### Preview, audit, and handlers

- current/resulting ЦФО, field counts, breakdown, conflicts, and pair issues render correctly;
- project and ЦФО changes contain exact per-field rule ID/revision provenance;
- no pair audit for blocked/no-change plans;
- one audit and one flush for an actual complete-pair change;
- dispatch guard prevents duplicate generic audit;
- manual one-row apply and Messenger handler use the same result;
- existing message compatibility tests remain unchanged.

### Standard

- targeted PHP CS Fixer and Twig lint;
- focused unit/integration/functional tests;
- `make site-test-unit`;
- relevant full integration suite;
- Symfony container lint and Doctrine schema validation;
- empty-database migration CI;
- `git diff --check` and complete scope/security/no-debug/N+1 review.

## Must not change

- existing rule priority, specificity, immutable-ID tie-break, category/counterparty safe-fill, conflict, revision, or correlation semantics;
- rule conditions or candidate generation;
- legacy `UPDATE` into overwrite behavior;
- existing transaction classifications through migration, backfill, history range, repair, or recalculation;
- Cash writer/import defaults before Stage 7.9 acceptance;
- documents, document operations, P&L totals, formulas, signs, periods, or reports;
- routes, auth/RBAC, voters, CSRF, Messenger routing/transports/workers/cron;
- dependencies, production configuration, or a new frontend framework;
- inference from project/ЦФО names, ordering, or first match;
- long-lived worker caches or per-transaction pair queries in preview/batch.

## Owner decisions required

1. Confirm that the exact system pair is an unallocated placeholder eligible for atomic auto-rule replacement, even if a user manually reselected it.
2. Confirm that a conflict in either project or ЦФО blocks both paired changes while independent category/counterparty changes continue.
3. Confirm that existing active project-only rules must be explicitly configured before planner/writer cutover; no automatic target inference or disable is allowed.
4. Stage 7.9.1a migration creation and application to local `app_test` are approved and complete. Any non-local application retains a separate immediate approval gate.

## Phase 0 checks

- Current-schema aggregate preflight section A executed successfully against local `app_test` (three read-only queries; zero local rule rows).
- The Stage 7.9.1a migration passed local `up -> down -> up`; the final local state is `up`.
- The nullable UUID column, index, restrictive FK, empty rollback, and configured-data rollback guard were verified.
- `git diff --check` and documentation whitespace/scope review — passed.

## Phase 0 self-review

- [x] Scope limited to planning/read-only preflight
- [x] `ARCHITECTURE.md`, `PATTERNS.md`, Stage 7.6, and current auto-rule paths inspected
- [x] Existing category/project/counterparty target patterns compared
- [x] Company isolation and module boundary defined
- [x] Migration, rollback, N+1, audit, preview, worker, and cutover risks identified
- [x] No application code, migration, production write, rule edit, or historical run performed
