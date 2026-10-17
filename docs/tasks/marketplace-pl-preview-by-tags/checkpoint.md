## Current checkpoint

**Phase:** handoff
**Status:** planned
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
- external: pending (handoff)

### Exact next action
- Handoff: полные гейты, финальное ревью в свежей сессии, внешнее ревью Codex, `handoff.md`, push + PR. Стенд asf9-fpm/asf9-nginx и пару asf9-pg/asf9-redis убрать.

### Files to inspect first on resume
- `docs/tasks/marketplace-pl-preview-by-tags/plan.md`, `stages/`
