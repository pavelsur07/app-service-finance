# Stage 2 — handoff, merge и деплой

## Какую задачу решаем

Довезти код Stage 1 до прода: PR, зелёный CI, merge, деплой; убедиться, что на проде
работает релиз с новым калькулятором.

## Что будет результатом

- PR из ветки `fix/wb-review-points-withheld` в `master` с описанием, замерами и ссылкой на #387.
- Зелёные обязательные проверки CI (unit, static analysis, CS, migrations — миграций нет).
- Merge и деплой по одобрению Владельца («делай все этапы автономно», 19.10).
- На проде релиз = merge-коммит.

## Что считаем выполненным

PR смержен, workflow деплоя завершился успешно, `SENTRY_RELEASE` / тег GlitchTip нового
релиза = merge-коммит, контейнеры прода здоровы (`codex-docker-ps`).

## Чек-лист готовности этапа

- [x] Ветка содержит только файлы задачи (`git diff --stat origin/master`), посторонние untracked не попали
- [x] PR создан, тело — что/зачем/проверка/после деплоя, атрибуция
- [x] CI: все обязательные job'ы зелёные (`gh pr checks`)
- [x] `mergeable = MERGEABLE`, без влития master без нужды
- [x] Merge выполнен, деплой (workflow по merge-коммиту) — success
- [x] Прод: контейнеры `healthy`, релиз = merge-коммит

## Итог этапа

Выполнено 19.10.2026. PR #2724, merge-коммит `5be32e0c`, CI зелёный (unit, integration,
functional 3/3, static analysis, CS), workflow «Deploy to Production» (run 39222757675) — success.
Прод: `site-php-fpm`, `site-nginx`, `site-php-cli` на образах `5be32e0c`, `healthy`.
