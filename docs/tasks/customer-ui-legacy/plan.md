# customer-ui-legacy: пользовательские страницы на legacy

Класс: Large (несколько модулей, изменение UI и совместимости endpoint).
База задачи: `0658c9fc6a3c4c3519f6e254d6669200b6a38753`.
Baseline: `docker exec -e XDEBUG_MODE=off vf-customer-legacy-php php bin/phpunit --filter 'UiModeResolverTest|HomeUiModeTest|UiModeControllerTest|ApiSettingsControllerTest|ApiPermissionsControllerTest'`
— OK, 39 tests, 279 assertions, 6.972 s.

## Stage 1: backend — единственный пользовательский режим

Risk: HIGH-LOCAL (совместимость POST endpoint).
stage_base_commit: `0658c9fc6a3c4c3519f6e254d6669200b6a38753`.
Definition of Done:
- `/finance` всегда рендерит существующий legacy-dashboard.
- Старые cookie app/vf и POST app безопасно приводятся к legacy.
- Сохранены CSRF, redirect-защита, периоды, KPI и фильтры.
Work items:
- 1.1 — regression tests, доказать red на прежнем коде.
- 1.2 — resolver, controller compatibility, выбор dashboard; green и self-review.
Stage checks: UiModeResolverTest, UiModeControllerTest, HomeUiModeTest,
HomeCashflowActivityTest; PHP lint/fixer изменённых PHP-файлов.
Reviewer focus: CSRF, redirect, cookie flags, financial filter preservation.

## Stage 2: frontend — Tabler для API и завершение переключения

Risk: MEDIUM.
stage_base_commit: `fe29a8f8d`.
Definition of Done:
- Все экраны API, включая результат и ошибки, наследуют legacy-shell.
- Формы, таблицы, права, пагинация и пустые состояния используют Tabler.
- Переключатель отсутствует; admin/apps и общие ресурсы админки сохранены.
Work items:
- 2.1 — regression tests оболочки и загрузки скриптов всех API-экранов.
- 2.2 — Twig/layout/form theme, удаление переключателя и мёртвых пользовательских шаблонов.
- 2.3 — module tests, frontend checks, desktop/mobile smoke, self-review.
Stage checks: Functional/Api, Functional/Finance, Functional/Shared/Controller;
Twig lint; frontend lint/build/test:unit.
Reviewer focus: scripts parent block, CSRF, one-time secret, permissions matrix,
keyboard, responsive tables, no shared kit/admin regressions.

## Stage 3: handoff

Risk: LOW.
stage_base_commit: записать перед началом.
Definition of Done:
- Итоговый аудит не находит активных пользовательских наследников app-shell.
- Пройдены полные гейты; внутреннее ревью в свежей сессии и внешнее Claude review.
- Один Ready PR с проверенной базой master и отчётом.
Work items:
- 3.1 — аудит scope, гейты, независимое внутреннее ревью.
- 3.2 — external review, исправления, handoff и Ready PR.
Stage checks: make site-stan site-cs-check site-cs-strict-types site-test-unit;
make site-test; make site-cs-twig; frontend lint/build/test:unit.
Reviewer focus: полный diff, отсутствие admin/apps изменений, сохранение поведения.

Production и миграции не входят в реализацию; миграций не требуется.
