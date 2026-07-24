## Current checkpoint

**Phase:** Stage 3
**Status:** done — Release Gate
**Stage base commit:** `597c5514bba6832ab81d4cea4de9fbe62c907dae`
**Current Work item:** complete
**Owner gate:** yes

### Completed

- Phase 0 plan approved by the owner.
- Task branch `codex/wb-ad-daily-spend-hardening` created.
- Relevant MarketplaceAds, Marketplace catalog, logging, Money, and Docker
  patterns inspected.
- External Claude reviewer preflight succeeded without persisting auth output.
- Work item 1.1: tenant-scoped DBAL reconciliation query and Money DTO added.
- Work item 1.2: loader result, structured log, and command output expose
  persisted totals and missing-line amounts.
- Work item 1.3: exact invariants enforced; mismatch resets raw to `DRAFT` and
  fails the connection.
- Work item 1.4: command output, architecture and operations documentation,
  and regression coverage completed.
- Stage 1 committed and pushed as `26cb5ff0`.
- Draft PR #2233 created against `master`.
- Work items 2.1–2.2: persisted-unmapped-driven catalog refresh and one
  same-raw reprojection implemented with explicit recovery result fields.
- Work item 2.3: each Promotion API request has three total attempts for
  429/5xx, strict integer/RFC 7231 `Retry-After`, bounded waits, and
  structured retry/abandon logs.
- Work item 2.4: one bounded aggregated normal-channel application alert is
  emitted when `review_required > 0`.
- Work item 2.5: recovery, refresh failure, unresolved IDs, retry limits,
  delay parsing, no-retry 4xx, and bounded multi-connection alert regression
  coverage added.
- Work item 3.1: the production CLI runtime comments out only the broken
  opcache dynamic-load entry, following the existing development pattern;
  PHP-FPM is untouched.
- Work item 3.2: the production CLI image built successfully; `php -v`,
  `php -m`, Symfony command help, the real entrypoint, and `supercronic
  -version` completed without the opcache startup warning.
- Work item 3.3: operations and architecture documentation now describe the
  CLI-only runtime decision, local image acceptance, production acceptance,
  and the separate Production Gate for live checks and loads.
- Work item 3.4: internal review and three external review iterations are
  green; Stage Report and final handoff are prepared.

### Current diff / affected files

- `docs/tasks/wb-ad-daily-spend-hardening/plan.md` — approved plan.
- `docs/tasks/wb-ad-daily-spend-hardening/checkpoint.md` — this checkpoint.
- `site/src/MarketplaceAds/Application/DTO/WbAdSpendReconciliation.php`
- `site/src/MarketplaceAds/Infrastructure/Query/WbAdSpendReconciliationQuery.php`
- `site/src/MarketplaceAds/Exception/WbAdSpendReconciliationException.php`
- `site/src/MarketplaceAds/Application/DTO/WbAdSpendLoadResult.php`
- `site/src/MarketplaceAds/Application/LoadWbAdSpendDayAction.php`
- `site/src/MarketplaceAds/Command/WbAdDailySpendCommand.php`
- focused unit/integration tests and architecture/operations documentation.
- `site/docker/production/php-cli/Dockerfile` — CLI-only opcache startup fix
  with a fail-closed build assertion.
- `docs/tasks/wb-ad-daily-spend/operations.md` and `ARCHITECTURE.md` —
  acceptance procedure and shared CLI runtime contract.

### Checks and baseline

- Focused WB Ads/catalog baseline: 22 tests / 134 assertions, green on PHP
  8.3.31.
- Stage 1 focused query/action/command tests: 17 tests / 117 assertions,
  green.
- Full MarketplaceAds unit suite: 335 tests / 2128 assertions, green.
- Full MarketplaceAds integration suite: 173 tests / 697 assertions, green.
- Stage 2 focused action/client/command/catalog tests: 38 tests / 245
  assertions, green after the external-review fixes.
- Stage 2 MarketplaceAds unit suite: 346 tests / 2203 assertions, green after
  the external-review fixes.
- Stage 2 MarketplaceAds integration suite: 173 tests / 697 assertions, green.
- Symfony container lint, task-scoped PHP CS Fixer, PHP syntax lint, and
  `git diff --check`: green.
- Production evidence is recorded in `plan.md`; no production action was run
  during implementation.
- Stage 3 production CLI image build: green.
- Stage 3 final image smoke (`php -v`, `php -m`, Symfony help, real
  entrypoint, `supercronic -version`): green, no opcache warning.
- Stage 3 MarketplaceAds unit: 346 tests / 2203 assertions, green.
- Stage 3 MarketplaceAds integration: 173 tests / 697 assertions, green.
- Task-scoped PHP CS Fixer (11 PHP files), PHP lint, Symfony container lint,
  and `git diff --check`: green.
- `make site-test` remains blocked before PHPUnit by the pre-existing
  `bot_links.updated_at` test-schema drift in `Version20250219120000`.
- `make site-cs-check` reports 585 pre-existing repository-wide formatter
  violations; all task-owned PHP files pass the same formatter.

### Review status

- Internal review: green.
- External Claude review: `REVIEW_GREEN`; its final safe MINOR findings were
  resolved by consolidating all four invariants in the DTO contract and adding
  same-company/raw-document isolation plus fail-closed unallocated-line tests.
- Stage 1 unresolved findings: none.
- Stage 2 internal review: green after strict RFC 7231 parsing.
- Stage 2 external review iteration 1: one IMPORTANT and four safe MINOR
  findings confirmed and fixed.
- Stage 2 external confirmation review: `REVIEW_GREEN`; final documentation
  and nullable-status MINOR fixes also received `REVIEW_GREEN`.
- Stage 2 committed and pushed as `597c5514`; Draft PR #2233 updated.
- Stage 3 internal review: green; production PHP-FPM and shared configuration
  are unchanged.
- Stage 3 external review: three iterations, final `REVIEW_GREEN`; all safe
  in-scope MINOR findings were fixed.
- Stage 3 committed and pushed as `178f7ced`; Draft PR #2233 updated.
- Final internal whole-task review from
  `44b28304d27c5e1025e8c29230d75b928a36681f`: green.
- Final external whole-task review: first run reached the 40-turn limit; the
  prescribed narrowed retry at 80 turns completed with `REVIEW_GREEN` and no
  BLOCKER/IMPORTANT findings.

### Exact next action

- Owner decides whether to mark Draft PR #2233 Ready for review. Merge,
  deploy, production checks, and live WB loading remain separate and are not
  authorized.

### Files to inspect first on resume

- `docs/tasks/wb-ad-daily-spend-hardening/plan.md`
- `site/docker/production/php-cli/Dockerfile`
- `docs/tasks/wb-ad-daily-spend/operations.md`
- `ARCHITECTURE.md`
