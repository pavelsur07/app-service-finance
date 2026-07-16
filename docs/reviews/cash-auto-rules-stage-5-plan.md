# Cash auto rules — Stage 5 plan

## Status

- Phase: **Stage 5.1 implemented**
- Implementation: **awaiting owner review**
- Overall risk: **HIGH**
- Required gate: **STOP before Stage 5.2**
- Production mutations: **forbidden in this plan**

## Goal

Make the existing auto-rule preview exact and operationally useful, and make every range-triggered mutation traceable through one correlation ID, without adding a second matcher, changing matching semantics, or rewriting historical transactions.

Stage 5 must provide:

- the winner and competing rules per target field;
- exact field-level before/after values based on the same application plan as the worker;
- read-only dry-run statistics for the active company and selected period;
- durable correlation between a range run, its child transaction messages, logs, and `AuditLog` records;
- no descriptions, INNs, account numbers, or raw bank payloads in new logs or audit metadata.

## Current implementation and reusable patterns

### Cash auto-rule flow

- `CashTransactionAutoRuleService::match()` is the single matcher used by preview, manual apply, and the worker.
- `CashTransactionAutoRuleService::createApplicationPlan()` already calculates the exact field-level changes without flushing them.
- `CashTransactionAutoRuleService::previewRule()` already iterates transactions, invokes the shared matcher, and returns the match plus application plan.
- `CashTransactionAutoRuleApplicationPlan::auditDiff()` already persists per-field rule ID/revision and before/after IDs in `AuditLog.diff`.
- `CashTransactionAutoRuleController::check()` is an authenticated, active-company-scoped HTML flow with a validated maximum period of 366 days and a row limit of 200.
- `EnqueueAutoRulesForRangeHandler` already propagates company and range context to child `ApplyAutoRulesForTransaction` messages.

### Similar project patterns inspected

1. Inventory snapshot sessions generate UUIDv7 correlation IDs once and propagate the same ID through child work and logs.
2. Marketplace Ads range handlers keep a single run/job identity while dispatching child messages.
3. Existing ingestion and finance dry-runs separate read-only inspection from execute modes; Stage 5 will use a genuinely read-only preview and will not run a transaction-and-rollback simulation.

## Design decisions

### 1. Keep one matcher and one application-plan calculation

Preview must call the existing matcher and `createApplicationPlan()` for every candidate transaction. It must not rebuild rule semantics in SQL or Twig.

No changes are allowed to:

- priority/specificity/immutable-ID precedence;
- independent category/project/counterparty resolution;
- conflict handling;
- `CF_UNALLOC` safe-fill behavior;
- null-target semantics;
- finance-lock and soft-delete skips.

### 2. Extend the existing check page

Keep the existing route, authorization, status codes, and active-company boundary. Do not add a second preview route or a public JSON API.

The page will show, per displayed transaction:

- selected rule status;
- winner per field;
- competing rules for conflicting fields;
- current value and planned value for category, project, and counterparty;
- skip/no-change reason.

The existing row limit applies only to displayed rows. Statistics cover every selected-rule match in the validated period.

### 3. Use one small preview-result DTO

Add one readonly result DTO that contains:

- limited preview rows in the existing internal row shape;
- scanned/matched/would-change/no-change/skipped/conflict counts;
- change counts per field;
- independent breakdowns by calendar month, currency, resulting category, and resulting project.

Do not build a combinatorial company × month × currency × category × project cube. The active company is the company dimension for the current authenticated run; each other dimension is aggregated independently.

“Resulting category/project” means the planned value when the application plan changes the field, otherwise the current value. Null/unallocated buckets remain explicit.

### 4. Stream and aggregate in one pass

Move the company/date query construction into a focused repository iterator so the controller stays thin. Preload the relations needed by the existing page and grouping, load active rules once, then calculate rows and aggregates in one PHP pass.

Memory must be bounded by:

- at most 200 displayed rows;
- aggregate maps for the allowed dimensions;
- no retained list of every scanned transaction.

