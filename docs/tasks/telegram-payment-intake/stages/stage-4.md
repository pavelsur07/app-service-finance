# Stage 4 — Telegram dialogue and delivery

**Risk:** HIGH-LOCAL. **stage_base_commit:** `dbfc6d5ffaef6f49554d58bb7863b3d67bbeb59c`.
**Scope:** Telegram adapter, actor/source/delivery migration and Telegram tests.
Spec and acceptance checklist were written in `stage-4-spec.md` before code.
Parent integrates Messenger configuration, gateway Facade and ARCHITECTURE.

## Delivered

- Authenticated link creation stores the actual application user UUID; `/start`
  copies it to the binding. Historical NULL actors require a new link for intake.
  There is no owner impersonation. Every new intake action, callback and async
  delivery checks fresh financial write rights and binding/bot/sender/chat.
- `TELEGRAM_PAYMENT_INTAKE_ENABLED` defaults to **0**. With rollout disabled,
  bindings that never selected intake retain legacy entry even when the company
  has an unrelated AI categorization grant. Once intake has been selected, its
  marker/source/session keeps subsequent messages in the new route when rollout,
  AI access or the service becomes unavailable. Known Cash sources remain closed
  even if their Telegram binding/session was removed. Private chat is required.
- `/set_cash` persists one selected binding. Selection is serialized by bot/user
  advisory lock and protected by a partial unique index. Original sources stay
  pinned to their company, actor and Cash account after changing selection.
  A Cash-committed source can reconstruct a missing Telegram session.
- Draft cards show all rows/questions, human directory names and financial labels,
  visible date/currency/general-analytics defaults and possible-duplicate warnings.
  Plain text never enables Telegram markup. Long content splits without losing
  its tail. Stable row/session UUIDs are encoded only inside callbacks (≤64 bytes).
- Row exclusion, human verification and correction call revision-scoped Cash
  Amend. Explicit row verification states the amount/date/direction/account and
  company-funds decision; explicit overall verification states separate-payment
  grouping and common data. Required missing values remain Cash validation errors.
  Neither button saves money; Confirm saves only selected rows. Structured field
  replies use a global Cash-owned receipt keyed by bot/chat/reply message, so a
  replay cannot target another row after an intervening correction. Field replies
  no longer replace the original source revision marker.
- Text corrections require the chosen row and a reply to that draft's card. Exact
  project/category/CFO names resolve through trusted company dictionaries; no UUID
  entry is required. `проект Название; ЦФО Название` updates a linked pair atomically,
  so disjoint allowed pairs do not deadlock editing. Unaddressed corrections request an explicit target.
  Original edits use date/update sequence plus a text fingerprint; repeated and
  older edits are inert. The Cash draft compares structured edit ordering while
  holding its row lock; a stale Telegram session cannot bypass ordering by using
  a freshly read draft revision. The Telegram fingerprint updates monotonically
  as a derived hint. Completed drafts never silently amend saved money.
- Durable sessions retain source identity, reply-card IDs, delivery state/cursor,
  polling generation and sequence. A durable `pending_poll` intent is committed
  with progress before publication, and compare-and-set acknowledgement clears
  only the exact enqueued generation/sequence. Duplicate deliveries can republish
  an unacknowledged successor without resending completed cards. Recovery also
  restarts expired pending polls whose processing card was already delivered.
  The lease is released only when its claimed expiry still matches. A successor
  arriving while the predecessor holds a live lease retries after 2 seconds.
  An expiring DB lease prevents concurrent delivery; the sequence suppresses transport replays. Quick pull reuses the
  gateway importer, consumer and ack. Polling and parse submissions use the
  dedicated `async_payment_intake` logical Doctrine queue, with 2-second retries;
  existing categorization routing is unchanged. Polling uses delayed messages, at most 15 pending attempts; no webhook/worker sleeps.
- Each delivery request has a nullable UUID token (new sessions generate one;
  historical rows may be NULL). HTTP sends occur before a short row-locked
  finalization transaction. After refreshing the session, finalization checks the
  claimed lease, generation/sequence, request token and current Cash revision/state.
  A concurrent amendment preserves the newer pending request and schedules its
  continuation; an old final card cannot acknowledge or clear the new demand.
  Sent card IDs are retained without advancing a newer request's cursor.
- Cards send individually at 1100 ms intervals with a persisted cursor. A delivery
  failure retries the failed card instead of restarting the list. A Telegram
  timeout never calls Confirm from the notification handler or rolls back money.
  Ambiguous network outcomes can repeat one notification card, not payments.
