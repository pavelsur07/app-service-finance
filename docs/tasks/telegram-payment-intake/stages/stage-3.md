# Stage 3 — Cash PaymentIntake

Base: `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`. Risk HIGH-LOCAL.
Status: implementation and module verification complete; parent independent reviews/commit pending.
SPEC preceded implementation: [stage-3-spec.md](stage-3-spec.md).

## Delivered

`CashFacade` request/get/source lookup/amend/confirm/cancel/recover contracts and scalar DTOs; durable JSON candidate aggregate with stable item and ledger UUIDs; global immutable Telegram source binding; company + exact actor authorization; current financial write permission and period/account/category/project–CFO checks. Selected-account currency, original message date and existing general project–CFO defaults are displayed with provenance. Models never confirm payment; user explicitly confirms paid fact. Financial/analytic amendment clears previous paid confirmation unless the same amendment explicitly confirms final values.

Queue intent, payload snapshot, revision and attempt persist before enqueue. Broker failure retains pending intent; recovery reuses exactly the same deterministic task. Terminal failed retry creates one new attempt; stale tasks/results cannot replace manual corrections. Natural correction supplies current stable row refs, preserves untouched rows and manual analytics. Consumer runs in gateway transaction without flush and validates strict output shape plus original literal evidence.

Confirm locks the aggregate with ORM refresh, then posts selected candidates and marks confirmation inside one transaction. Stable ledger UUIDs and terminal confirmation replay prevent duplicate postings. `AddCashTransactionAction` exposes guarded `createInCurrentTransaction` reusing existing VAT/split/payment-plan/balance logic; original entrypoint behavior retained. Cash cache event is retained as a durable pending marker and retried after commit; event failure does not turn a saved payment into a reported failure.

Recovery: `php bin/console app:cash:recover-payment-intake <company-uuid>` retries pending enqueue and Cash cache invalidation. This is a processing command; production invocation requires separate approval. Parent Telegram ingress also invokes recovery. No scheduler/worker production changes or live model calls performed.

## Evidence

- Authoritative baseline: `docker exec vf-payment-intake-php php bin/phpunit tests/Integration/Cash/Application/CashTransactionActionsTest.php --no-progress` → 8 tests, 39 assertions green. Initial `docker compose exec` baseline used a different mounted worktree and was discarded; dedicated runner uses this checkout.
- Aggregate regression initially failed because class missing; initial core tests green 3/6. Monetary amendment regression explicitly red (`paid_confirmed` stayed true), then green.
- Visible general-pair regression explicitly red (null project ID), then consumer uses existing `findGeneralPair` default with provenance.
- Event-after-commit regression explicitly red (`Test cache unavailable` escaped Confirm), then fixed with retained marker, successful confirmation and recoverable event.
- Focused lifecycle + concurrency + aggregate run before general default enhancement → 20 tests, 72 assertions green. Broader final module run recorded below.
- Real PostgreSQL concurrency: `PaymentIntakeConcurrencyTest` → 2 tests, 18 assertions green. Committed fixtures use separate physical connection; two independent PHP processes are observed waiting on the aggregate lock. Two Confirm return identical IDs with one ledger row; Amend/Confirm cannot both apply the same revision. A first test-harness attempt failed because PostgreSQL cached its activity snapshot; `pg_stat_clear_snapshot()` makes observation current.
- Local test migration `Version20261113171344` applied successfully. Mapping validation green; preliminary DB diff contained only unrelated missing `test_money_holder` fixture and Telegram index name drift, reported to parent.

## Acceptance / review

- [x] Exact actor + company isolation and global source pinning.
- [x] Durable enqueue recovery, original reference time, same transport attempt.
- [x] Stale/repeated results and old task attempts inert.
- [x] Explicit paid confirmation, unresolved questions block posting.
- [x] Visible defaults and preserved manual provenance.
- [x] Atomic selected batch; failure on second row rolls back first row and draft state.
- [x] Real concurrent Confirm and Amend/Confirm checks.
- [x] Period/category changes after preview rechecked; current permission checks reuse Company serialization contract.
- [x] Cancel never posts; source/import IDs separate from legacy.
- [x] No logging of raw text or credentials.
- [x] Final module/format/static results appended below.
- [ ] Parent fresh internal review and external review; findings fixed before Stage closes.
- [ ] Parent commit/push/PR and ARCHITECTURE update (outside subagent ownership).

No claims of live-model quality or pilot readiness. Heuristic duplicate warnings compare same company/account/date/amount/currency/direction in one batched query and repeated candidates in memory; equal legitimate payments are retained and can both be confirmed. Recovery runs on explicit command/ingress; production scheduler changes require separate approval.

## Final owned checks

- Cash module: `docker exec vf-payment-intake-php php bin/phpunit tests/Unit/Cash tests/Integration/Cash --no-progress` → **812 tests, 2,988 assertions green** (before final duplicate-warning enhancement).
- Focused PHPStan over all owned source/tests → **exit 0**, no new diagnostics. Initial annotation/style diagnostics fixed without baseline changes.
- Cash migration round-trip: `doctrine:migrations:execute DoctrineMigrations\Version20261113171344 --down --env=test`, then `--up` → **both green**; no existing financial rows changed.
- Duplicate warning regression explicitly red before implementation (missing possible_duplicate_ids), then focused lifecycle green **15 tests, 53 assertions**. Final aggregate + lifecycle + concurrency + old creation regression green **30 tests, 118 assertions** before final ready-state refinement. Remaining final touched paths checked with focused tests and style/static analysis.
- Internal review by implementer found/fixed: stale ORM identity-map loads under lock; Confirm while a new correction was queued; failure after ledger commit escaping as apparent save failure; implicit general project/CFO missing in preview. Parent review additionally found/fixed nullable evidence and retained paid confirmation after monetary amendment. **Implementer metrics: BLOCKER 0, IMPORTANT 4; parent findings IMPORTANT 2 already fixed.** Final independent review and external metrics owned by parent.

