# Stage 1 — Autonomous ledger backend (reviewing)

Risk: HIGH-LOCAL. Base:238dd997. Consolidates approved original backend work packages1–3; all requirements retained.

Implemented:
- Additive schema, same-company FK constraints, original tables preserved, no destructive down.
- Two sides/four article levels, separate accounts, used hierarchy locks, archives, audited changes.
- Book configuration, balanced opening, draft lifecycle, posting/reversal/correction, exact Money, idempotency and versioning.
- Historical individual and aggregate validation, per-company row locks, atomic rollback/current state.
- Period closing/reopening, granular membership-aware access, queries/cards/reports, precise arbitrary-size turnover formatting.
- Legacy financial providers removed; Company member labels reused through a scoped Facade query.

Verification:
- Baseline14 tests18 assertions.
- Combined backend unit/integration + formatter:89 tests220 assertions green.
- Focused PHPStan on all Balance source + backend tests and touched Company/formatter files:green.
- Focused CS fixer applied. Doctrine mapping validation --skip-sync:green; full DB synchronization intentionally not asserted (legacy archival tables and migration-managed composite FKs).
- Actual migration applied in dedicated local test DB; separate migration tests verify foreign company and restrictive deletion behavior.

Internal reviews and fixes:
- BLOCKER1: active-company permissions leaking across companies — fixed and tested.
- IMPORTANT: archived ancestor posting; target correction stale snapshot; renamed root with archived descendants; historical aggregate overflow; transient intra-document group-card balance; oversized cumulative turnover; consistent nested report transactions; silent audit truncation — all fixed with targeted evidence.
- MINOR: malformed UUIDs, pagination overflow, exact formatter strings and stale baseline suppression — fixed.

External review:round1 running, context explicitly backend only; UI pending Stage2.
Open findings:await external result; do not mark DONE until resolved.
Next:complete external fixes, commit/push backend and create Draft PR; continue frontend Stage2.

## External round 1 disposition

Reviewer returned BLOCKER1 / IMPORTANT2 plus MINOR findings. Although the context requested backend, it also inspected UI; the UI blocker is accepted and corrected.
- BLOCKER: target form account loop shadowing selected account. Fixed with distinct names and balances UI regression (red→green).
- IMPORTANT: error listener swallowed/log-suppressed arbitrary Domain/InvalidArgument errors. Narrowed to explicit BalanceLedgerException/unique; expected business failures typed; priority -1 after logging; red→green listener tests.
- IMPORTANT: posting replayed entire company/account history under lock. Changed to current states + candidate delta and backwards future suffix. Changed aggregate targets only; guarded-history tests fail if old amounts read. Core30 tests74 assertions green including concurrent rollback.
- MINOR: permission check before lock now added with in-lock recheck; descendants excluded parent choices; invisible report rows omitted without changing totals; obsolete EquationPolicy and Facade choice method removed. Facade tree method retained as Company onboarding uses it; empty directories not Git content.
- Root additional IMPORTANT: failed ORM structure mutation survived DB rollback in memory. Entity manager clears on transactional failure; regression proves rejected type/parent change cannot leak into next flush. Structure+listener+policy16 tests38 assertions green.

Round2 launched on complete backend+frontend integrated diff; output site/var/external-review/balance-round2/review.txt. No green claim yet.

Delivery note: backend/frontend were prepared concurrently in the same isolated worktree. They are kept in one implementation commit after both reviews to avoid a checkpoint whose removed providers and old controllers cannot compile together. Stage verification and scope remain distinct; one commit per Stage is a norm rather than a requirement to publish a broken intermediate revision.
