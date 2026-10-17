## Current checkpoint

**Phase:** Stage 5
**Status:** planned
**Stage base commit:** (после коммита Stage 1)

### Completed
- Stage 1 — общие формулы сумм (`MarketplacePlAmountSql`).
- Stage 2 — `FinanceFacade::calculatePlPreview()`.
- Stage 3 — `PlPreviewFactsQuery`.
- Stage 4 — покрытие затрат и продаж, сверка.

### Checks and baseline
- Baseline 26 tests OK; после Stage 1 — 35 tests OK. Тесты: своя пара asf9-pg/asf9-redis.

### Review status
- internal: Stage 1 — open: none
- external: pending (handoff)

### Exact next action
- Stage 5: план в `stages/stage-5.md`, затем `BuildMarketplacePlPreviewAction`.

### Files to inspect first on resume
- `docs/tasks/marketplace-pl-preview-by-tags/plan.md`, `stages/`
