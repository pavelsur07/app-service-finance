# Cash auto rules: review and improvement plan

**Status:** review complete; owner-approved six-point safety scope implemented in this branch
**Prepared:** 2026-07-15 UTC
**Scope:** deterministic classification of Cash transactions by cashflow category and project; assessment of readiness for CFO allocation
**Excluded from implementation:** AI/ML classifiers, production mutations, CFO model, durable provenance, and historical reprocessing

## 1. Executive summary

The module has a workable deterministic rule foundation, company scoping, exact counterparty matching, a preview screen, asynchronous processing, and a safe fallback category (`CF_UNALLOC`). Production references from rules to categories, projects, and counterparties do not cross company boundaries.

The current implementation is nevertheless unsafe for systematic financial classification:

1. The first matching rule wins, but rules are ordered by `name`, not by priority or specificity. Production already contains transactions matching two rules.
2. `UPDATE` overwrites manually assigned values and also clears project/counterparty when the corresponding rule target is `null`.
3. `FILL` treats `CF_UNALLOC` as an already filled category. Therefore a later rule cannot classify an operation previously placed in “Unallocated”. Widespread use of `UPDATE` appears to be a workaround for this behavior.
4. Automatic application can produce duplicate and amplified queue work: creation/update already dispatches messages, the Doctrine subscriber dispatches a day range on every `postPersist` and `postUpdate`, and the current debouncer returns `true` both on cache miss and cache hit.
5. The worker changes financial analytics directly, bypassing the finance-period lock enforced by `CashTransactionService`.
6. The range worker includes soft-deleted transactions, although the preview excludes them.
7. Preview and actual matching are separate implementations and can disagree. The preview also does not show which rule will win a conflict.
8. The system does not have a distinct CFO dimension. `ProjectDirection` must not be silently treated as CFO without an approved business model.

The first implementation objective should be safety and determinism, not adding more condition types or automatic coverage.

## 2. Evidence and scope of inspection

### Repository flow inspected

- `CashTransactionAutoRule` and `CashTransactionAutoRuleCondition` entities;
- rule repository, matcher, and application service;
- create/edit/check/match/apply controllers and forms;
- single-transaction and range Messenger handlers;
- Doctrine auto-rule subscriber and debounce service;
- transaction create/update flow and finance-period checks;
- transaction audit subscriber;
- existing Cash auto-rule tests and migrations;
- Cash module and repository architecture documentation.

### Production checks

Only approved read-only paths were used:

- `sudo /usr/local/bin/codex-docker-ps`;
- `sudo /usr/local/bin/codex-console messenger:stats`;
- aggregate `SELECT` queries through `sudo /usr/local/bin/codex-psql-ro`.

No company names, UUIDs, counterparties, descriptions, INNs, payment purposes, or transaction-level records are stored in this document. Company aliases below are ordered by the number of non-deleted transactions in the last 365 days.

One aggregate audit-log query was stopped after it proved too expensive for a production review. No further audit-log scans are required before implementation; provenance gaps are already established from the schema and code.

## 3. Production snapshot

Snapshot date: 2026-07-15 UTC.

### Overall

| Metric | Value |
|---|---:|
| Companies in database | 1,343 |
| Companies with Cash transactions | 4 |
| Companies with transactions in the last 365 days | 4 |
| Non-deleted transactions in the last 365 days | 4,130 |
| Companies with auto rules | 3 |
| Active companies with both recent transactions and rules | 2 |
| Auto rules | 104 |
| `UPDATE` rules | 75 |
| `FILL` rules | 29 |
| Rule conditions | 104 |
| Rules with exactly one condition | 104 |
| Rules with multiple conditions | 0 |
| Exact-counterparty conditions | 56 |
| Description-contains conditions | 48 |
| Soft-deleted transactions in the last 365 days | 58 |
| Rules with duplicate names (excess entries) | 43 |
| Exact duplicate rule definitions | 0 |
| Cross-company rule references | 0 |

At the time of inspection all Messenger transports were empty; the `failed` transport contained two messages. They were not inspected because their relation to Cash auto rules was not established through the permitted aggregate checks.

