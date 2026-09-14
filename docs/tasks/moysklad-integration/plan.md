# МойСклад — Implementation Plan

Spec: TASK.md. Base: 238dd997b27ecca5a1ae205fb417534e79de3439.
Architecture: extend existing MoySklad module, shared encryption and Symfony HttpClient; legacy Twig/Tabler forms with POST/redirect/GET.
Baseline: Shared security unit tests: 18 tests, 45 assertions, PASS. Functional/Twig baseline pending.

## Stage 1: Backend и безопасность
Risk: HIGH-LOCAL. stage_base_commit: 238dd997b27ecca5a1ae205fb417534e79de3439.
DoD: secure persistence, API outcomes, tenant-safe CRUD with CSRF, concurrency and tests.
- 1.1 API client, status enum/result, mocked HTTP tests.
- 1.2 Entity encryption/account/version, migration and secret codec; regression tests.
- 1.3 Scoped Actions/controllers/forms, rate limiting, optimistic concurrency; functional tests.
Checks: MoySklad unit/functional tests, focused PHPStan/style. Review: credentials, tenant scope, stale responses, duplicate account.

## Stage 2: Tabler UI
Risk: MEDIUM. Base recorded at start.
DoD: legacy menu, paginated connection cards, secure forms, all states, mobile and accessibility smoke.
- 2.1 Menu and list, existing route compatibility; no fake tabs.
- 2.2 Form interactions and verification feedback, functional rendering tests.
Checks: Twig lint, frontend lint/build, module functional tests.

## Stage 3: Перенос секретов и handoff
Risk: HIGH-LOCAL. Base recorded at start.
DoD: dry-run and idempotent execute command tested, release/backfill instructions, full gates and clean reviews.
- 3.1 Secret transfer command with atomic encryption/verification/clearing.
- 3.2 Full gates, fresh internal review, external Claude review, fix cycle, Ready PR.
Checks: make site-stan, site-cs-check, site-cs-strict-types, site-test-unit, site-test once at handoff.
External review: handoff plus HIGH-LOCAL stage exceeding 500 lines. One branch/Draft PR, no merge without named approval.

Ruling: use task branch in existing checkout, preserving unrelated untracked owner files, because running Docker test containers bind this checkout. No production or owner files touched.
