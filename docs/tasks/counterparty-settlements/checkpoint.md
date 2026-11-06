## Current checkpoint

**Phase:** Stage 1 / Work item 1.2
**Status:** implementing
**Task base commit:** 4fdad15c974830d13ff9342d1f40a526f3b5bd33
**Stage base commit:** 4fdad15c974830d13ff9342d1f40a526f3b5bd33
**Branch:** feat/counterparty-settlements
**Worktree:** /home/deploy/.codex/worktrees/3821/app-service-finance

### Completed
- Owner authorized execution 06.11.2026; recommended D1–D7 accepted for implementation; Money mandatory.
- Fresh worktree from fetched origin/master; plan copied from docs PR #2899 without merging it.
- Local container vf-settlements-php mounts this worktree at /app and existing vendor read-only; no production credentials copied.
- Inspected BalanceLedgerService, CreateDocumentFromTransactionAction, PaymentPlanMatcher, module setup, Money contracts.

### Checks and baseline
- `docker exec vf-settlements-php php bin/phpunit tests/Unit/Cash/Entity/Transaction/CashTransactionAllocationTest.php` — PASS 7 tests, 15 assertions.
- `docker exec vf-settlements-php php bin/phpunit tests/Integration/Cash/Application/CreateDocumentFromTransactionActionTest.php` — PASS 11 tests, 49 assertions (existing local test DB, transaction rollback).

### Rulings / pre-flight
- Project workflow supersedes skill per-work-item commits and full suite runs: Stage commits, full gates at handoff.
- Stage 1 produces Money-based positions consumed by allocation/report/source DTOs; shared signatures match plan.
- Money add/subtract/negate overflow limitations must not be bypassed with local money helpers (PATTERNS.md).

### Review status
- internal: not started
- external: pending at required Stage/handoff points

### Exact next action
- Complete Stage 1 negative/action tests and review; source adapters and remaining Stages not implemented.

### Files to inspect first on resume
- TASK.md, plan.md, checkpoint.md, git status.

### Work item 1.1 evidence
- Isolated local vf_settlements_test DB: all 306 existing migrations applied; no existing shared DB changed.
- Unit posting policy: red missing entity, then PASS 3 tests / 5 assertions.
- Schema Money round-trip/company FK: red missing document, then PASS 1 test / 4 assertions.
- New migration Version20261106183000 applied locally; mapping valid. Only schema difference: pre-existing fixture test_money_holder absent from migrations.
- Ruling: existing SchemaTool listeners inject unrelated tables when generating a subset. Generator restricted to settlement tables and explicit SettlementSchemaExtension. Corrected migration never applied to production.
- Ruling: ORM entities use private fields without readonly, keeping mutation behind methods. ORM refresh of readonly dates caused LogicException; removed readonly in entities only; DTO and Money stay readonly.
- Lifecycle positive test (create/post/correct/replay/reverse/history) PASS 1 test / 8 assertions.
- Focused PHPStan initially 17 errors (snapshot annotations + nullable fixture IDs); fixes in progress.

- Negative invariants: 5/6 passed; correction of reversed item was accepted (IMPORTANT). Added active-source guard after idempotency lookup; verifying fix.

### Stage 1 current verification
- Full current Settlement test set PASS: 11 tests, 40 assertions.
- IMPORTANT stale financial boundary: proven red after SQL period closure, fixed by fresh scoped DBAL getter; regression green.
- Internal review ongoing; Stage 1 not closed; external review required (>500 lines) before closure.
- Schema validate mapping PASS; schema difference only pre-existing test_money_holder fixture.
- Remaining: strengthen Stage1 action negative/idempotency tests, complete fresh internal review and external review, Stage Report.
