## Current checkpoint

**Phase:** Stage 2 / Work item 2.1
**Status:** implementing
**Stage base commit:** `0658c9fc6a3c4c3519f6e254d6669200b6a38753`

### Completed
- Проверен scope, прочитаны frontend/Twig/workflow правила.
- Создана fix/customer-ui-legacy от свежего origin/master; дерево было чистым.
- Зафиксированы план и чек-лист приёмки; подняты отдельные локальные PHP/Node runner containers.

### Checks and baseline
- Baseline: 39 tests, 279 assertions — OK.
- Stage 1: red доказан; целевые 25 tests / 1311 assertions и модульные
  209 tests / 2922 assertions — OK; focused PHPStan и fixer — OK.

### Review status
- internal: Stage 1 — BLOCKER 0, IMPORTANT 0
- external: pending; `claude` пока не найден в PATH, проверить до handoff.

### Exact next action
- Добавить regression tests legacy-оболочки API и отсутствия переключателя.

### Files to inspect first on resume
- site/src/Shared/Service/UiModeResolver.php
- site/src/Shared/Controller/UiModeController.php
- site/src/Finance/Controller/HomeController.php
- site/tests/Functional/Finance/HomeUiModeTest.php