### By anonymized company

| Company | Transactions, 365d | Unallocated | Missing project | Missing counterparty | Rules | Rule profile | Multiple matching rules |
|---|---:|---:|---:|---:|---:|---|---:|
| Company-01 | 2,716 | 0 | 2,716 | 9 | 0 | no rules | 0 |
| Company-02 | 845 | 16 | 13 | 213 | 75 | 75 `UPDATE`; 73 set project; 55 set counterparty | 38 |
| Company-03 | 567 | 32 | 526 | 0 | 28 | 28 `FILL`; no rule sets project | 15 |
| Company-04 | 2 | 0 | 0 | 0 | 0 | no rules | 0 |
| Company-05 | 0 | n/a | n/a | n/a | 1 | inactive-data rule | n/a |

Additional matching results for the last 365 days:

- Company-02: 110 transactions match no rule, 697 match one rule, and 38 match two rules.
- Company-03: 101 transactions match no rule, 451 match one rule, and 15 match two rules.
- Company-01 and Company-04 have no matching rules.
- Of all 4,130 non-deleted transactions, 3,926 have no `import_source`; 204 are marked `telegram`. `NULL` currently combines manual and legacy/imported data, so source lineage cannot be used reliably.

The missing-project counts are not automatically defects: the business may not require projects for every company or transaction type. This must be decided before enforcing project completeness.

## 4. What is implemented well

- Rule lookup is scoped by transaction company.
- Controller reads validate the active company before returning or mutating rule/transaction data.
- Production contains no cross-company category, project, or counterparty references from rules.
- Conditions inside one rule use `AND`, which is predictable.
- Counterparty identity is available as an exact condition; this is safer than name matching.
- Description matching is case-insensitive and normalizes `ё`/`е` consistently in the runtime matcher.
- Amount comparison in the runtime matcher uses decimal comparison rather than binary float.
- `FILL` exists as a non-destructive action.
- An explicit system “Unallocated” category prevents silent guessing.
- Rule preview exists and excludes soft-deleted transactions.
- Messages include both transaction and company identifiers, and the handler rejects company mismatch.
- Transaction changes are included in the general audit mechanism, although rule provenance is missing.
- Rule execution is asynchronous and the current production volume does not justify a complex rules platform.

These pieces should be retained and tightened rather than replaced with a new rules engine or third-party dependency.

## 5. Findings and risks

### P0 — First-match ordering depends on the rule name

`CashTransactionAutoRuleService::findMatchingRule()` returns the first match. `CashTransactionAutoRuleRepository::findByCompany()` orders only by `r.name ASC`.

Consequences:

- renaming a rule can change historical classification behavior;
- duplicate names have no stable secondary order;
- general rules can shadow specific rules;
- production already has 53 recent transactions matching two rules;
- the preview of an individual rule does not reveal that another rule wins first.

Risk: incorrect category/project/counterparty assignment with no explicit conflict signal.

### P0 — `UPDATE` can destroy manual or imported analytics

For `UPDATE`, the service always synchronizes all three targets: category, project, and counterparty. A `null` project or counterparty on the rule clears the corresponding transaction value.

Production exposure:

- 75 of 104 rules are `UPDATE`;
- Company-02 uses `UPDATE` for all 75 rules;
- 2 `UPDATE` rules have no target project;
- 20 `UPDATE` rules have no target counterparty;
- 735 recent Company-02 transactions match at least one `UPDATE` rule.

Any manual correction to a still-matching transaction can be reverted by the asynchronous worker. The audit diff does not state which rule caused the overwrite.

### P0 — `FILL` cannot replace “Unallocated”

The handler assigns `CF_UNALLOC` when no category exists. Later, `FILL` checks only for `null`, so a newly added rule cannot classify that transaction during a range rerun.

This makes the advertised six-month reprocessing flow ineffective for previously unallocated transactions unless users switch to destructive `UPDATE`. Company-03 currently has 28 `FILL` rules and 32 unallocated transactions, illustrating the operational impact.

### P0 — Ineffective debounce and duplicate dispatch paths

