# ai-service

Python-сервис ИИ-задач для Symfony (`docs/tasks/ai/`). Статус: ядро (этап 1),
задачи `echo` и `categorize_transactions` (этап 2, подсказки статей ДДС). Прод —
за флагом `AI_SERVICE_ENABLED` (выключен), включение — `docs/maintenance/ai-service-prod.md`.

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

В dev-compose `AI_SERVICE_ROUTES_FILE=config/routes.dev.yaml` — все задачи на
модели `stub` (без ключей и сети). Категоризация:

```bash
php bin/console app:cash:ai-categorize --company-id=<uuid>
php bin/console messenger:consume async_sync --limit=1
php bin/console app:ai-service:import-results
php bin/console app:cash:ai-categorize-stats --company-id=<uuid>
```

## Evals категоризации

Наборы — `evals/categorize_transactions/` (синтетический) и `history/<имя>/`
(из истории ручных категоризаций). Золотой набор из истории:

```bash
php bin/console app:cash:ai-eval-export --company-id=<uuid> > export.json   # ПДн в открытом виде, не коммитить
python -m ai_service evals-import export.json --name <имя>                 # обезличивание; результат просмотреть
python -m ai_service evals-compare [--models a/m,b/m] [--sets synthetic,history/<имя>]
```

`evals-compare` гоняет каждую модель отдельно через тот же pipeline пачками по
50 и печатает таблицу «модель × точность × стоимость × задержка»; цены —
`config/prices.yaml` (₽ за 1 млн токенов), без токена шлюза модели маршрута не
вызываются (`--models stub/categorize --routes config/routes.dev.yaml` — без сети).
Модели прод-маршрутов отвечают только через шлюз, то есть только с app-сервера
(в контейнере `ai-service`; наборы — в образе, `AI_SERVICE_EVALS_DIR=/app/evals`).

## Провайдеры — через шлюз ИИ

`config/routes.yaml`: `openai`, `deepseek`, `kimi` с `base_url`
`https://ai.vashfindir.ru/<провайдер>/v1` и одним токеном `AI_GATEWAY_TOKEN`;
ключи провайдеров — только на шлюзе (`gateway/README.md`). Задан
`AI_SERVICE_LLM_GATEWAY_URL` (в проде — всегда) → провайдер с `base_url` мимо
шлюза или без него не загружается: `api`, `worker` и команды с моделями выходят с 2.

```bash
python -m ai_service gateway-check   # бесплатно: /models с токеном → 200, без → 401, по провайдеру маршрутов
python -m ai_service llm-ping        # платно (доли копейки): «ok» одним словом, max_tokens 5
```

Обе — без БД, трассы не пишут; выход 0, только если ответили все провайдеры
маршрутов; строки `<провайдер>: ok …` / `<провайдер>: fail <код>`, без токена и
текста ответа.

## Команды образа

`python -m ai_service api | worker | migrate | cleanup-traces | evals-import | evals-compare | gateway-check | llm-ping`.

## Переменные окружения

| Переменная | Назначение |
|---|---|
| `AI_SERVICE_DB_DSN` | PostgreSQL, БД `ai_service`, роль `svc_ai_service` |
| `AI_SERVICE_API_TOKEN` | токен «Symfony → сервис», ≥ 16 символов |
| `AI_SERVICE_ROUTES_FILE` | маршруты задач (по умолчанию `config/routes.yaml`) |
| `AI_SERVICE_WORKER_*`, `AI_SERVICE_TRACE_TTL_DAYS`, `AI_SERVICE_LLM_*` | аренда, попытки, бэкофф, TTL трасс, таймаут и повторы LLM (`config.py`) |
| `AI_GATEWAY_TOKEN` | токен шлюза ИИ для провайдеров `routes.yaml`; пусто — провайдеры выключены |
| `AI_SERVICE_LLM_GATEWAY_URL` | URL шлюза; задан — `base_url` провайдеров обязаны начинаться с него |
