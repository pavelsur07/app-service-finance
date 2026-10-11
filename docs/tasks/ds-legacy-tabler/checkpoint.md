## Current checkpoint

**Phase:** handoff
**Status:** done — ждёт «merge and deploy»
**Stage base commit:** `3782e6f4` (master на старте задачи)

### Completed
- Stage 0 аудит — PR #2617 (`feat/ds-legacy-tabler`)
- Stage 1 токены — PR #2617
- Stage 2 компоненты — PR #2618 (`feat/ds-legacy-components`)
- Stage 3 деньги и даты — PR #2619 (`feat/ds-legacy-money`)
- Stage 4 пагинация, Tabs, таблицы — PR #2620 (`feat/ds-legacy-pagination-tabs`)
- Stage 5 пилот — `feat/ds-legacy-pilot`
- Ветки стекуются: каждая следующая от предыдущей, все PR в `master`, мержить по порядку.

### Checks and baseline
- baseline фронта: lint ✅ build ✅ check:ui-kit ❌ 9081 (pre-existing) mapping ❌ (pre-existing)
- после Stage 4: check:ui-kit 8836

### Review status
- internal: свежая сессия, IMPORTANT 2 — исправлены
- external: Codex, IMPORTANT 1 — исправлено, fixed without re-run

### Сверка с листами DS (2026-10-11)
- листы в `docs/design-system/sheets/`; правки в тех же ветках + merge вперёд по стеку
- `TODO(ds)` закрыты, кроме палитры графиков (на листах её нет)
- расхождения поручения с листами — аудит §7a

### Exact next action
- после «merge and deploy»: мерж #2617 → #2618 → #2619 → #2620 → #2623 по порядку, CI, деплой, приёмка

### Files to inspect first on resume
- `docs/tasks/ds-legacy-tabler/plan.md`, `stages/stage-*.md`, `docs/plan/ds-legacy-tabler-{audit,pages}.md`

### Environment notes
- локальному `owner@app.ru` выдан `ROLE_COMPANY_OWNER` для smoke — вернуть `["ROLE_ADMIN"]` в конце
- phpunit через `docker compose exec site-php-fpm` пишет кэш от root → `make site-stan` падает на прогреве; chown 1000 через тот же контейнер