`DebouncedRangeEnqueuer::shouldEnqueueCompanyDay()` caches and returns `true`. On a cache hit it reads the same `true`, so duplicate events are not rejected.

At the same time:

- `CashTransactionService::add()` and `update()` dispatch a single-transaction message;
- `CashTransactionAutoRulesSubscriber` dispatches a day-range message on every persist/update;
- applying a rule flushes the transaction and triggers `postUpdate` again;
- the range message then creates one message per transaction for that day.

This is finite when the second application makes no changes, but it amplifies queue traffic and database reads. The subscriber also passes an associative array where the message contract requires a list of account IDs.

### P0 — Finance-period lock is bypassed

Manual transaction changes go through `CashTransactionService::assertNotLockedForCompany()`. The auto-rule worker mutates entities directly and flushes without checking `finance_lock_before`.

No company with current rules had a lock configured at the time of review, but enabling a lock would not protect historical analytics from bulk auto-rule application.

### P1 — Soft-deleted transactions are included in bulk application

The range handler has no `deletedAt IS NULL` predicate and the single-transaction handler does not reject a deleted transaction. There are 58 soft-deleted transactions in the reviewed period. Preview and execution therefore operate on different sets.

### P1 — Manual apply accepts a non-matching rule

The POST action validates that rule and transaction belong to the active company, but when `ruleId` is supplied it does not verify that the rule currently matches the transaction. A same-company rule can therefore be applied to an unrelated transaction by a modified request.

This is an integrity issue, not an IDOR issue.

### P1 — Runtime matcher and preview duplicate semantics

The runtime matcher uses PHP and decimal comparison; preview rebuilds conditions in Doctrine SQL and converts amounts to `float`. INN normalization also differs. Any future condition change must be implemented twice.

Risk: preview says a transaction matches while the worker does not, or vice versa.

### P1 — Dimensions cannot be classified independently

One winning rule controls category, project, and counterparty together. Category is mandatory on every rule. A category-only rule that wins prevents a later project-specific rule from running.

Best-practice deterministic behavior is a cascade per target field:

- category may be resolved with high confidence;
- project may remain unallocated or be resolved by a different rule;
- counterparty enrichment must not be coupled to category replacement.

### P1 — No rule lifecycle or governance

Rules have no:

- enabled/disabled state;
- explicit priority;
- effective-from/effective-to dates;
- created/updated timestamps and actor;
- safe draft mode;
- conflict status;
- explanation of why a rule won.

Deletion is currently the only way to disable a rule. This is poor auditability for financial logic.

### P1 — Weak validation of conditions

The form exposes every operator for every field, but the matcher silently interprets unsupported combinations through default branches. Entity/form validation does not require:

- a counterparty for `COUNTERPARTY`;
- a non-empty value for text conditions;
- valid decimal/date values;
- `valueTo` for `BETWEEN`;
- lower bound not exceeding upper bound;
- at least one condition;
- category/project/counterparty targets from the same company at the domain boundary.

Production rules currently use only valid `COUNTERPARTY/EQUAL` and `DESCRIPTION/CONTAINS` pairs, but invalid rules can still be created later.

### P1 — Audit lacks assignment provenance

The general transaction audit can show changed fields and a system actor, while the application log contains `ruleId` and `ruleName`. These records are not linked durably. It is not possible to answer reliably from transaction history:

- which rule assigned each field;
- whether the previous value was manual, imported, or automatic;
- which rule version was used;
- whether the result came from a conflict;
- why a value was cleared.

### P2 — Deterministic signals are too limited

Useful non-AI signals are missing from rule conditions:

- money account;
- currency;
- import source;
- transfer flag;
- document type/number;
- exact normalized purpose fragments with include/exclude support;
- stable external source attributes where present.

Description-only matching is fragile and should normally be combined with direction, account, counterparty/INN, or source. Conditions should not be expanded until conflicts and destructive updates are fixed.

### P2 — Source lineage is weak

About 95% of current non-deleted transactions have no `import_source`. This does not mean they are all manual; the value currently conflates manual and legacy/imported origins. Deterministic rules cannot safely distinguish sources until ingestion lineage is normalized.

### P2 — Processing is query-heavy

