## Current checkpoint

**Phase:** Stage 1
**Status:** complete, pending commit
**Stage base commit:** `44b28304d27c5e1025e8c29230d75b928a36681f`
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
- Symfony container lint, task-scoped PHP CS Fixer, PHP syntax lint, and
  `git diff --check`: green.
- Production evidence is recorded in `plan.md`; no production action was run
  during implementation.

### Review status

- Internal review: green.
- External Claude review: `REVIEW_GREEN`; its final safe MINOR findings were
  resolved by consolidating all four invariants in the DTO contract and adding
  same-company/raw-document isolation plus fail-closed unallocated-line tests.
- unresolved findings: none

### Exact next action

- Commit and push Stage 1, update the Draft PR, then begin Stage 2.

### Files to inspect first on resume

- `docs/tasks/wb-ad-daily-spend-hardening/plan.md`
- `site/src/MarketplaceAds/Application/LoadWbAdSpendDayAction.php`
- `site/src/MarketplaceAds/Infrastructure/Query/`
- `site/tests/Integration/MarketplaceAds/`
