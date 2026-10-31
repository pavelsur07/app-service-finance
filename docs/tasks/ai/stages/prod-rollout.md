# Вывод ai-service в прод

Решение Владельца 31.10.2026: этап 3 отложен, сначала вывод сервиса в прод.
Образец — выкат ozon-fetcher (`docs/maintenance/ozon-fetcher-prod.md`).

Изменения прод Docker/CI/CD, cron и секретов — `AGENTS.md` §3.3: мерж этого PR —
отдельное явное одобрение Владельца; включение — его же действия (секреты,
переменные, ключи провайдеров).

## Задача

Сервис и Symfony-часть ИИ существуют только в коде. Подготовить выкат так,
чтобы после мержа на проде ничего не изменилось, а включение сводилось к
секретам и переменным GitHub: `AI_SERVICE_ENABLED=true` — деплой поднимает
сервис; allowlist компаний — Symfony начинает ставить задачи.

## Результат

- **CI:** гейты сервиса (ruff, format, mypy, pytest без evals — с PostgreSQL) на
  изменениях `services/ai-service/**`; образ `ghcr.io/pavelsur07/ai-service`
  собирается вместе с остальными на master (деплой тянет образы по SHA).
- **Прод-compose:** `ai-service` (API) и `ai-service-worker` за профилем
  `ai-service`, только сеть `default`, лимиты памяти; переменные Symfony
  `AI_SERVICE_*` в PHP-сервисах и scheduler; убраны мёртвые `LLM_API_*`.
- **Деплой:** при `AI_SERVICE_ENABLED=true` — роль `svc_ai_service` и БД
  `ai_service` (`docker/ai-service/db-bootstrap.sql`, идемпотентно), миграции
  сервиса, запуск API и воркера с ожиданием healthy; проверка секретов. Без
  флага — шаг пропускается, Symfony видит сервис выключенным.
- **GlitchTip:** сервис шлёт ERROR в свой проект (`AI_SERVICE_SENTRY_DSN`;
  пусто — только лог).
- **Cron:** `app:ai-service:import-results` каждые 5 минут;
  `app:cash:ai-categorize --all-enabled` раз в час — компании allowlist с
  операциями без статьи. Выключено → обе команды выходят с 0, ничего не делая.
- **Runbook** `docs/maintenance/ai-service-prod.md`: секреты, включение по шагам
  (echo → одна компания в режиме подсказок → остальные), проверка, откат,
  ротация.

## Что считаем выполненным

- тесты: `--all-enabled` (выбор компаний, сбой одной компании не останавливает
  остальные, конфликт опций), запрос компаний; гейты сервиса и Symfony;
- `docker compose -f docker-compose.prod.yml config` валиден с профилем и без;
- `actionlint`/YAML валиден; образ сервиса собирается локально, `migrate` и
  `/health` работают на чистой БД;
- `ARCHITECTURE.md` (cron, конфигурация), runbook.

## Чек-лист приёмки

- [x] CI: гейты и образ ai-service
- [x] прод-compose: сервисы за профилем, env Symfony
- [x] деплой: БД и роль, миграции, запуск, проверка секретов
- [x] GlitchTip в сервисе
- [x] cron и `--all-enabled`
- [x] runbook, `ARCHITECTURE.md`
- [x] гейты зелёные, compose config валиден, образ проверен локально

## Нужно от Владельца для включения (не для мержа)

- провайдеры — решено 31.10: OpenAI, DeepSeek, Kimi только через шлюз ИИ
  (`../gateway/`, G2 — доработка этого PR); ключи на шлюзе, на app — `AI_GATEWAY_TOKEN`;
- бюджет на LLM (§12);
- первая компания для allowlist;
- проект GlitchTip для сервиса (DSN).

## Итог

- CI: job `services-python` (dev-цель Dockerfile, ruff/format/mypy, pytest с
  PostgreSQL на init dev-compose), образ `ai-service` в матрице; на master
  собирается вместе с остальными.
- Прод-compose: `ai-service`, `ai-service-worker` за профилем, 256M каждый
  (локально ~90 МБ); `AI_SERVICE_*` в `x-php-env` и scheduler; убраны мёртвые
  `LLM_API_URL`/`LLM_API_KEY`.
- Деплой `[4c/5]`: проверка секретов (алфавит, токен ≥ 32), bootstrap БД,
  `migrate`, `up --wait`; выключено — `stop`. `COMPOSE_PROFILES` копится для
  ozon-fetcher и ai-service. Гейт `tests/shell/deploy-workflow-test.sh` зелёный.
- GlitchTip: `ai_service.tracing.errors` — только ERROR+, без тел запросов,
  локальных переменных и ПДн; `sentry-sdk` в зависимостях.
- Cron: `import-results` `*/5`, `ai-categorize --all-enabled` `25 * * * *`.
- Проверено локально: гейты сервиса (125 тестов), образ — `migrate` на чистой
  БД, API healthy, воркер стартует; `docker compose config` с профилем и без;
  actionlint без новых замечаний.
- Ревью Codex: сбой компании внутри транзакции закрывал EntityManager для
  остальных — после сбоя `resetManager()`, тест «сбой первой — запись второй»;
  стандартные интеграции Sentry (FastAPI, excepthook) могли отправить текст
  исключения с ПДн — оставлена только `LoggingIntegration`, тест на транспорте
  в памяти. Оба теста красные без исправлений.
