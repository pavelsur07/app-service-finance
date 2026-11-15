## Current checkpoint

**Phase:** Stage 3 / Work item 3.1
**Status:** implementing
**Stage base commit:** `f230a0670`

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
- Независимое внутреннее review: IMPORTANT 1 (FlashShellsTest) исправлен;
  red 2 LoaderError → green 2 tests / 20 assertions; reviewer подтвердил fix.
- CI выявил 2 устаревших test assertions: billing banner app layout и JS tags
  без frontend build. Исправлены; независимый reviewer подтвердил delta.
- На чистой изолированной локальной test DB после миграций: review fixes
  17 tests / 207 assertions — OK; полный suite запущен отдельно.
- Full gates: PHPStan, cs-check (4310 files), strict-types, unit
  (4822 tests / 26764 assertions), Twig (399 files) — OK.
- make site-test остановился в composer install на permission denied в
  vendor/phpstan/extension-installer/src/GeneratedConfig.php. При пропуске
  install с уже установленными locked deps suite запущен; в старой DB отсутствуют
  seed tariffs. Для контрольного полного прогона создана app_customer_ui_legacy_test.

### Review status
- internal: Stage 1 — 0/0; Stage 2 — IMPORTANT 1 fixed; финальное независимое
  review — IMPORTANT 1 fixed. Суммарно BLOCKER 0 / IMPORTANT 2 fixed, открытых нет.
- external: BLOCKED — external-review.sh exit 2: reviewer claude not installed.
  Проверены стандартные пути; локальной Claude авторизации также нет.
  REVIEW_GREEN не получен, PR должен оставаться Draft.

### Exact next action
- Дождаться full suite на isolated DB и завершения первого прогона; записать
  точные результаты, commit/push review fixes, обновить Draft PR #3021.
  External review блокирован окружением; не ставить Ready/merge.

### Files to inspect first on resume
- site/src/Shared/Service/UiModeResolver.php
- site/src/Shared/Controller/UiModeController.php
- site/src/Finance/Controller/HomeController.php
- site/tests/Functional/Finance/HomeUiModeTest.php
