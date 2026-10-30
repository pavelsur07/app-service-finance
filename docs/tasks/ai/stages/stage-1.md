# Этап 1 — каркас `services/ai-service`

ТЗ: `docs/tasks/ai/TZ_AI_service_VashFinDir.md`, §3–§9, §11 этап 1.

## Задача

Поставить Python-сервис `services/ai-service` с общим ядром (API задач,
очередь в своей БД, pipeline, LLM Gateway, маскирование ПДн, трассы) и
клиент в `ServiceGateway`, чтобы задача-заглушка `echo` прошла путь
Symfony → сервис → Symfony. Бизнес-задач (категоризация) в этапе нет — это
этап 2.

## Результат

**Контракт** `services/_contracts/ai-task/v1`: конверт задачи, статус, конверт
результата, страница результатов, ack; payload/output заглушки `echo`.

**Сервис** (`services/ai-service`, Python 3.12, uv, FastAPI, Pydantic v2,
psycopg 3, httpx, structlog):

- API: `GET /health`, `/ready`, `/internal/status`; `POST /internal/tasks`
  (`Idempotency-Key`, 202 после записи в БД, повтор → тот же `task_id`),
  `GET /internal/tasks/{id}`, `GET /internal/tasks?status=done` (курсор),
  `POST /internal/tasks/ack` (обнуляет `output`); Bearer-токен «Symfony →
  сервис» на `/internal/*`;
- БД `ai_service`, роль `svc_ai_service`; forward-only миграции, прогон
  явный (`make ai-service-migrate`); таблицы `ai_task` (unique по
  `idempotency_key`), `ai_result`, `ai_trace` (TTL 30 дней);
- worker: `FOR UPDATE SKIP LOCKED`, lease и возврат зависших, лимит попыток →
  `failed`; результат и трасса — одной транзакцией;
- pipeline `prepare → generate → parse → verify → decide → finalize`;
  `Task` + реестр по `task_type`; `Check` с уровнями `block`/`review`/`info`;
  промпт — файл с версией;
- LLM Gateway: режимы `single` и `fallback` (`vote` — этап 2, конфиг с ним
  отвергается), маршрут задачи в `routes.yaml`; адаптеры по семейству API:
  OpenAI-совместимый (OpenAI, DeepSeek, vLLM, Ollama и др.), Anthropic
  Messages, YandexGPT (Foundation Models), плюс `stub` без сети для заглушки
  и тестов. Провайдер включается env-переменными ключа; в коде не зашит;
- маскирование ПДн до вызова модели: телефоны, e-mail, счета и карты, ИНН
  физлиц, ФИО; в трассу — только маскированный вход;
- логи structlog JSON без токенов, ПДн, сырых ответов и сумм;
- evals: `evals/echo/cases.jsonl` + pytest-порог.

**Symfony** (`ServiceGateway`):

- `AiServiceClient` (таймауты, единые ошибки `ServiceUnavailableException` /
  `ContractViolationException`), парсер контракта `ai-task/v1`;
- `SubmitAiTaskMessage` (`async_sync`) и `ImportAiResultsAction`; импорт
  раздаёт результаты потребителям по `task_type`
  (`AiTaskResultConsumerInterface`) и подтверждает ack;
- `AI_SERVICE_ENABLED`, `AI_SERVICE_COMPANY_ALLOWLIST`; выключенный сервис —
  команды ничего не отправляют;
- консольные команды `app:ai-service:echo` и `app:ai-service:import-results`.

**Документы:** `services/python.md`, `services/ai-service/AGENTS.md` и
`README.md`, исключение для ai-service в `services/AGENTS.md` §9,
`ARCHITECTURE.md` (ServiceGateway, ADR, Changelog), таблица
`service-gateway.md` §4. Dev: профиль `services` в `docker-compose.yml`,
init-скрипт БД, `make ai-service-*`.

## Что считаем выполненным

- гейты сервиса локально: ruff, ruff format, mypy (strict), pytest
  (unit + интеграционные на PostgreSQL), evals;
- гейты PHP: PHPStan, CS, strict_types, unit, затронутые интеграционные;
- e2e локально: `app:ai-service:echo` → сервис → worker →
  `app:ai-service:import-results` → потребитель получил результат, ack
  обнулил `output`; повтор постановки с тем же ключом не создаёт задачу;
- миграций Symfony нет.

## Вне этапа — отдельное одобрение (`AGENTS.md` §3.3)

- job гейтов ai-service в CI (`deploy.yml`, фильтр путей);
- прод: `docker-compose.prod.yml`, БД и роль в деплое, секреты токенов и
  ключей провайдеров, cron импорта результатов, проект GlitchTip;
- живые вызовы LLM-провайдеров (нужны ключи и решение §12 ТЗ о провайдерах).

## Чек-лист приёмки

- [x] контракт `ai-task/v1` (`schema.json`, `CHANGELOG.md`), тест контракта с обеих сторон
- [x] сервис: API, миграции, worker, pipeline, gateway (3 адаптера + stub), маскирование, трассы
- [x] `services/python.md`, `services/ai-service/AGENTS.md`, `README.md`, §9 `services/AGENTS.md`
- [x] dev: compose-профиль, init БД, `make ai-service-*`
- [x] Symfony: клиент, парсер, сообщения и routing, потребитель `echo`, команды, env
- [x] `ARCHITECTURE.md`, `service-gateway.md` §4
- [x] гейты сервиса и PHP зелёные
- [x] e2e echo Symfony → сервис → Symfony, идемпотентность постановки

## Итог

- Отклонение от плана: `ImportAiResultsMessage` не заведено — импорт идёт
  командой `app:ai-service:import-results` (lock) напрямую через
  `ImportAiResultsAction`; сообщение без cron ничего не добавляет. При выводе
  в прод импорт запускает cron (отдельное одобрение).
- Идемпотентность постановки: `task_id` не входит в отпечаток запроса —
  повтор фасада с новым `task_id` и тем же ключом возвращает исходную задачу;
  другие `task_type`/`company_id`/`payload` с тем же ключом → 409.
- Гейты: Python — ruff, ruff format, mypy strict (38 файлов), pytest 82
  (unit + интеграционные на PostgreSQL + evals); PHP — PHPStan, CS,
  strict_types, unit `ServiceGateway` 77, `lint:container`.
- e2e (dev, локальные контейнеры): `app:ai-service:echo` → `async_sync` →
  worker `accepted` → `import-results`: 1 получен, 1 подтверждён; повторный
  импорт — 0. В БД сервиса после ack `payload` и `output` пусты, в трассе
  вход замаскирован (`[PERSON_1]`, `[PHONE_1]`). Компания вне allowlist —
  `disabled`, задача не ставится.