Every transaction message loads all company rules and evaluates them in PHP. Range processing emits one message per transaction. With current volume this is acceptable after duplicate dispatch is fixed; no caching or custom DSL is justified yet. Add performance work only after measuring the corrected flow.

## 6. Target deterministic behavior

### 6.1 Precedence

For each target field independently:

1. Explicit manual value or approved source value, unless the user explicitly requests forced recalculation.
2. Exact stable mapping: account/source/external document/counterparty identity.
3. Composite deterministic rule with explicit priority.
4. Safe fallback: `CF_UNALLOC` for category, `null`/“not assigned” for optional dimensions.

No rule should silently overwrite a manual value. A force action, if retained, must be per field, explicit, auditable, previewed, and unavailable for locked periods.

### 6.2 Matching and conflicts

- Evaluate all active rules applicable on the transaction date.
- Order by explicit priority, then specificity, then immutable ID as a stable tie-breaker.
- Resolve independently for category, project, and counterparty.
- If equal-priority rules assign different values to the same field, mark a conflict and do not auto-apply that field.
- `CF_UNALLOC` counts as empty for safe category filling.
- A `null` rule target means “do not change this field”, not “clear it”. Clearing requires an explicit action.
- Internal transfers use dedicated deterministic handling and should not fall through general inflow/outflow rules.

### 6.3 Rule lifecycle

Minimum fields:

- `isActive`;
- integer `priority`;
- optional `effectiveFrom` and `effectiveTo`;
- created/updated timestamps;
- target-field actions (`KEEP`, `FILL`, optionally `FORCE_SET`, explicitly `CLEAR`).

Avoid building a generic expression language. Existing typed conditions with validated field/operator pairs are enough.

### 6.4 Preview and audit

One matcher must power both worker and preview. Preview must show:

- matched transaction count and amount by currency;
- proposed changes per field;
- values that would be preserved;
- conflicts and the competing rules;
- locked/deleted transactions that will be skipped;
- before/after totals;
- whether the run is new-only or historical.

For every actual change, retain durable provenance:

- transaction ID;
- rule ID and rule revision/version;
- changed field;
- previous and new IDs/values;
- system/user actor;
- timestamp and run/message correlation ID.

Reuse the current audit infrastructure where practical; do not introduce a separate event-sourcing platform.

## 7. CFO decision

There is no CFO/cost-center entity or transaction field in the current domain. `ProjectDirection` is a project hierarchy, not a proven CFO model.

Before implementation, choose one of these models:

1. **Derived CFO — preferred when valid:** every project belongs to exactly one CFO. Store the mapping on project/master data and derive CFO in reports; do not duplicate CFO on every Cash transaction.
2. **Independent CFO:** a transaction can have a CFO unrelated to project. Add a separate company-scoped CFO master-data dimension and a transaction reference. This requires a HIGH-risk schema and migration stage.

Do not add CFO to auto rules until ownership, hierarchy, effective dates, archived values, and project relationship are approved.

One-to-many allocation of a transaction across projects/CFOs is also not supported by the current rule entity. If required, treat it as a separate accounting-allocation task; do not mix it into the classification safety work.

## 8. Staged implementation plan

### Stage 1 — Characterization and safety tests

**Risk:** LOW
**Result:** executable specification of current defects; no production behavior change.

- Add unit tests for deterministic ordering, overlapping rules, `FILL` with `CF_UNALLOC`, and `UPDATE` null-target clearing.
- Add integration tests proving manual edits can currently be overwritten.
- Add tests for deleted and locked transactions in handler/range flows.
- Add a test exposing the current debounce cache-hit behavior.
- Add controller test proving supplied `ruleId` is not revalidated.
- Record current production aggregates as anonymized review evidence only; do not add production fixtures.

Checks:

- focused PHPUnit suites for Cash auto rules;
- `make site-test-unit` or Codex Cloud equivalents;
- `git diff --check`.

### Stage 2 — Queue and mutation safety

**Risk:** HIGH
**STOP:** owner review required before and after implementation because financial classification behavior changes.