- Recovery command: `app:telegram:recover-payment-intake <company-id>`. It reads
  only active candidates in fair 100-row UUID keyset batches, using a company/id
  index, and isolates stale or inaccessible drafts per session. Delivered cold
  history is excluded. Mutation delivery intent is persisted before Cash changes,
  so a commit followed by a process crash remains a recovery candidate.
- Inline ingress/callback/poll recovery uses only the current authorized draft.
  Company-wide Cash recovery remains in the trusted console command. Exact SQL
  reply-ID filtering, scoped by bot/chat and the caller's binding, avoids hydrating
  chat history. The existing unique source index supplies the bot/chat prefix.
  Owned revoked replies are explicitly denied, preventing a new parse in another
  selected company. Unknown/legacy edits remain ignored.
- Report viewing/preferences require financial read access; payment and other
  financial mutation paths keep the default write check. Manual corrections of an
  existing authorized draft continue when the AI provider/grant is disabled,
  without a new model request or legacy fallback. Only actual new parsing/reparsing
  is provider-gated. Rollout-disabled existing callbacks/replies stay consistently
  closed until rollout resumes.
- No production worker activation, scheduler or processing command was run.
  Activating the dedicated queue worker and production recovery processing each
  requires the separately named approval before enabling the pilot.

## Verification evidence

Historical baseline (common runner; superseded by final dedicated-runner checks):
`docker compose exec -T site-php-cli php bin/phpunit -c phpunit.xml
 tests/Unit/Telegram` — **21 tests, 69 assertions**.

Regression proof: new webhook tests first failed for disabled-service legacy
fallback, actorless binding acceptance and ignored edits (three failures). Actor
unit tests first failed on missing actor methods; presentation first failed on
missing presenter. Implementation made these tests pass.

Final authoritative run used `vf-payment-intake-php`, mounted to this checkout.
Earlier common `docker compose exec site-php-cli` results are superseded: that
shared service was later found pointing at another checkout.

Final Telegram module run:
`docker exec vf-payment-intake-php php bin/phpunit -c phpunit.xml
 tests/Functional/Telegram tests/Unit/Telegram tests/Integration/Telegram
 tests/Unit/Cash/PaymentIntake --display-warnings` —
**93 tests, 779 assertions, no warnings** for Telegram plus Cash draft entity tests
before the final two MINOR fixes. Those final fixes then passed the targeted
`--filter 'FinanceReader|ManualFieldReplyWorks'` run: **2 tests, 13 assertions**.
The three final IMPORTANT regressions passed separately: **3 tests, 21 assertions**.
Includes source/session-loss recovery across A→B selection, duplicate/older edits,
forged/revoked callbacks, processing/delayed polling, confirmed-money survival
through a Telegram timeout and retry, long-list cursor resume and replay-step
suppression. External-review regressions prove default-disabled AI-granted legacy
creation, a 200-row reply history without hydration, 101 stale candidate rows
across batch boundaries, narrow recovery that leaves another draft pending, owned
lease release, and delivery intent already present when money commits.
Legacy /start, reports, webhook secret and legacy cash paths pass.

Focused PHPStan (level 8, existing baseline, unmatched-baseline diagnostics
suppressed only in ignored `var/phpstan-telegram.neon`) — **PASS, no errors** after the fresh-review fixes. The earlier focused CS run
used `.php-cs-fixer.dist.php`; the canonical gate uses `.php-cs-fixer.php`. Parent
will run the canonical aggregate fixer/check after authors freeze, so the earlier
zero-change result is not claimed as the canonical gate. Parent owns global gates.

Migration `Version20261113120001`: the pre-token revision ran **up → down → up**
in a throwaway PostgreSQL schema inside one rolled-back transaction: **23 SQL
statements**, legacy binding retained. The final nullable delivery token column
was applied to the local test database and exercised by the 93-test run. Its final
exact roundtrip/schema check remains parent-owned after the serial full PHPStan gate.
Command: `docker exec vf-payment-intake-php php
 var/verify-telegram-intake-migration.php` (ignored local verification artifact).
Pre-token schema dump: **only pre-existing `test_money_holder`**. Parent will
repeat the dump after the final token addition before handoff.
Initial unavailable `doctrine:query:sql` diagnostic was corrected to the supported
`dbal:run-sql`; no migration failure was hidden.

## Review and remaining boundaries

