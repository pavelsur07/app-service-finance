# retire-feature-flags — handoff (проверки завершаются)

Branch: `feat/retire-feature-flags` · PR: https://github.com/pavelsur07/app-service-finance/pull/3006
Base: `0e14475c9`. Миграций БД и новых зависимостей нет.

## Результат
- Stage 1: технический AI доступ по URL/token, компании по admin grant; SourceFacts
  без списка компаний с прежними принадлежностью/карантином/idempotency/ack-after-commit.
- Stage 2: Telegram без pilot; новые parse/edit/retry по grant; старые черновики
  manual/Confirm/delivery с финансовыми правами. Recovery UUID или --all;
  grants + pending Telegram + pending Cash events. Команда не создаёт платежи.
- Stage 3: шесть Variables не читаются; постоянный production запуск сервисов
  и payment worker; nightly отдельный флаг/профиль; короткий PHP cron --all.

## Основные файлы
`site/src/{ServiceGateway,SourceFacts,Telegram,Cash}`, regression tests,
`.github/workflows/deploy.yml`, `docker-compose.prod.yml`, `docker/cron/app.cron`,
DI/env templates, active architecture/runbooks и admin text copy.

## Проверки и review
- Deployment shell +6 runtime tests; Python 14 config tests; synthetic Compose;
  real local Compose nightly true/false; Twig lint/twigcs: PASS.
- Python ruff/format/mypy PASS; 540 pytest PASS, 61 skipped (нет AI_TEST_DB_DSN;
  pre-existing moved Symfony action path), 10 evals deselected, 2 sentry_sdk warnings.
- Full CS + strict: PASS 0/4272; full unit: 4804 tests /26738 assertions PASS.
- Final focused PHPStan PASS; Telegram/PaymentIntake: 184 tests /1304 assertions PASS.
- Full PHP tests: 9147 tests /51337 assertions PASS; full PHPStan: local worker OOM; in-process retry + CI pending.
- Internal: fresh independent BLOCKER 0 / IMPORTANT 1 fixed + author final IMPORTANT 1 fixed (Cash error reporting и сохранение queued результата после отзыва grant, RED→GREEN).
- После внешнего REVIEW_GREEN добавлен точечный grant guard для crash-window;
  регрессия RED→GREEN, Telegram/PaymentIntake и полный PHP набор PASS.
- External: 2 review points ×1 round; Stage 2 IMPORTANT 1 fixed without re-run;
  handoff REVIEW_GREEN (deployment diff дополнительно передан в context).
- Metrics: internal BLOCKER 0 / IMPORTANT 2; external BLOCKER 0 / IMPORTANT 1;
  все подтверждённые исправлены, открытых BLOCKER/IMPORTANT нет.

## Ограничения и follow-up
- Production и GitHub Variables пока не менялись.
- Один внешний MINOR отложен: при сбое Cash поздние намерения того же кабинета
  ждут следующего cron; Telegram и остальные кабинеты продолжаются. Per-draft
  перестройка retry не входит в цель удаления rollout-настроек.
- Монолитный full PHP запуск был killed (137) при 6771/9145 без assertion failure;
  полный набор повторён последовательными suite/штатными CI shards.
  PHPStan parallel worker timeout: повтор в последовательном режиме; CS 300s
  timeout — локальным COMPOSER_PROCESS_TIMEOUT=0. Эти адаптации не меняют CI.
- Ruling: текущий запрос реализации покрывает предусмотренные CI/Compose/cron изменения.
  Ruling: event-only Cash recovery нужен для завершённых Confirm после отзыва grant;
  он только доставляет событие уже записанных изменений, без AI и новых платежей.

## Решение владельца и выпуск
Один запрос: merge/deploy #3006, предусмотренный постоянный запуск/cron и удаление
ровно шести repository Actions Variables ПОСЛЕ успешного deploy и read-only приёмки:
AI_SERVICE_ENABLED, OZON_FETCHER_ENABLED, WB_FETCHER_ENABLED, SOURCE_FACTS_COMPANIES,
TELEGRAM_PAYMENT_INTAKE_ENABLED, TELEGRAM_PAYMENT_INTAKE_COMPANIES.
AI_SERVICE_EVAL_ENABLED, ECONOMICS_WEEKLY_REPORT_COMPANIES, прочие Variables и все Secrets сохраняются.

До deploy сохранить исходные шесть значений в защищённом операторском канале
для отката (не git/логи). Выпуск кода — при ещё существующих Variables;
после CI/deploy read-only приёмка через разрешённые vf-prod-codex wrappers;
затем удалить только разрешённые имена и проверить сохранность остальных.
Платных тестов, ручных production recovery/consume/backfill нет.
Для отката старого кода после удаления сперва восстановить сохранённые Variables.
