## Current checkpoint

**Phase:** handoff (часть 2, этапы 3–5) — ревью исправлено, тесты зелёные, PR
**Status:** Stage 5 done; часть 1 (этапы 0–2) на проде с 04.11 (#2874)
**Stage base commit:** `cf083d2e7` (ветка `feat/pl-budget-plan-fact`)

### Completed
- Phase 0: `plan.md`, решения Владельца в `TASK.md`, `ui-ux.md` (ТЗ на экраны).
- 0.1 `PLCategoryDTO::$type`; 0.2 Enum/Entity/Builders; 0.3 миграция
  `Version20261104090000` + `BudgetSchemaExtension`; 0.4 создание/копия;
  0.5 смена статуса; 0.6 сохранение ячеек; 0.7 `ARCHITECTURE.md`.

- Stage 1: доступ, `calculatePlTree`, сетка, страницы и JSON API, DS раздела
  ОПиУ, меню «Доходы и расходы» — `stages/stage-1.md`.

- Stage 2: React-сетка `budget-grid`, `apiFetch`, `BudgetGridRow::$format`,
  решения Владельца 5–7 — `stages/stage-2.md`.

- Stage 3: факт по месяцам (`getPlFactAmountsByMonth`), план–факт API,
  `BudgetVariance`, «Итог года» в списке — `stages/stage-3.md`.

- Stage 4: вкладка «План–факт» (Twig), кварталы и «с начала года» —
  `stages/stage-4.md`.

- Stage 5: помощники заполнения (`/fill`, панель в сетке) —
  `stages/stage-5.md`.

### Checks and baseline
- baseline: `AssignTariffActionTest` — OK (6).
- Budget + Finance + Shared/Doctrine — OK (430 тестов), Budget — 53.
- Stage 1: связанный набор — OK (558).
- Stage 2: Budget — OK (70); lint/build зелёные; smoke со скриншотами.
- PHPStan по изменённым файлам — OK; baseline −7 (PLCategoryFacade).
- php-cs-fixer (`.php-cs-fixer.php`, без кеша) — OK.

### Review status
- internal: iteration 2, open: none (исправлены обход «только владелец» при
  гонке и успех повторного утверждения участником).
- external: round 1 — IMPORTANT (регистр UUID в ключе блокировки) исправлен, fixed without re-run.

### Exact next action
- Stage 2 закоммичен; написать `stages/stage-3.md` (план–факт backend + итог года), затем TDD.

### Files to inspect first on resume
- `docs/tasks/pl-budget/stages/stage-0.md`, `site/src/Budget/`.

### Test environment
- стенд smoke: `asfb-fpm` + `asfb-nginx` в `asfb-net`, база `app_dev` в `asfb-pg`
  (фикстуры, владельцу выдан `ROLE_COMPANY_OWNER` — только в этой базе);
  скрипты puppeteer — в `$CLAUDE_JOB_DIR/tmp/shot`.
- запуск: `site/var/pl-budget/t.sh <команда>`; своя пара `asfb-pg` / `asfb-redis` в сети `asfb-net`; после задачи —
  `docker rm -f asfb-pg asfb-redis asfb-fpm asfb-nginx; docker network rm asfb-net`.
