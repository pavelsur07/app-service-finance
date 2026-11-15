## Current checkpoint

**Phase:** handoff / owner decision
**Status:** done
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
  seed tariffs. Для контрольного полного прогона создана app_customer_ui_legacy.
- Диагностика на отдельной app_customer_ui_diag: первое integration test
  падает на записи var/storage/cash-file-imports (root-owned directory vs UID 1000).
  Не меняя прав, исправлен пользователь локального test runner: user 0.
  Запущен background full suite; предыдущий non-root изолированный прогон
  остановлен после доказательства причины. Старый make-run завершился 143
  без итоговой сводки; зелёным не считается.
- CI source 6f380a39e: все три functional shards, integration, unit, PHPStan,
  style и real-auth SPA job — pass; все CI checks pass/skipped.
- Итоговый полный прогон: 9271 tests / 52215 assertions, 07:45.022, exit 0.
  Runtime helper и лог в ignored site/var/customer-ui-smoke/.
- После проверок task-owned DB app_customer_ui_legacy/app_customer_ui_diag и
  disposable containers vf-customer-legacy-php/node/browser удалены. Исходные
  test DB и PostgreSQL/Redis services не удалялись.

### Review status
- internal: Stage 1 — 0/0; Stage 2 — IMPORTANT 1 fixed; финальное независимое
  review — IMPORTANT 1 fixed. Суммарно BLOCKER 0 / IMPORTANT 2 fixed, открытых нет.
- external: REVIEW_GREEN, exit 0. По явному поручению владельца reviewer Codex
  в отдельной ephemeral read-only сессии; один завершённый раунд, B0/I0.
  Первый Codex запуск не завершил проверку из-за bwrap; исправленный повтор
  проверил полный diff + dossier 52 файлов через stdin без shell.
  Report: site/var/external-review/0658c9fc-codex-stdin/review.txt;
  session 01a1e076-ec7d-7741-9f9f-560653ce0ea7. Ранее Claude preflight не запустился.

### Exact next action
- Все проверки и review завершены; PR #3021 переводится в Ready.
- Ожидать owner decision: "merge and deploy #3021". После него выполнить
  весь release pipeline по AGENTS.md, без дополнительных вопросов по этапам.
- Merge/deploy не выполнялись. Политика reviewer изменена только для этой задачи
  явным поручением владельца; глобальные инструкции не менялись.
- Source review fixes: 6f380a39e; полный audit и все runnable checks завершены.

### Files to inspect first on resume
- site/src/Shared/Service/UiModeResolver.php
- site/src/Shared/Controller/UiModeController.php
- site/src/Finance/Controller/HomeController.php
- site/tests/Functional/Finance/HomeUiModeTest.php
