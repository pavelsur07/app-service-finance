## Current checkpoint

**Phase:** handoff
**Status:** done
**Stage base commit:** (после коммита Stage 1)

### Completed
- Stage 1 — общие формулы сумм (`MarketplacePlAmountSql`).
- Stage 2 — `FinanceFacade::calculatePlPreview()`.
- Stage 3 — `PlPreviewFactsQuery`.
- Stage 4 — покрытие затрат и продаж, сверка.
- Stage 5 — `BuildMarketplacePlPreviewAction`.
- Stage 6 — экран `/marketplace/pl-preview`.
- Stage 7 — выгрузка xlsx.

### Checks and baseline
- Baseline 26 tests OK; после Stage 1 — 35 tests OK. Тесты: своя пара asf9-pg/asf9-redis.

### Review status
- internal: Stage 1 — open: none
- external: round 1, IMPORTANT 1 — fixed without re-run

### Exact next action
- Ждём «merge and deploy». Миграций нет.

### Files to inspect first on resume
- `docs/tasks/marketplace-pl-preview-by-tags/plan.md`, `stages/`
