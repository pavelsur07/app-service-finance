# ai-service

Python-сервис ИИ-задач для Symfony (`docs/tasks/ai/`). Статус: **каркас**
(этап 1) — ядро и диагностическая задача `echo`; в прод не выведен.

## Запуск (dev)

```bash
make ai-service-build
docker compose --profile services up -d services-postgres
make ai-service-migrate
make ai-service-up          # API на :8181 (dev-проброс) и worker
make ai-service-lint ai-service-test ai-service-evals
```

Symfony (dev): `AI_SERVICE_ENABLED=true`, `AI_SERVICE_BASE_URL=http://services-ai-service:8080`,
`AI_SERVICE_API_TOKEN=dev-only-ai-service-token`, `AI_SERVICE_COMPANY_ALLOWLIST=<uuid>`, затем

```bash
php bin/console app:ai-service:echo --company-id=<uuid>
php bin/console messenger:consume async_sync --limit=1
php bin/console app:ai-service:import-results
```

## Команды образа

`python -m ai_service api | worker | migrate | cleanup-traces`.

## Переменные окружения

| Переменная | Назначение |
|---|---|
| `AI_SERVICE_DB_DSN` | PostgreSQL, БД `ai_service`, роль `svc_ai_service` |
| `AI_SERVICE_API_TOKEN` | токен «Symfony → сервис», ≥ 16 символов |
| `AI_SERVICE_ROUTES_FILE` | маршруты задач (по умолчанию `config/routes.yaml`) |
| `AI_SERVICE_WORKER_*`, `AI_SERVICE_TRACE_TTL_DAYS`, `AI_SERVICE_LLM_*` | аренда, попытки, бэкофф, TTL трасс, таймаут и повторы LLM (`config.py`) |
| `AI_OPENAI_API_KEY`, `AI_DEEPSEEK_API_KEY`, `AI_LOCAL_API_KEY`, `AI_ANTHROPIC_API_KEY`, `AI_YANDEX_API_KEY` + `AI_YANDEX_FOLDER_ID` | ключи провайдеров из `routes.yaml`; пусто — провайдер выключен |
