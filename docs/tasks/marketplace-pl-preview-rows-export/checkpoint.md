## Current checkpoint

**Phase:** handoff
**Status:** ready for merge and deploy
**Stage base commit:** f47d1760e

### Completed
- Phase 0: `plan.md`, baseline.
- Stage 1 — Action по каналам (42 tests OK).
- Stage 2 — Exporter: «Данные», «Паспорт».
- Stage 3 — «Покрытие», «Реклама», «Предупреждения» (49 tests OK).
- Stage 4 — Controller `/marketplace/pl-preview/rows-xlsx`.
- Stage 5 — меню «Скачать xlsx» с двумя форматами.
- Stage 6 — ARCHITECTURE, гейты, ревью (внутреннее + Codex REVIEW_GREEN).

### Checks and baseline
- Baseline 2026-10-20: `phpunit tests/Integration/Marketplace/PlPreview tests/Functional/Marketplace/Controller/PlPreviewControllerTest.php` — OK, 35 tests / 128 assertions. БД `app_test_rowsx`, сеть `app-service-finance_default`.

### Review status
- internal: финальное (свежая сессия) — open: none
- external: Codex round 1 — REVIEW_GREEN

### Exact next action
- Ждём «merge and deploy». Миграций нет. После деплоя — критерий ТЗ 7.

### Files to inspect first on resume
- `plan.md`, `stages/stage-1.md`