No preview path may call `persist()`, `flush()`, dispatch Messenger messages, or mutate a managed transaction.

### 5. Reuse `AuditLog`; do not add a new table

Stage 4 already provides durable rule ID/revision and field-level changes. Stage 5 will add a top-level UUID `correlationId` to the same JSON diff.

Proposed audit shape:

```json
{
  "correlationId": "uuid",
  "autoRules": {
    "cashflowCategory": {"id": "uuid", "revision": 1}
  },
  "changes": {
    "cashflowCategory": {"before": "uuid-or-null", "after": "uuid"}
  }
}
```

No database migration is planned. Existing audit readers must continue accepting older diffs without `correlationId`.

### 6. Backward-compatible correlation propagation

- Generate one UUIDv7 for every `EnqueueAutoRulesForRange` run.
- Pass it unchanged to every child `ApplyAutoRulesForTransaction` message.
- Single-transaction fallback and manual apply generate their own UUIDv7.
- Add the ID to safe structured logs and to explicit auto-rule `AuditLog.diff` records.
- Message fields must remain optional/defaultable during deserialization so messages queued by the previous deployment remain processable.
- Do not change `config/packages/messenger.yaml`, transports, retries, workers, or routing.

## Staged implementation

### Stage 5.1 — Exact preview and dry-run statistics

**Risk:** MEDIUM

**Result:** one read-only preview implementation shared with worker semantics.

**Next action after green self-review:** create a separate reviewable PR; do not start Stage 5.2 until owner review.

Work:

1. Add `CashTransactionAutoRulePreviewResult`.
2. Add an active-company/date repository iterator with the current eager joins and soft-delete exclusion.
3. Change `previewRule()` to return limited rows plus full-range aggregates in one pass.
4. Render field-level winner/conflict and before/after values on the existing check page.
5. Render independent breakdown tables for month, currency, resulting category, and resulting project.
6. Keep the existing filter validation, route, authorization, and 366-day maximum.

Acceptance criteria:

- Preview and worker produce the same winners, conflicts, skips, and application plan for the same transaction.
- Display limit does not truncate statistics.
- Preview performs no writes or dispatches.
- Existing company access checks remain intact.
- Empty/unallocated buckets are visible and deterministic.
- No new N+1 query is introduced.

### Stage 5.2 — Correlation ID and durable provenance

**Risk:** HIGH

**Result:** one correlation ID links range dispatch, child processing, logs, and mutation audit.

**Next action after green self-review:** STOP for owner review.

Work:

1. Add backward-compatible optional correlation fields to both auto-rule messages.
2. Generate UUIDv7 once per range and propagate it unchanged to all children.
3. Add correlation IDs to subscriber fallback and manual single-apply paths.
4. Extend `auditDiff()` to include the correlation ID without changing existing rule/changes payloads.
5. Add correlation ID to range and transaction auto-rule logs; add no new PII fields.
6. Verify retry behavior keeps the original correlation ID.

Acceptance criteria:

- Every new worker mutation audit has a valid correlation UUID.
- Every child of one range carries the same correlation UUID.
- Different range runs have different UUIDs.
- A retry preserves the message correlation UUID and does not alter matcher semantics.
- Older payloads without the new field can still be handled.
- No schema or Messenger routing change exists in the diff.

### Stage 5.3 — Hardening and production acceptance

**Risk:** HIGH for production verification

**Result:** Stage 5 handoff with read-only production evidence.

**Next action:** STOP after final handoff.

Work:

1. Run the full relevant local check set and review the complete Stage 5 diff.
2. Deploy only after owner approval of Stage 5.2.
3. Run read-only production checks for deployment health, queues, and new audit correlation shape.
4. Use the authenticated check page as a dry-run; do not enqueue a historical range.
5. Compare preview counts with subsequent natural processing only when a normal new transaction/import occurs.

Acceptance criteria:

- Deployment and containers are healthy.
- No new failed Messenger messages are attributable to Stage 5.
- New mutation audits contain valid correlation IDs and unchanged per-field provenance.
- Preview remains read-only.
- No historical reprocessing is triggered as a deployment side effect.

