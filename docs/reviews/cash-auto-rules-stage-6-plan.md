# Cash auto rules — Stage 6 plan

## Status

- Phase: **Phase 0, Stage 6.1, and owner-approved Stage 6.2 complete; Stage 6.3 business-definition gate reached**
- Overall risk: **MEDIUM**, with explicit HIGH-risk and business-definition gates below
- Production mutations: **out of scope**
- Historical recalculation: **forbidden**

## Goal

Extend the existing deterministic matcher with high-value exact transaction signals without changing the meaning of existing rules, introducing a second matcher, or creating rules automatically from history.

Stage 6 covers:

- exact currency, import-source, transfer-flag, and document-type conditions;
- an exact money-account condition after a separately approved schema change;
- explicit treatment of missing legacy/import lineage;
- a read-only rule-candidate report after its business thresholds are approved;
- no negative-text operator until a concrete production rule requires it.

## Current implementation and reusable patterns

### Existing auto-rule model

- `CashTransactionAutoRuleCondition` stores a typed field/operator plus a generic string value and validates the allowed operator matrix.
- `CashTransactionAutoRuleService::ruleMatchesTransaction()` is the single matcher used by preview, manual application, and workers.
- The condition form already limits exact counterparty choices to the active company and the Stimulus controller mirrors the server-side operator matrix.
- Existing persisted rules use only the current enum cases. Adding enum cases does not reinterpret their stored values.

### Similar project patterns inspected

1. Exact counterparty conditions use a stable entity identity and enforce company ownership at the domain boundary.
2. Amount/date conditions reuse the generic `value` columns with field-specific validation and matching rather than adding a condition DSL.
3. Cash transaction and payment-plan forms populate `MoneyAccount` choices from the active company; the same company-scoped choice pattern is required for a future account condition.

No existing rule-candidate report defines what counts as repeated, confirmed classification history. Those thresholds are business rules and must not be inferred from unrelated reports.

## Design decisions

### 1. Preserve all existing rule semantics

New condition fields are additive enum cases. Existing fields, operators, priority, specificity, conflict handling, safe-fill behavior, preview, and audit provenance remain unchanged.

Every new field uses `EQUAL`. No generic `NOT_CONTAINS`, OR groups, nested expressions, or third-party rules engine is added.

### 2. Implement scalar exact signals with the existing value column

The first implementation unit adds:

- `CURRENCY`: uppercase three-letter code;
- `IMPORT_SOURCE`: exact non-empty source identifier;
- `IS_TRANSFER`: canonical `true` or `false`;
- `DOCUMENT_TYPE`: exact normalized text.

Missing `import_source` remains explicit through a reserved, documented form value rather than being silently equal to an empty string. Existing transaction source data is not rewritten.

### 3. Keep money-account identity relational

Money account is company-owned master data. A raw UUID in the generic value column would be difficult to use and would lack a database foreign key. The preferred implementation adds a nullable `MoneyAccount` association to conditions, mirroring exact counterparty conditions.

This requires an additive migration and is therefore a separate HIGH-risk unit with a mandatory owner review before the migration is created or run.

### 4. Do not normalize legacy source data in place

Current source values include fixed application identifiers (`file`, `telegram`) and bank connector codes; `NULL` also combines manual and legacy data. Stage 6.1 only matches the stored lineage exactly.

Normalization is required when a new ingestion path is introduced, at that path's boundary and before deduplication. No current importer, uniqueness key, or historical row is changed in Stage 6.

### 5. Candidate generation remains read-only and approval-based

The future candidate report may aggregate repeated stable signals and already assigned category/project/counterparty values, but it must never persist a rule or enqueue application work.

Implementation is blocked until the owner defines:

- minimum repetition/sample count and analysis period;
- what proves a historical assignment is confirmed rather than automatic;
- which target fields may become candidates;
- acceptable consistency/confidence threshold and tie behavior.

## Staged implementation

### Stage 6.1 — Scalar exact conditions

**Risk:** MEDIUM

**Result:** exact currency, import-source, transfer-flag, and document-type conditions work identically in preview and execution.

