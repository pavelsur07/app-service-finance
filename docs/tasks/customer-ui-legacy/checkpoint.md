## Current checkpoint

**Phase:** Stage 3 / Work item 3.1
**Status:** implementing
**Stage base commit:** `fe29a8f8d`

### Completed
- Проверен scope, прочитаны frontend/Twig/workflow правила.
- Создана fix/customer-ui-legacy от свежего origin/master; дерево было чистым.
- Зафиксированы план и чек-лист приёмки; подняты отдельные локальные PHP/Node runner containers.

### Checks and baseline
- Baseline: 39 tests, 279 assertions — OK.
- Stage 1: red доказан; целевые 25 tests / 1311 assertions и модульные
  209 tests / 2922 assertions — OK; focused PHPStan и fixer — OK.
- Stage 2: red 3 failures; green 30 tests / 282 assertions; module 241 tests /
  3763 assertions; последняя правка permissions — 7 tests / 69 assertions.
- Frontend lint/build/unit (152 tests), focused Twig/PHP checks и Chromium
  smoke 18 экранов desktop/mobile — OK.

### Review status
- internal: Stage 1 — BLOCKER 0, IMPORTANT 0; Stage 2 — IMPORTANT 1 fixed,
  открытых BLOCKER/IMPORTANT нет.
- external: pending; `claude` пока не найден в PATH, проверить до handoff.

### Exact next action
- Запустить полные гейты; независимое внутреннее review полного diff.

### Files to inspect first on resume
- site/src/Shared/Service/UiModeResolver.php
- site/src/Shared/Controller/UiModeController.php
- site/src/Finance/Controller/HomeController.php
- site/tests/Functional/Finance/HomeUiModeTest.php