- Fix debounce to return `false` on a cache hit.
- Select one automatic dispatch path for creation/update; remove duplicate range fan-out behavior without changing Messenger transport configuration.
- On update, enqueue only when matcher input fields changed, not when category/project output fields changed.
- Pass a real list of account IDs.
- Exclude soft-deleted transactions in range and single handlers.
- Enforce finance-period lock in the auto-rule application service.
- Revalidate that a manually supplied rule matches before applying it.
- Make message handling idempotent and ensure rule application does not enqueue itself again.

Acceptance checks:

- one transaction change produces at most one intended application path;
- repeated identical messages cause no data change and no range re-enqueue;
- deleted and locked transactions remain unchanged;
- no edits to `config/packages/messenger.yaml`, transports, workers, cron, or production config.

### Stage 3 — Rule governance schema

**Risk:** HIGH
**STOP:** owner approval required before creating or running a migration.

- Add active state, priority, effective dates, and timestamps.
- Add validation and company-consistency guards at the domain/form boundary.
- Define stable tie-breaking independent of names.
- Add a non-destructive disable action in UI.
- Keep existing rules active initially; do not bulk-convert semantics in the migration.
- Add indexes for active company/date/priority lookup only if justified by the query plan.

Migration requirements:

- additive and non-destructive;
- safe defaults for all 104 existing rules;
- reversible schema change;
- no automatic production migration execution.

### Stage 4 — Deterministic per-field matcher

**Risk:** HIGH
**STOP:** owner review required because existing classifications can change.

- Introduce one matcher result containing all matches, winners, and conflicts.
- Resolve category, project, and counterparty independently.
- Treat `CF_UNALLOC` as empty for safe category fill.
- Change `null` targets to “leave unchanged”.
- Retain forced overwrite/clear only as explicit per-field actions if the owner confirms a business need.
- Use explicit priority, specificity, and immutable ID ordering.
- Skip conflicting fields instead of guessing.
- Use the same matcher from preview, manual apply, and worker.

Existing-rule transition:

- review all 75 `UPDATE` rules before enabling new semantics;
- pay special attention to the 2 rules with no project target and 20 with no counterparty target;
- resolve the 38 Company-02 and 15 Company-03 current overlap cases;
- do not rewrite historical transactions during deployment.

### Stage 5 — Exact preview and durable provenance

**Risk:** HIGH
**STOP:** owner review required if schema or public route/response behavior changes.

- Extend the existing check flow rather than creating a second preview engine.
- Show winner, competing rules, and field-level before/after values.
- Add dry-run statistics by company, period, currency, category, and project.
- Persist rule ID/revision and field-level changes through the existing audit approach or a minimal dedicated application record.
- Add correlation IDs to range runs and child transaction messages.
- Do not log descriptions, INNs, account numbers, or raw bank payloads.

### Stage 6 — Add high-value deterministic signals

**Risk:** MEDIUM, or HIGH if existing rules are reinterpreted
**Next action:** continue only after Stages 2–5 are stable.

- Add validated conditions for money account, currency, import source, transfer flag, and document type.
- Add negative text condition only if a concrete rule needs it.
- Prefer exact counterparty/INN and source identifiers over counterparty-name contains.
- Normalize `import_source` for new ingestion paths; keep legacy/null explicit.
- Provide a rule-candidate report from repeated confirmed history, but require user approval to create a rule. This is deterministic aggregation, not ML.
- Do not auto-create rules from history.

### Stage 7 — CFO model

**Risk:** HIGH
**STOP:** separate owner-approved task required.

- Approve derived-vs-independent CFO model.
- Define company ownership, hierarchy, archival behavior, and effective dates.
- If independent, add the CFO master data and Cash transaction reference through an additive migration.
- Add CFO rule target only after the master data is established.
- If split allocation is required, design it separately with sum and rounding invariants.

### Stage 8 — Controlled production rollout

**Risk:** HIGH
**STOP:** explicit owner approval immediately before every mutating production command.

