## Current checkpoint

**Phase:** Stage 2
**Status:** complete, pending commit
**Stage base commit:** `26cb5ff0b3faa36340bc6b259accfd7fcf0f9e06`
**Current Work item:** Stage gate
**Owner gate:** no

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

### Exact next action

- Commit and push Stage 2, update Draft PR #2233, then begin Stage 3.

### Files to inspect first on resume

- `docs/tasks/wb-ad-daily-spend-hardening/plan.md`
- `site/src/MarketplaceAds/Application/LoadWbAdSpendDayAction.php`
- `site/src/MarketplaceAds/Infrastructure/Query/`
- `site/tests/Integration/MarketplaceAds/`
