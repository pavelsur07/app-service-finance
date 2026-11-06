## Current checkpoint

**Phase:** Stage 2 / Work item 2.3
**Status:** reviewing
**Task base commit:** 4fdad15c974830d13ff9342d1f40a526f3b5bd33
**Stage base commit:** 66e666657
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
- Await external Stage2 review session34272; log /tmp/vf-settlement-external-stage2.log, artifacts site/var/external-review/66e66665/. Fix confirmed findings, finalize Stage2 report/commit/push, then Stage3. Do not rerun duplicate review.

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

### Work item2.1
- Cash snapshot Facade/DTO added with scoped locked DBAL projection, no stale ORM source.
- RegisterCashSettlementAction posts Money PAYMENT/item/movement and active link; source Cash/legacy allocation untouched.
- Reverse source document now locks Cash before book and clears source link with lastReversalDate; repeated reinclusion respects history.
- Registration test red missing Action, then reinclusion failed until link lifecycle fixed. PASS7 tests /35 assertions, focused PHPStan PASS, CS applied.
- Source guard integration still pending Work item2.3; source adapter alone is not safe for release.
- Next: Finance source snapshot and explicit obligation draft; post must reread/fingerprint under source locks.

### Work item2.2
- Finance locked snapshot DTO/Facade with Cash-before-Finance order; explicit full Money amount creates draft only.
- Posting revalidates source eligibility/fingerprint, activates unique source link. Changed date/operation amount and duplicate active obligation were red (3 real assertion failures), then green.
- Cancellation releases Finance link using source-before-book locking.
- Settlement current tests PASS38 /160; focused PHPStan PASS, CS applied.
- Next Work item2.3: source-owned events/lifecycle guards and all inventory write paths; these are NOT implemented yet, so feature remains unsafe to release.
- Snapshot query should be factored out of FinanceFacade before injecting guard into DeletePLDocumentAction, to avoid FinanceFacade → Action → guard → FinanceFacade DI cycle. Reuse one fingerprint builder for stored vs in-memory form state.

### Work item2.3 in progress
- Source-owned synchronous events and Settlement subscriber implemented; active link rejects material mutation. Cash update/delete/restore/bulk now transactional with fresh source lock; cache event stays after commit.
- Finance guard reuses FinanceSettlementSourceQuery, comparing stored vs edited managed form without refresh. Soft delete/restore/hard-delete guarded. DeletePLDocumentAction scoped lookup replaces legacy unscoped find.
- Existing import-match update and 1C overwrite guarded; import wrapped in all-or-nothing transaction, retained import log finalized after rollback. Raw Cash SQL command locks company and sorted fixed source IDs; source/derived recalc atomic. Finance repair locks Cash then Finance and constrains operation owner in SQL.
- Current focused legacy+Settlement run PASS66 /301 (before newest two tests); targeted commands validation running. Full Stage2 not closed.
- Ruling: PAYMENT item cannot be increased/decreased by manual correction. Such a correction bypassed immutable Cash amount and could create available payment money with no Cash; regression red. Use cancellation + actual Cash correction + reinclusion. OPENING/OBLIGATION remain correctable. This enforces source-truth and forbids masking refunds as corrections.
- Still required: verify import atomic rejection/log retention, command guards, nonmaterial Finance edits, all write-path coverage; focused gates and fresh review/external at Stage closure. User-facing guard errors via Settlement listener in UI Stage4.

### Stage2 latest review and regressions
- Fresh internal reviewer found 4 IMPORTANT total in Stage2: stale hard-delete date, source error HTTP500, import file-order locks, account lock released before outer commit. All fixed with regression evidence; bounded rereview confirms no open B/I (compatibility test adapters completed).
- MINOR: dedupeHash assignment lost by refresh fixed; allowed linked overwrite repairs null hash without duplicate cash.
- Import Action owns account lease past commit/rollback; Service skips inner acquire/release for authorized caller and rejects unsupported transactional direct calls. Existing description/idempotency tests now go through actual Action.
- Two real PG sessions test sorted source row locks and registration/mutation serialization; child PHP Process used because CLI pcntl unavailable. Test fixture never accesses production.
- Kernel source mapper regression: disabling mapper yielded500 instead of422; restored and local test container cache cleared, three selected mapper/fresh-date checks PASS3 /10.
- Most recent broad targeted run PASS104 /535 before latest import/helper tests; rerun final Stage2 set pending. Focused PHPStan had 4 fixture-only errors (assert already narrow / exit forbidden), fixed by removing redundant assert and using return/exception.
- External Stage2 review not started yet. Need final focused gates/module test output then one external round from base66e666657, Stage Report, commit/push, continue Stage3.

### Current exact state (latest)
- Work item2.3 guard inventory completed; all material paths covered and targeted tests pass.
- Final combined Stage2 module+affected old source checks PASS118 /594; project focused CS PASS, focused PHPStan PASS. After optimizing Finance header-only locks, relevant source/Finance subset PASS62 /387 and focused PHPStan PASS.
- External Stage2 running: `site/bin/external-review.sh 66e666657 --effort medium --context /tmp/vf-settlement-stage2-review-context.md`; exec session34272. Output /tmp/vf-settlement-external-stage2.log, artifacts site/var/external-review/66e66665/. Do not duplicate invocation.
- Stage2 source guards currently uncommitted; Stage2 source registration earlier checkpoint commit d10853b05. Last pushed stage closure66e666657. Current worktree has only task changes.
- Source query cleanup: deleting/restoring technical Finance docs locks header without loading huge operation arrays; full snapshot retained for edit comparisons/create/post. Reversal refreshes active source link before clearing.
- User asked where documents will be: answered separate “Взаиморасчёты → Документы”, links in counterparty card and Cash/Finance. Ensure Stage4 includes document list route/navigation.
- Stages3/4/5 NOT implemented. Task remains active; no merge/deploy approval received or requested for implementation.
