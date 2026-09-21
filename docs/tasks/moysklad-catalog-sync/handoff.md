# moysklad-catalog-sync — handoff

**Branch:** feat/moysklad-catalog-sync · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2497 · **CI:** green

## Summary of stages

- Stage 1 — tenant-scoped Product/Variant schema, parser, normalized documented fixture and architecture.
- Stage 2 — full paged import, separate cursors/runs, page transactions, source-drift detection, bounded Messenger retry and safe errors.
- Stage 3 — authorized manual launch, separate product/variant status, counters, history and stale-run recovery.

## Files changed

- site/src/MoySklad/ — catalog entities, snapshots, parser, repositories, action, message/handler, controller and status query.
- site/migrations/Version20260920160000.php — product and variant tables with tenant and parent FKs.
- site/templates/moy_sklad/connections/index.html.twig — launch and status UI.
- site/tests/{Unit,Integration,Functional}/MoySklad and site/tests/Fixtures/MoySklad — contract, failure, tenant and UI coverage.
- ARCHITECTURE.md, docs/tasks/moysklad-catalog-sync/ — decisions and evidence.

## Migrations

- Version20260920160000 creates two empty tables and indexes; it does not transform existing data.
- down() is reversible while both new tables are empty. With imported rows it deliberately refuses rollback, so recovery is code rollback with tables retained until a separate data decision. No backup is needed before creation of empty tables.

## Public API / contract changes

- No public API. Adds an authenticated internal POST route and an async_sync message.

## Checks

- make site-stan — no errors.
- make site-cs-check, make site-cs-strict-types — clean.
- make site-test-unit — 2965 tests, 16341 assertions, 4 existing deprecations.
- make site-test — 5125 tests, 29201 assertions, 6 deprecations.
- GitHub CI — migrations-empty-db, PHP style, API types, static analysis, unit, integration, all three functional shards and production image builds pass.
- Frontend npm run lint and npm run build pass. Global UI Kit checks remain on the pre-existing baseline: 8977 legacy class usages and 47 missing wrappers; this task changes no React or UI Kit source.

## Reviews

- Internal: Stage reviews plus final fresh-context review; final BLOCKER 0 / IMPORTANT 0 / MINOR 0.
- External: Stage 1 REVIEW_GREEN; Stage 2 two rounds with BLOCKER 1 / IMPORTANT 2 / MINOR 4 fixed; final round has no confirmed BLOCKER/IMPORTANT. One false IMPORTANT rejected against official String(255) schema, one tenant-test MINOR fixed, one trusted-host MINOR rejected.

## Risks, limitations, follow-ups

- The test account has no variants. The non-empty fixture follows the pinned official contract; live variant order=id,asc remains unconfirmed. Runtime monotonicity and size guards fail safely without advancing the cursor, with bounded retry.
- After deploy, the administrator starts catalog import explicitly from the connection page. Production API reads happen only then through the existing encrypted token.

## Owner decision

Ready: PR #2497 "feat(moysklad): sync products and variants" — the PR adds migration Version20260920160000: creates empty tables, no existing data transformation, reversible only while empty, no backup required.
Run the production migration and deploy?
Reply: "run the migration and deploy #2497"