### Boundaries

Migration stores stable candidate rows inside aggregate JSON rather than separate item entities: a single aggregate row lock serializes all item mutations and Confirm, and item lookup always occurs inside the authorized aggregate. No user-supplied ledger/source UUID fields can be patched. Existing createTransaction regression suite retained. No commits, push or PR performed by subagent; parent owns the single task PR.

- Explicit ready state refinement: regression red (`needs_clarification` after paid confirmation); `ready` now means all included rows have explicit paid confirmation, positive valid money/date/direction, and resolved questions. Any relevant correction clears prior paid confirmation and returns clarification. Current references/permissions are still revalidated at Confirm.

- Final reparse regression red (stable item ID and manual amount lost), then fixed: known stable refs reuse item/ledger UUIDs and manual fields, show conflicts and reset paid confirmation. Unmatched former rows remain with explicit remapping questions; only included rows (maximum 50) enter another model request.
- Final focused regression run after ready/reparse refinements: **31 tests, 124 assertions green**; all owned source/test focused PHPStan **exit 0**, CS **0/19 fixes**, `git diff --check` clean. The last performance refinement hoists account refresh/category directory/finance period reads out of per-item validation, scoped to each mutation; follow-up checks recorded in parent handoff.

- Post-performance-refinement verification: focused regressions **31 tests, 124 assertions green**, focused Action/aggregate PHPStan **exit 0**. CS dry-run found import/order/docblock spacing only and was corrected before final handoff.

- Recovery query now refreshes managed drafts from DB. This exposed an overly strict test that compared JSONB object key insertion order; corrected to exact task/key identity plus semantic payload equality (JSONB legitimately reorders object keys). Final recovery tests rerun after that assertion correction.

## External review round 1 — fixes without re-run

Review: `site/var/external-review/8a1f51ca/review.txt`: **BLOCKER 0, IMPORTANT 3, MINOR 5**. Parent fixed IMPORTANT 1 (deterministic single stable `target_ref`; unknown/null/multiple correction targets never mutate). Cash implementer verified IMPORTANT 2 and 3 and fixed them:

- **I2:** consumer validates IDs/direction/project–CFO pair against the exact persisted request directory snapshot; foreign/nonexistent or one-sided choices become null fields plus row questions. Manual amendments validate only explicitly patched rows; Confirm still revalidates every selected row. Regressions were red (foreign category stored; unpatched archived row blocked another row's correction), then green.
- **I3:** recovery catches sanitized per-draft enqueue failures, continues through deterministic UUID keyset pages (100/page), and counts only drafts where a pending flag was actually cleared. Regression creates 101 pending drafts while the first 100 continue failing; later draft succeeds, a subsequent all-failing run returns zero, and after broker recovery all remaining 100 clear. This test initially proved recovery aborted at first failure. The new test exposed deprecated string sort order; replaced with `SortDirection::Ascending`.
- **M4:** removed unused Cash `notificationPending` state, methods and migration column. Telegram delivery remains its own durable session responsibility. The already-applied local test schema was updated to remove the old NOT NULL column. A test run started before local schema alignment failed with 23502; rerun only after successful local `dbal:run-sql` schema correction.
- **M5:** general defaults skip excluded rows and never duplicate the missing-pair question.
- **M6/M7:** patches enforce actual Cash description maximum (1024), question maximum 50×500, decimal/date/ISO currency/UUID/enum/boolean types. A request combining text and row patches is explicitly rejected. Ten regressions proved previous acceptance/dropped patch/default mutation, then passed after correction. Second-row rollback test now uses a valid historical date whose period is closed after preview, proving actual batch rollback rather than feeding an invalid patch through validation.
- **M8:** snapshot lifetime suggestion is **partially rejected with technical reason**: clearing in `markDispatched()` breaks reference validation against the submitted snapshot and recovery of the same queued attempt. Snapshot remains until the result consumes it or the attempt fails/is invalidated; it is cleared after acceptance, terminal failure, manual supersession, Confirm and Cancel. Regression verifies exact snapshot retention after dispatch and release after consumption.

Recovery is invoked by authorized Telegram ingress/callbacks, trusted recovery command and delayed poll after binding/access checks (Telegram owner wires and tests these callers). Recurring production scheduling remains a separately approved rollout action; no cron/worker configuration changed here.

Review metrics: **external found B0/I3, confirmed I3; all fixed with targeted verification, no BLOCKER, therefore fixed without re-run**. Parent owns final independent full-diff review and combined external metrics.

Final external-fix verification:

- Focused PHPStan over changed Cash intake source/repository/aggregate and tests: **exit 0**. CS check/fix over six changed paths: **0 fixes**; `git diff --check` clean.
- Combined aggregate/lifecycle/real-concurrency/transaction-boundary/old-creation suite ran **49 tests, 252 assertions**, with two environment errors only: Docker DNS briefly could not resolve `site-redis` inside the existing auto-rule listener. Redis container was healthy and a subsequent in-runner `getent hosts site-redis` resolved successfully. Both affected tests were rerun narrowly after that diagnosis and passed **2 tests, 8 assertions**. No remaining test assertion failure; parent full gates provide the final combined run.
- Schema mapping validates. DB diff after removing unused column is only the existing test-only `test_money_holder` fixture; Cash table matches current mapping and task migration exactly.
- Recovery entry callers are owned and validated by Telegram implementation. No recurring production schedule was added. A database failure that closes EntityManager is explicitly reported as requiring a fresh manager; broker failures remain isolated and do not starve later drafts.
