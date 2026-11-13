# Stage 4 specification and acceptance checklist

Base: `dbfc6d5ffaef6f49554d58bb7863b3d67bbeb59c` at Stage 4 start. Scope: Telegram adapter only;
Cash owns validation, revision and atomic confirmation; ServiceGateway owns task
result import. Existing webhook secret validation remains the first gate.

External review addendum: isolate interactive parse submission and poll/card
delivery on `async_payment_intake`, a dedicated logical Doctrine queue using
the existing application database/transport package. Retry delay is 2 seconds.
Existing categorization and other AI submissions retain their current routing.
Local routing is part of implementation; production worker activation is a
separate named approval before pilot enablement. No production worker/queue
commands are executed by this change.

## Intended behavior

The dedicated TELEGRAM_PAYMENT_INTAKE_ENABLED switch defaults to 0. With the
switch off, never-selected bindings retain legacy behavior, including companies
already granted AI categorization. Once explicitly enabled, ordinary text on an
AI-enabled company goes exclusively to PaymentIntake. Once selected, an intake
binding/source/session never falls back to legacy on an outage or revoked grant.
Known Cash intake sources also stay closed after a Telegram binding/session is
removed. Unknown and legacy edited messages remain ignored.
Authenticated link creation records the actual application user UUID. `/start`
copies it to the binding. Historical links/bindings keep NULL actor and must be
rebound for intake; the company owner is never substituted.

`/set_cash` persists one selected company binding per Telegram user and bot.
Each source message is durably pinned to its original binding and draft. Edits,
replays and callbacks resolve this source before current company selection and
check fresh financial rights. A private chat is required for the new intake.

Acceptance is explicit: drafts do not create money. Cards show every stable row,
questions, dates, account and analytics as plain text. Revision-bound callbacks
confirm selected rows, exclude/include rows, cancel, retry or request a targeted
text correction. A reply to a draft card targets that draft; unaddressed
correction text asks for the target rather than choosing the latest draft.
Edited original messages reparse with an idempotent edit fingerprint.

A durable Telegram session records pending delivery. Delayed bounded Messenger
polls reuse the gateway task refresh/import path and render the latest Cash view.
Webhook and worker never sleep. A UUID delivery request token prevents an older
in-flight card from clearing a newer request; finalization refreshes under a row
lock after HTTP. Field replies have an atomic Cash receipt independent of the
source edit marker. Existing manual corrections require financial write access
but do not require the AI provider; report paths require financial read access. Telegram delivery retry does not invoke Confirm.
At-least-once delivery can repeat a card after an ambiguous Telegram timeout;
it cannot repeat payment creation. Normal import remains the result fallback.

## Acceptance checklist (evidence recorded in Stage report)

- [x] Actual actor retained from authenticated link; old bindings require rebind.
- [x] Forged secret, wrong Telegram sender/bot/chat, revoked binding and rights denied.
- [x] Multiple-company selection persists; original source stays pinned across changes.
- [x] AI-enabled normal text queues a draft and cannot invoke legacy creation.
- [x] Service/budget/timeout failures are explicit; retry is revision-scoped.
- [x] Processing → cards → explicit confirm/cancel; only selected stable rows confirmed.
- [x] All rows/questions displayed, including long lists; Telegram text never uses parse_mode.
- [x] Targeted correction and edited original use draft/revision, repeated edit is idempotent.
- [x] Poll delivery is bounded and durable; response failures do not repeat Confirm.
- [x] Existing /start, /set_cash, reports and disabled-company legacy tests pass.
- [x] Focused lint/static checks and Telegram tests run without live external calls.

Non-goal: live production Telegram/model testing, new public routes or grants,
web UI, or changes to financial semantics. Production rollout remains disabled
until the separate Stage 5 live evaluation and release approvals.