## Expected change areas

### Stage 5.1

- `site/src/Cash/Application/DTO/CashTransactionAutoRulePreviewResult.php` — new result DTO.
- `site/src/Cash/Application/DTO/CashTransactionAutoRulePreviewFilter.php` — only if grouping/filter validation needs a focused extension.
- `site/src/Cash/Repository/Transaction/CashTransactionRepository.php` — company/date preview iterator.
- `site/src/Cash/Service/Transaction/CashTransactionAutoRuleService.php` — one-pass preview result and aggregates.
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php` — thin orchestration only.
- `site/templates/cash_transaction_auto_rule/check.html.twig` — exact field details and aggregate tables.
- focused unit/functional tests for the DTO, service, controller, and template context.

### Stage 5.2

- `site/src/Cash/Message/EnqueueAutoRulesForRange.php`.
- `site/src/Cash/Message/ApplyAutoRulesForTransaction.php`.
- `site/src/Cash/EventSubscriber/Transaction/CashTransactionAutoRulesSubscriber.php`.
- `site/src/Cash/MessageHandler/EnqueueAutoRulesForRangeHandler.php`.
- `site/src/Cash/MessageHandler/ApplyAutoRulesForTransactionHandler.php`.
- `site/src/Cash/Controller/Transaction/CashTransactionController.php`.
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php`.
- `site/src/Cash/Command/CashAutoRulesEnqueueCommand.php`.
- `site/src/Cash/Application/DTO/CashTransactionAutoRuleApplicationPlan.php`.
- focused message/subscriber/handler/controller tests.

### Documentation

- `ARCHITECTURE.md` — preview statistics and correlation/audit contract.
- Stage report/handoff documents only if a task ID is assigned before implementation.

## Required checks

### Stage 5.1

- Targeted unit tests for matcher/preview statistics and filter boundaries.
- Controller functional tests for company isolation, invalid filters, limited rows, and full statistics.
- Twig lint for the updated Cash auto-rule template.
- Symfony container lint.
- Targeted PHP CS check for changed PHP files.
- `make site-test-unit`.

### Stage 5.2

- Unit tests for correlation generation and propagation.
- Integration tests for range → child message correlation.
- Integration tests for worker/manual audit diff shape.
- Compatibility test for missing correlation ID/default construction.
- Symfony container lint and targeted PHP CS check.
- `make site-test-unit` plus relevant integration tests.

### Final

- `git diff --check`.
- `git status --short` and `git diff --stat`.
- Full relevant GitHub CI.
- Read-only production checks only after explicit owner approval/deployment.

## Must not change

- Matching precedence or existing financial classification semantics.
- Safe-fill/no-overwrite behavior.
- Existing rule lifecycle, schema, priority, revision, or optimistic locking.
- Public route URLs, response status codes, auth, roles, voters, or company access.
- Messenger transports, routing, retry policy, workers, or production scheduler.
- Existing transactions through an automatic historical enqueue.
- Financial report formulas, signs, periods, or mappings.
- Dependencies, Docker, deployment configuration, or CI behavior.

## Explicitly out of scope

- New rule conditions/signals from Stage 6.
- CFO model from Stage 7.
- Cross-company admin preview.
- Persisted dry-run jobs or a dedicated auto-rule-application table.
- Automatic rule creation or ML recommendations.
- Historical backfill/reprocessing.
- Cleanup of unrelated `reports/` files or branches.

## Owner review focus

1. Confirm that active-company-scoped statistics satisfy the “by company” requirement; no cross-company UI is proposed.
2. Confirm independent breakdowns instead of a high-cardinality multidimensional cube.
3. Confirm reuse of `AuditLog.diff` and no migration/new application table.
4. Confirm backward-compatible optional correlation fields for queued messages.
5. Confirm three separate reviewable units and the STOP after Stage 5.2.

## Phase 0 decision

The owner approved Stage 5.1 implementation. The minimal no-migration path above is implemented and must be reviewed before Stage 5.2 starts.
