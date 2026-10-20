## Current checkpoint

**Phase:** Stage 4
**Status:** in progress
**Stage base commit:** f47d1760e

### Completed
- Phase 0: `plan.md`, baseline.
- Stage 1 — Action по каналам (42 tests OK).
- Stage 2 — Exporter: «Данные», «Паспорт».
- Stage 3 — «Покрытие», «Реклама», «Предупреждения» (49 tests OK).

### Checks and baseline
- Baseline 2026-10-20: `phpunit tests/Integration/Marketplace/PlPreview tests/Functional/Marketplace/Controller/PlPreviewControllerTest.php` — OK, 35 tests / 128 assertions. БД `app_test_rowsx`, сеть `app-service-finance_default`.

### Review status
- internal: Stage 1–3 — open: none
- external: —

### Exact next action
- Stage 4: Controller и маршрут rows-xlsx.

### Files to inspect first on resume
- `plan.md`, `stages/stage-1.md`
