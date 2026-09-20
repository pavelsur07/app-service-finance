# moysklad-data-sync — handoff

**Branch:** `feat/moysklad-counterparty-sync` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2495

## Summary of stages

- Stage 1 — three tenant-scoped tables, composite FK to connection, unique keys, UTC millisecond timestamps and guarded migration rollback.
- Stage 2 — bounded read-only API page client and strict parser of sanitized counterparty fixtures.
- Stage 3 — two full page passes with page transactions, cursor only on success, advisory lock, safe errors and bounded Messenger retries.
- Stage 4 — manual authorized CSRF POST and bounded history/status on the connection page.

## Files changed

- `site/migrations/Version20260920110000.php`; `site/src/MoySklad/` entities, repositories, API, parser, Action, Message, Handler, Controller and Query.
- `site/templates/moy_sklad/connections/index.html.twig`, `site/config/packages/{doctrine,messenger}.yaml`, `site/src/Shared/Infrastructure/Doctrine/` UTC timestamp type.
- `site/tests/Unit/MoySklad`, `site/tests/Integration/MoySklad`, `site/tests/Functional/MoySklad`, `ARCHITECTURE.md`, Stage reports.

## Migrations

- `Version20260920110000` adds three tables and a composite unique key on existing `moysklad_connections`. It does not alter existing rows. `down` locks the new tables and succeeds only when all are empty; after import it refuses to drop data. Empty `up → down → up` and refusal with a synthetic run were verified locally. The production migration requires a separate manual dispatch before deploy. A data-bearing rollback requires a separately approved recovery decision.

## Public API / contract changes

- No external public API and no write to МойСклад. An internal authenticated POST route queues a read-only import. Other modules still have no access to imported records until a future Facade contract is designed.

## Checks

- Clean migration-only test DB: `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit` (2945 / 16289), `make site-test` (5079 / 28965) — PASS before final-review fixes.
- After fixes: MoySklad integration/functional and UTC type — 66 tests / 356 assertions PASS. Handoff `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit` (2945 / 16289), and `make site-test` (5090 / 29044) PASS. Twig lint and Doctrine metadata mapping PASS.
- An earlier full test run had 18 unrelated Cash/Company failures because `site-test-db-rebuild` loads demo fixtures; rebuilding migration-only test DB resolved them. A later full run had 5088 tests / 29030 assertions with one unrelated, intermittent `ApiKeyActionsTest` failure: its raw SQL has no `ORDER BY` but asserts the first row's name. The exact test passed on a targeted rerun (1 / 13); no Api code changed in this PR. Global schema synchronicity validation has pre-existing drift; mapping is valid.

## Reviews

- Stage 1 external: 1 round, 0 BLOCKER / 2 IMPORTANT / 4 MINOR; fixed without re-run. Stage 3 external: 1 round, 0 / 2 / 3; fixed without re-run.
- Final internal round 1: 1 BLOCKER / 5 IMPORTANT / 1 MINOR; round 2: 0 / 1 / 0. All addressed with targeted evidence. Round 3 and final review after external fixes both returned `REVIEW_GREEN`.
- External handoff: 1 round, 0 BLOCKER / 1 IMPORTANT / 3 MINOR. Explicit zero-initialized time parsing and whole-second DB/repeat-scan tests address the IMPORTANT, although PHP 8.4 already returned `.000000` in direct reproduction. All three MINOR findings fixed: safe exception class in logs, queue button hidden while running, shared error category whitelist. Targeted tests passed; per review policy, fixed without re-run.

## Risks, limitations, follow-ups

- MoySklad `offset` pagination is not a consistent snapshot while source data changes; subsequent full scans converge. Missing rows never cause local deletion. Scheduling of automatic runs, other entity types, financial rules and Facade access are separate work.
- Stage 0 specification is in documentation PR #2493, which remains separate from this implementation PR.

## Owner decision

All local gates and reviews are complete. PR carries a migration, so approval at Ready handoff must cover the manual production migration dispatch and subsequent deploy together.