**Next action after green self-review:** continue to the mandatory money-account/candidate-report gate.

Work:

1. Add four condition-field enum cases.
2. Restrict all four to `EQUAL` in domain validation and the form controller.
3. Add field-specific canonical value validation.
4. Add matcher branches using current transaction properties.
5. Add concise form labels/placeholders for the canonical inputs.
6. Add unit tests for validation, matching, AND composition, and non-matches.
7. Update Cash architecture documentation.

Acceptance criteria:

- Existing rules match exactly as before.
- New conditions use the shared matcher, so preview and worker cannot diverge.
- Invalid currency, source, boolean, document type, or operator combinations are rejected.
- Missing source is explicit and cannot be confused with an omitted condition value.
- No database migration, public route, response contract, queue configuration, or historical processing is introduced.

### Stage 6.2 — Exact money-account condition

**Risk:** HIGH (database migration)

**Status:** DONE; owner approved migration creation and local test execution

**Result:** a rule can select one company-owned money account through an entity choice and match by stable identity.

**Next action:** **STOP for owner review before deployment or Stage 6.3.**

Completed work:

1. Add a nullable `MoneyAccount` association and safe foreign key/index to the condition entity.
2. Add an additive Doctrine migration; no existing condition row is rewritten.
3. Pass active-company accounts to the rule form and reject cross-company references.
4. Add exact identity matching and persistence/company-isolation tests.

### Stage 6.3 — Rule-candidate report

**Risk:** MEDIUM after business contract approval

**Result:** a bounded, read-only report proposes deterministic candidates for human review.

**Next action:** **STOP until the four business parameters in Design decision 5 are supplied.**

The report must not create rules, mutate transactions, dispatch messages, or expose transaction descriptions/INNs in logs.

### Stage 6.4 — Final hardening and handoff

**Risk:** MEDIUM locally; HIGH for production acceptance

Work:

1. Run the full relevant local checks and review the complete Stage 6 diff.
2. Verify old rule fixtures and persisted enum values remain compatible.
3. Deploy only after review of any migration-bearing unit.
4. Production verification remains read-only unless the owner gives immediate approval for a specific mutation.
5. Do not enqueue historical ranges as part of deployment or acceptance.

## Expected change areas

### Stage 6.1

- `site/src/Cash/Enum/Transaction/CashTransactionAutoRuleConditionField.php`
- `site/src/Cash/Entity/Transaction/CashTransactionAutoRuleCondition.php`
- `site/src/Cash/Form/Transaction/CashTransactionAutoRuleConditionType.php`
- `site/src/Cash/Service/Transaction/CashTransactionAutoRuleService.php`
- `site/assets/controllers/auto_rule_conditions_controller.js`
- focused condition-validation and matcher tests
- `ARCHITECTURE.md`

### Stage 6.2 after approval

- condition entity/form and rule controller form options
- additive Doctrine migration
- focused unit, integration, and functional tests

### Stage 6.3 after business contract approval

- one focused read model/query and presentation entry point, selected during that substage's inspection
- focused aggregation, access-boundary, and no-write tests

## Required checks

### Stage 6.1

- targeted condition validation tests;
- targeted matcher tests;
- frontend lint/build check covering the Stimulus change, if exposed by the Makefile;
- Symfony container lint;
- targeted PHP CS check;
- `make site-test-unit`;
- `git diff --check`.

### Stage 6.2 after approval

- Doctrine schema validation;
- migration SQL review for additive-only behavior;
- condition persistence and cross-company rejection tests;
- relevant functional form tests.

### Final

- full relevant test set;
- `git status --short` and `git diff --stat`;
- complete scope/security/no-debug self-review.

## Must not change

- existing condition/operator meanings;
- priority, specificity, conflict, or per-field winner semantics;
- safe-fill/manual-value behavior;
- preview/application/audit contracts from Stages 4–5;
- current import-source values, unique constraints, or deduplication behavior;
- current transaction classifications or historical data;
- Messenger transports, routing, workers, cron, or production configuration;
- public routes or APIs;
- CFO model or target fields;
- automatic rule creation;
- negative text matching without an approved concrete case.
