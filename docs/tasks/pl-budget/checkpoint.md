## Current checkpoint

**Phase:** Stage 2 / planning
**Status:** Stage 1 done
**Stage base commit:** `b19c6a138`

### Completed
- Phase 0: `plan.md`, решения Владельца в `TASK.md`, `ui-ux.md` (ТЗ на экраны).
- 0.1 `PLCategoryDTO::$type`; 0.2 Enum/Entity/Builders; 0.3 миграция
  `Version20261104090000` + `BudgetSchemaExtension`; 0.4 создание/копия;
  0.5 смена статуса; 0.6 сохранение ячеек; 0.7 `ARCHITECTURE.md`.

- Stage 1: доступ, `calculatePlTree`, сетка, страницы и JSON API, DS раздела
  ОПиУ, меню «Доходы и расходы» — `stages/stage-1.md`.

### Checks and baseline
- baseline: `AssignTariffActionTest` — OK (6).
- Budget + Finance + Shared/Doctrine — OK (430 тестов), Budget — 53.
- Stage 1: связанный набор — OK (558).
- PHPStan по изменённым файлам — OK; baseline −7 (PLCategoryFacade).
- php-cs-fixer (`.php-cs-fixer.php`, без кеша) — OK.

### Review status
- internal: iteration 2, open: none (исправлены обход «только владелец» при
  гонке и успех повторного утверждения участником).
- external: round 1 — IMPORTANT (регистр UUID в ключе блокировки) исправлен, fixed without re-run.

### Exact next action
- Stage 1 закоммичен; написать `stages/stage-2.md` (React-сетка, `ui-ux.md` §3), затем TDD.

### Files to inspect first on resume
- `docs/tasks/pl-budget/stages/stage-0.md`, `site/src/Budget/`.

### Test environment
- запуск: `site/var/pl-budget/t.sh <команда>`; своя пара `asfb-pg` / `asfb-redis` в сети `asfb-net`; после задачи —
  `docker rm -f asfb-pg asfb-redis; docker network rm asfb-net`.