Focused internal review: **BLOCKER 0; IMPORTANT 7 found, 7 fixed; open 0**.
Fixed: raw technical presentation, generic question dismissal, partial-list
resend starvation, company-selection concurrency, out-of-order edits, duplicate
poll/delivery chains, and legacy fallback after revoking AI access. Fresh internal review then found three further IMPORTANT issues: successor enqueue
loss, disjoint-pair editing deadlock, and source edit ordering under concurrency.
All three are fixed with regression evidence. Shared external Stage 3 recovery
feedback also resulted in wiring authorized Cash recovery callers. Parent owns
review closure and external review results; none is claimed on their behalf.

Stage 4 external round 1 (`1b326aa9`): **BLOCKER 1, IMPORTANT 4, MINOR 3**.
All requested source changes are implemented, including the parent-owned dedicated
transport. External round 2 supersedes the earlier pending status: the rerun and final
fresh review are closed with no remaining BLOCKER/IMPORTANT. The local report-read
and manual-provider MINOR fixes passed their targeted tests. Round 2 returned
**BLOCKER 0, IMPORTANT 1**: queue/worker documentation and a local worker were
missing. The three canonical ARCHITECTURE sections, runbook/spec and opt-in dev
Compose worker now describe the queue and the separate activation approval before
pilot enablement; production workers remain unchanged. This finding is
**fixed without re-run**, not an external REVIEW_GREEN marker. During these fixes, internal checks also caught busy-lease ACK loss
and revoked-reply context loss; both were fixed and regression-tested. Overall
internal Stage 4 findings: **BLOCKER 0, IMPORTANT 15 fixed, open 0** after the final fresh-review fixes. The parent owns the external review artifacts/marker and records the final
round-2 result in the task handoff.

Final fresh review added three IMPORTANT cases, each proven red then green:
actorless legacy fallback after binding/session removal (ledger count 1 → 0),
retargeted replay after an intervening field reply (row B 300 → retained 400),
and concurrent amendment during the final HTTP card (new delivery demand lost →
retained and delivered at revision 2). The final external-round-2 MINOR cases
were also proven red then green: finance readers can use reports while payment
writes remain denied; manual field replies work with AI disabled and issue no
new parsing request. No other financial permission checks were relaxed.

One intermediate combined run failed the concurrently introduced Cash unit test
`testSourceEditImmediatelyInvalidatesPaidConfirmation`; the parent had already
fixed that logic while the earlier process held the old class. A fresh process
then passed all 93 tests. The final independent reviewer rechecked the three IMPORTANT fixes and returned
BLOCKER 0 / IMPORTANT 0. Parent reports the full canonical CS gate green and the
strict-types gate green (0/4151). Full static analysis runs serially with an
isolated cache under the parent; no parallel PHP gate is started by this author.

Parent verified the final migration including delivery_request_token:
`docker exec vf-payment-intake-php php var/verify-telegram-intake-migration.php`
— PASS, up/down/up, 23 SQL statements, legacy row unchanged, transaction rolled
back. Final `doctrine:schema:update --dump-sql --env=test` reports only the
unrelated existing test_money_holder fixture; no Cash/Telegram schema drift.

Global gates, commit/push/PR and external review belong to parent handoff.
No live Telegram/model API or production command was run. Model quality and
pilot activation remain Stage 5 work; the rollout default remains disabled.
Question strings remain untyped: explicit reviewed human decisions are used,
not a mechanical classifier of model question text. Natural language analytics
aliases are not inferred; exact unambiguous company names are required.

## Final handoff MINOR closure

The two final Telegram MINOR findings were verified and fixed locally:

- A still-valid pre-migration link with NULL application actor preserves an
  existing binding's actual actor. A non-NULL authenticated link still replaces
  it explicitly. The regression uses an existing non-owner actor and verifies
  that the legacy link is consumed successfully without erasing that identity.
- Missing-session source recovery continues after an inaccessible/wrong actor
  candidate and reaches a later matching binding. Only DomainException is caught;
  database/infrastructure failures propagate. The catch sets `knownSource = true`
  so a source created after the initial metadata check cannot revive legacy entry.

Both regressions were proven red (actor erased to NULL; owned session count 0),
then green: **2 tests, 16 assertions** on `vf-payment-intake-php`. Focused PHPStan
on the two changed source files and their two functional test files passed.
Canonical `.php-cs-fixer.php` dry-run passed on all four files; formatting fixes
were limited to tests. An initial fixture issue from a managed session referencing
a cascade-deleted binding was corrected by clearing/reloading the fixture's ORM
references before proving the behavioral failures. No production action occurred.

Before these narrow final changes, the parent verified the full application suite:
**8942 tests, 50254 assertions, green**. No broad suite or additional external
review was started by this author after the MINOR fixes. Source is frozen for
the parent's final one-look review, commit and CI.
