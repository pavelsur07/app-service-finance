## Current checkpoint
**Phase:** handoff / stopped (external reviewer environment)
**Status:** stopped
**Stage base commit:** c0f68e7b9a98b7175ccff01a5f2014a66c37ef0b
**Branch:** feat/balance-legacy-ui. Один коммит и один Draft PR; фактические SHA/URL — в итоговом сообщении и GitHub.

### Completed
- Все три Stage локально реализованы; approved plan, Stage Reports, browser acceptance, handoff сохранены.
- Legacy shell/tabs/menu/errors; Tabler cards/tables/status/KPI/tree/filters/pager; Bootstrap forms и shared confirmation с сохранёнными CSRF/version/archive. Код финансов, прав, маршрутов/API не менялся.
- Mobile collapse исправлен только для balance_*; subtitle отделён от заголовка.
- Balance финально OK 38/530. Logging-тест адаптирован к modal trigger; Logging + Balance OK 40/541.
- Финальный browser smoke на повторном рендере из исходников: 40/40 (1440/375), menu/More/presets/rows/totals/confirm/focus/scroll; JS errors 0.
- Свежий внутренний агент и допроверки: BLOCKER/IMPORTANT/MINOR 0/0/0; открытых находок нет.

### Checks and baseline
- Baseline Balance 31/248. Red для shell 1, фильтров/таблиц 2, темы 2; green после правок.
- make site-stan OK 4268; site-cs-twig OK 406; PHP style/strict types OK 4304; unit OK 4819/26765; focused CS и PHPStan последней адаптации logging-теста проверены.
- frontend lint/build/test:unit OK 152 теста; JS 123920 bytes unchanged, CSS +700 bytes (1.45%).
- Full gate: исправлены локальные ошибки прав (UID1000 против root-owned vendor/storage/log). Полный повтор под UID0: 9262/52066, 1 failure (logging-тест искал старую HTML-форму). Адаптация к helper подтверждена green 40/541; весь набор после неё не повторялся. Подробности команд и границ — handoff.md.

### Review status
- internal: fresh independent review + follow-ups, open none.
- external: 0 завершённых раундов, preflight exit 2 `ревьюер 'claude' не установлен`. Бинарь/стандартная конфигурация/переменные авторизации отсутствуют. REVIEW_GREEN не заявляется; исправленный запуск невозможен без настройки инструмента.

### Exact next action
- Проверить git status, коммит и Draft PR ветки по итоговому сообщению; не создавать второй PR.
- После настройки Claude Code: site/bin/external-review.sh c0f68e7b9a98b7175ccff01a5f2014a66c37ef0b --effort medium --context site/var/balance-review-context.md; обработать findings по policy. При утрате ignored context восстановить из handoff (без секретов).
- Обновить один PR, Ready только после обязательного review; затем единственный запрос merge + deploy. Сейчас merge/deploy не выполнялись.

### Files to inspect first on resume
- handoff.md; TASK.md; git status/diff; site/var/balance-final-regression.log; site/var/balance-full-tests-root.log; site/var/balance-browser-final.log.
