# ai-service на проде — включение

План — `docs/tasks/ai/stages/prod-rollout.md`. Пока `AI_SERVICE_ENABLED` не
равен `true`, деплой сервис не запускает и его БД не трогает, а Symfony видит
ИИ выключенным: задачи не ставятся, cron-команды выходят с 0.

Включение — отдельные решения Владельца (`AGENTS.md` §3.3, ТЗ §12): провайдеры
и ключи, бюджет, компании.

## Что сделать один раз

1. **Провайдеры — через шлюз ИИ** (`docs/tasks/ai/gateway/`). Ключи OpenAI,
   DeepSeek, Kimi живут только на шлюзе `ai.vashfindir.ru`; сервис предъявляет
   токен шлюза `AI_GATEWAY_TOKEN` (тот же секрет, что у деплоя шлюза).
   Маршрут — `services/ai-service/config/routes.yaml`
   (`categorize_transactions`: `fallback` deepseek → openai; Kimi — после ключа,
   #2837). Прод-compose задаёт `AI_SERVICE_LLM_GATEWAY_URL`: провайдер мимо шлюза
   — сервис не стартует.
2. **GlitchTip:** проект `ai-service` → DSN.
3. **GitHub → Settings → Secrets and variables → Actions.**

   Secrets (ключи и токены — только символы `A-Za-z0-9._~-`, деплой проверяет):

   | Имя | Значение |
   |---|---|
   | `AI_SERVICE_API_TOKEN` | случайная строка ≥ 32 символов — токен «Symfony → сервис» |
   | `AI_SERVICE_DB_PASSWORD` | случайный пароль роли `svc_ai_service` |
   | `AI_SERVICE_SENTRY_DSN` | DSN из п. 2; пусто — только лог |
   | `AI_GATEWAY_TOKEN` | уже заведён для шлюза (G1); ≥ 32 символов |

   Variables:

   | Имя | Значение |
   |---|---|
   | `AI_SERVICE_ENABLED` | `true` — деплой поднимает сервис, Symfony его видит |
   | `AI_SERVICE_COMPANY_ALLOWLIST` | UUID компаний через запятую; пусто — ни одной, `*` — все |

4. **Деплой** (merge в master или `production_action=deploy`). Шаг `[4c/5]`
   сам создаёт роль `svc_ai_service` и БД `ai_service`
   (`docker/ai-service/db-bootstrap.sql`), прогоняет миграции сервиса,
   проверяет шлюз (`gateway-check`: `/models` с токеном → 200, без → 401 у
   каждого провайдера маршрутов; бесплатно; провал — деплой красный до запуска)
   и запускает `ai-service` (API) и `ai-service-worker`.

## Включение по шагам

1. `AI_SERVICE_ENABLED=true`, allowlist пуст, деплой. В логе деплоя —
   `deepseek: ok 200/401`, `openai: ok 200/401`; `docker ps` — `ai-service`
   `healthy`, `ai-service-worker` `Up`.
2. Связь с моделями (по команде Владельца — доли копейки):
   `docker exec ai-service python -m ai_service llm-ping` — `ok <модель> <мс>` у
   каждого провайдера. Затем `evals-compare` на синтетике в том же контейнере —
   порядок моделей маршрута.
3. Путь Symfony → сервис → Symfony без моделей: allowlist — одна компания,
   деплой, затем
   `docker exec scheduler php bin/console app:ai-service:echo --company-id=<uuid>`,
   через минуту `docker exec scheduler php bin/console app:ai-service:import-results`
   — `1 received, 1 acked`.
4. Подсказки статей одной компании: cron `25 * * * *` ставит задачи
   (`app:cash:ai-categorize --all-enabled`), `*/5` забирает результаты.
   Подсказки видны в списке операций; автоприменение выключено, пока не
   включено командой `app:cash:ai-auto-apply --company-id= --enable` после
   ворот качества (этап 2.3).
5. Метки точности — `app:cash:ai-categorize-stats --company-id=`; остальные
   компании — дописать в allowlist и задеплоить.

## Проверка после деплоя

- `docker ps` — `ai-service` `healthy`.
- Очередь сервиса: `docker exec site-postgres psql -U app -d ai_service -c "SELECT status, count(*) FROM ai_task GROUP BY 1"`
  (суперпользователь `app` видит БД сервиса; только чтение).
- Логи: `docker logs ai-service-worker --since 1h` — `task_done`, без
  `task_llm_unavailable`.
- GlitchTip — проект `ai-service` без новых issue.

## Откат

`AI_SERVICE_ENABLED=false` и деплой: контейнеры останавливаются, Symfony
перестаёт ставить задачи; уже показанные подсказки остаются, принять их можно.
Быстро без деплоя: `docker stop ai-service-worker ai-service` — постановка
задач уходит в повторы Messenger, импорт пишет WARNING «unavailable».
Одну компанию — убрать из allowlist и задеплоить.

## Ротация

`AI_SERVICE_API_TOKEN` и `AI_SERVICE_DB_PASSWORD` — сменить секрет и
задеплоить: Symfony и сервис получают новое значение одним деплоем, пароль
роли синхронизирует `db-bootstrap.sql`. `AI_GATEWAY_TOKEN` и ключи провайдеров —
`gateway/README.md`, «Ротация токена шлюза».
