## Current checkpoint

**Phase:** Stage 1 / planning
**Status:** planned
**Stage base commit:** `ecf23ca53`

### Completed
- Phase 0: `plan.md`, решения Владельца в `TASK.md`, `ui-ux.md` (ТЗ на экраны).
- 0.1 `PLCategoryDTO::$type`; 0.2 Enum/Entity/Builders; 0.3 миграция
  `Version20261104090000` + `BudgetSchemaExtension`; 0.4 создание/копия;
  0.5 смена статуса; 0.6 сохранение ячеек; 0.7 `ARCHITECTURE.md`.

### Checks and baseline
- baseline: `AssignTariffActionTest` — OK (6).
- Budget + Finance + Shared/Doctrine — OK (430 тестов), Budget — 53.
- PHPStan по изменённым файлам — OK; baseline −7 (PLCategoryFacade).
- php-cs-fixer (`.php-cs-fixer.php`, без кеша) — OK.

### Review status
- internal: iteration 2, open: none (исправлены обход «только владелец» при
  гонке и успех повторного утверждения участником).
- external: round 1 — IMPORTANT (регистр UUID в ключе блокировки) исправлен, fixed without re-run.

### Exact next action
- Stage 0 закоммичен; написать `stages/stage-1.md` (план + чек-лист), затем TDD.

### Files to inspect first on resume
- `docs/tasks/pl-budget/stages/stage-0.md`, `site/src/Budget/`.

### Test environment
- запуск: `site/var/pl-budget/t.sh <команда>`; своя пара `asfb-pg` / `asfb-redis` в сети `asfb-net`; после задачи —
  `docker rm -f asfb-pg asfb-redis; docker network rm asfb-net`.
