# moysklad-stock-sync Stage 2 — handoff

**Branch:** `feat/moysklad-stock-snapshot-loader` · **PR:**
[#2499](https://github.com/pavelsur07/app-service-finance/pull/2499) ·
**CI:** merge only after all required checks are green

## Summary of stages

- Stage 1 — released separately in PR #2498: schema, immutable storage and
  strict parsers.
- Stage 2 — complete store synchronization, immutable stock snapshot loading,
  recovery and bounded Messenger retry.
- Stage 3 — manual endpoint and UI remain separate work.

## Files changed

- `site/src/MoySklad/Application/` — stock sync action and store/stock runners.
- `site/src/MoySklad/Infrastructure/Api/MoySkladClient.php` — paginated Store
  and raw Stock report requests without automatic HTTP retry.
- `site/src/MoySklad/Message/`, `MessageHandler/` and Messenger config —
  `async_sync` message with bounded retry.
- `site/tests/` — HTTP, integration, recovery, tenant and Messenger coverage.
- `ARCHITECTURE.md`, task checkpoint and Stage report — internal contracts.

## Migrations

- None. Stage 2 uses the Stage 1 schema already present on `master`.

## Public API / contract changes

- No HTTP endpoint or cross-module Facade.
- Internal Messenger message `SyncStockSnapshotMessage` routes to `async_sync`.

## Checks

- MoySklad unit+integration — 246 tests, 1003 assertions, PASS.
- `make site-stan` — PASS, 2686 files.
- `make site-cs-check` — PASS, 2701 files.
- `make site-cs-strict-types` — PASS, 2701 files.
- `make site-test-unit` — 3023 tests, 16503 assertions, PASS.
- `make site-test` — 5221 tests, 29540 assertions, PASS.

## Reviews

- internal: 0 BLOCKER, 3 IMPORTANT and 1 MINOR found and fixed.
- external: round 1, 0 BLOCKER, 2 IMPORTANT and 3 MINOR; both IMPORTANT and
  two MINOR fixed; one MINOR rejected per explicit assortment-ID requirement;
  result `fixed without re-run`.

## Risks, limitations, follow-ups

- Remote pagination cannot provide a single source timestamp; size, duplicate
  and store-coverage guards prevent known incomplete snapshots.
- Stage 3 must add the manual endpoint and status UI before user-driven runs.
- Production synchronization is data processing outside the deploy pipeline
  and is not authorized or performed by this handoff.

## Owner decision

Ready: PR #2499 "feat(moysklad): sync store stock snapshots" — merge into
master with automatic production deploy?

Reply: "merge and deploy #2499"
