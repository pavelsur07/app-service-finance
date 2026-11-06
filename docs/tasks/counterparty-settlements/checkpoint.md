## Current checkpoint

**Phase:** Stage 1 / Work item 1.2
**Status:** reviewing
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
- Await/read external Stage1 review, fix confirmed findings; finish Stage Report, commit/push fixes, continue Stage2 automatically. Source adapters and later Stages not implemented.

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

- Internal fresh Stage1 review found 3 IMPORTANT in total: stale closure getter (fixed), UUID ordering (fixed with company-book-issued persistent eventSequence), missing Stage1 security/opening/history coverage (expanded).
- Sequence regression: old UUID order fails when chronological movement IDs are deliberately reversed; new effectiveDate/eventSequence passes.
- Draft implementation PR: https://github.com/pavelsur07/app-service-finance/pull/2901 (base master verified). Checkpoint commit 80e4c8502 pushed; Stage1 review fixes not yet committed.
- Local migration evolving before release: added event_sequence to documents and next_event_sequence to book; test DB altered locally, migration remains additive for fresh production deployment.

### Latest evidence
- Expanded module tests PASS 23 tests / 78 assertions; no new Doctrine deprecations.
- Focused PHPStan PASS (Settlement src/tests/builders and Company changed files).
- Fresh independent internal rereview: BLOCKER 0 / IMPORTANT 0 remaining, all findings fixed.
- Fresh second DB vf_settlements_fresh_test: all 307 migrations applied, Settlement schema test PASS 1/4. New migration executable from empty database.
- External Stage1 review running via site/bin/external-review.sh 4fdad15c974830d13ff9342d1f40a526f3b5bd33 --effort medium --context /tmp/vf-settlement-stage1-review-context.md. Process session82656; output /tmp/vf-settlement-external-stage1.log and site/var/external-review/4fdad15c/. Do not start duplicate review.
- Source write-path exploration delegated read-only to inspect_settlement_sources; await inventory for Stage2.

### Stage1 closure
- External review completed one round: BLOCKER0, IMPORTANT1, MINOR3; all fixed. IMPORTANT was missing dependency-reversal coverage, now 3 explicit cases pass. External result: fixed without re-run per AGENTS7.2.
- Final Stage1 tests PASS27 /103; focused PHPStan and CS checked. No open B/I. Stage Report saved; continue Stage2 automatically.