- Deploy code and schema separately.
- Run read-only dry-run reports per company and period.
- Start with new transactions only and safe `FILL` behavior.
- Compare conflict, skip, and change counts against approved expectations.
- Convert existing `UPDATE` rules company by company after review.
- Reprocess history only for an explicitly approved period and company.
- Never run a full historical queue enqueue as a deployment side effect.
- Verify queue counts, failures, audit records, category/project completeness, and financial report totals after each company rollout.

## 9. Required test matrix

### Matcher

- direction `INFLOW`, `OUTFLOW`, and `ANY`;
- exact counterparty and normalized text matching;
- valid date/amount boundaries without floats;
- multiple `AND` conditions;
- explicit priority and stable tie-break;
- equal-priority conflict;
- independent category/project/counterparty winners;
- `CF_UNALLOC` treated as empty;
- manual values preserved;
- explicit force/clear behavior if retained;
- inactive and out-of-period rules skipped;
- internal transfer behavior.

### Security and integrity

- rule, category, project, and counterparty belong to the same company;
- manual apply rejects a non-matching rule;
- deleted transaction skipped;
- locked-period transaction skipped;
- no raw/PII data in logs;
- CSRF and active-company checks remain intact.

### Messaging and idempotency

- cache miss enqueues once, cache hit does not;
- output-only update does not enqueue another range;
- duplicate single-transaction message is a no-op;
- range query excludes deleted records and respects account/date filters;
- no N+1 regression when evaluating rules;
- audit/application record written exactly once per actual field change.

### Preview parity

- preview and worker return identical match/winner/conflict results;
- amount comparison remains decimal-safe;
- locked/deleted items are shown as skipped, never as applicable;
- preview totals reconcile by currency.

## 10. Expected change areas

Likely files/areas, subject to stage approval:

- `site/src/Cash/Service/Transaction/CashTransactionAutoRuleService.php`;
- `site/src/Cash/Repository/Transaction/CashTransactionAutoRuleRepository.php`;
- `site/src/Cash/Entity/Transaction/CashTransactionAutoRule.php`;
- `site/src/Cash/Entity/Transaction/CashTransactionAutoRuleCondition.php`;
- `site/src/Cash/MessageHandler/ApplyAutoRulesForTransactionHandler.php`;
- `site/src/Cash/MessageHandler/EnqueueAutoRulesForRangeHandler.php`;
- `site/src/Cash/EventSubscriber/Transaction/CashTransactionAutoRulesSubscriber.php`;
- `site/src/Cash/Application/Service/DebouncedRangeEnqueuer.php`;
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php`;
- Cash auto-rule forms/templates;
- focused tests under `site/tests/Unit/Cash` and `site/tests/Integration/Cash`;
- additive Doctrine migration(s) only in approved HIGH-risk stages;
- `ARCHITECTURE.md` when rule contract or CFO model is approved.

## 11. Must not change in this task

- cashflow formulas, signs, periods, or report semantics without separate approval;
- existing category/project/CFO business mappings by assumption;
- Messenger transports, workers, routing, cron, or production config;
- production records or queues during analysis/Phase 0;
- auth, roles, voters, or company access rules;
- ingestion integrations or live external APIs;
- transaction splitting/allocation unless separately specified;
- unrelated Cash, Finance, or Company modules;
- dependencies.

## 12. Owner decisions required

1. Is project mandatory for Company-01, Company-02, and Company-03, and for which operation types?
2. Must a manual category/project/counterparty always win over an auto rule?
3. Is forced overwrite needed at all? If yes, who may enable it and for which fields?
4. Should `CF_UNALLOC` always be considered empty for reclassification?
5. How should internal transfers be categorized and should they ever receive projects/CFOs?
6. Is CFO derived from project or independent from project?
7. Are split allocations across several projects/CFOs required, or is one value per transaction sufficient?
8. Should historical reprocessing be allowed in an open period only, or also through a separately approved override?
9. Which source systems should populate `import_source` for future transactions?

## 13. Recommended next action

Approve **Stage 1 only** to add characterization and safety tests. After the test evidence is reviewed, approve Stage 2 as a separate HIGH-risk implementation unit. Do not add more rules or expand automatic coverage until destructive overwrites, conflict ordering, reprocessing of `CF_UNALLOC`, and queue amplification are fixed.
