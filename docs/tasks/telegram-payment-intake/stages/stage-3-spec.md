# Stage 3 SPEC — Cash PaymentIntake

Base: `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`. Risk HIGH-LOCAL.

Durable company-owned draft binds globally unique Telegram bot/chat/message to the original company and actor. Original received time/timezone and account determine visible date/currency defaults. JSON candidates are stable UUID rows inside the locked aggregate, each with evidence, questions and provenance. They never create ledger entries until explicit user confirmation of paid status.

Actions own transactions. All user operations check current company cash write access and exact actor ownership, and lock the draft. Revision and task-attempt keys prevent stale model results and callbacks. Request persistence precedes enqueue; retained pending intent can re-enqueue the same deterministic task after a crash. Explicit retry after terminal failure increments attempt once. Result consumer is called in gateway transaction, locks draft and performs no flush.

Confirm locks and validates current permissions, revision, selected rows, active account/category and project–CFO compatibility, period and currency, and explicit paid status. Existing Cash creation is reused inside one outer transaction; side effects must remain recoverable and confirmation stores stable transaction IDs. Confirm replay returns those IDs. Cancel is terminal. Posted operations are never amended automatically.

Acceptance: source replay cannot cross company; actor and company isolation; invalid/stale result no mutation; transport retries same task; explicit retry new task; visible defaults and unresolved questions block posting; manual patches preserve provenance; confirm replay no duplicate; concurrent confirm/amend serialize; failure on second row rolls back whole batch; revoked permissions/archived category/closed period reject; local/test migration and schema check; old Cash creation regressions pass.

Checks: baseline existing CashTransactionActions integration; unit aggregate/parser; PostgreSQL integration lifecycle and rollback/concurrency; focused PHPStan/CS and schema validation. No production or live LLM calls.
