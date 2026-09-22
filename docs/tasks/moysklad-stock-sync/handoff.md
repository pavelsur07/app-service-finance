# moysklad-stock-sync Stage 1 — handoff

**Branch:** `feat/moysklad-stock-sync` · **PR:**
[#2498](https://github.com/pavelsur07/app-service-finance/pull/2498) ·
**CI:** merge only after all required checks are green

## Summary of stages

- Stage 1 — API contract, sanitized fixtures, strict parsers and tenant-scoped
  immutable storage for MoySklad stores and stock snapshots.
- Stages 2 and 3 — sync orchestration, Messenger and admin status UI remain
  follow-up work in separate branches by owner decision.

## Files changed

- `site/migrations/Version20260921100000.php` — three tables, constraints,
  indexes and immutability triggers.
- `site/src/MoySklad/` — page DTO/parsers, domain snapshots, entities and
  repositories.
- `site/tests/` — sanitized fixtures, builders and unit/integration coverage.
- `ARCHITECTURE.md`, `docs/tasks/moysklad-stock-sync/` — architecture,
  contract, checkpoint and Stage report.

## Migrations

- `Version20260921100000` is schema-only and touches no row data.
- Down migration is safe while the new tables are empty; after rows exist it
  deliberately refuses rollback and requires a separate data decision.
- Creating `uniq_moysklad_variants_tenant_external` is non-concurrent and can
  briefly block writes to the existing variants table during migration.

## Public API / contract changes

- No HTTP endpoint or cross-module Facade is added in Stage 1.
- Internal contract adds strict Store and Stock page parsing and storage.

## Checks

- `make site-stan` — PASS.
- `make site-cs-check` — PASS.
- `make site-cs-strict-types` — PASS.
- `make site-test-unit` — 3006 tests, 16453 assertions, PASS.
- `make site-test` — 5174 tests, 29330 assertions, PASS.
- current MoySklad module — 201 tests, 797 assertions, PASS.
- migration down/up, guarded rollback and mapping validation — PASS.

## Reviews

- internal: 7 iterations; 0 BLOCKER, 9 IMPORTANT fixed; `REVIEW_GREEN`.
- external: 1 round; 0 BLOCKER, 1 IMPORTANT fixed; fixed without re-run.

## Risks, limitations, follow-ups

- Production migration may briefly lock writes to `moysklad_variants` while
  building one unique index.
- Stage 1 does not call the MoySklad API or populate the new tables.
- Stage 2 will implement full store/stock loading, retries, cursors and runs.
- Stage 3 will expose manual launch and status in the admin UI.

## Owner decision

Merge after green CI is already approved for Stage 1. Production migration and
deploy remain a separate manual decision required by the release workflow.

Ready: PR #2498 "feat(moysklad): model stores and stock snapshots" adds
`Version20260921100000` (schema-only; guarded rollback; brief variants write
lock). Run the production migration and deploy?

Reply: `run the migration and deploy #2498`
