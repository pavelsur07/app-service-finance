# services/python.md — правила Python-сервисов монорепо

Распространяется на `services/*/**/*.py`. Иерархия: корневой `AGENTS.md` →
`services/AGENTS.md` → этот файл → `services/<name>/AGENTS.md`. Первый сервис —
`ai-service` (`docs/tasks/ai/`).

## Тулчейн

- Python **3.12** (пин `requires-python` в `pyproject.toml` и тег базового
  образа); переход на следующую версию — отдельным PR после выхода её
  security-релизов у всех зависимостей.
- Пакеты и окружение — **uv**: `pyproject.toml` + `uv.lock` в git, установка
  только `uv sync --frozen`. Глобальные установки на хост не нужны: гейты
  гоняются в dev-образе сервиса (`docker/Dockerfile`, цель `dev`).

## Раскладка

```text
services/<name>/
├── pyproject.toml / uv.lock
├── src/<package>/          # src-layout: тесты не видят пакет без установки/PYTHONPATH
│   ├── __main__.py         # только wiring: config → зависимости → api/worker
│   └── config.py           # pydantic-settings, fail fast
├── migrations/             # forward-only SQL, прогон явный
├── tests/{unit,integration}/
├── docker/Dockerfile       # цели runtime (по умолчанию в compose) и dev
└── AGENTS.md / README.md
```

## Паттерны

- Зависимости — через конструктор, без DI-фреймворка; интерфейсы — `Protocol`
  у потребителя на границах подмены (HTTP-клиент, LLM-адаптер).
- Модели данных и контракты — Pydantic v2 (`extra="forbid"` на входе);
  настройки — `pydantic-settings` из env.
- Синхронный код по умолчанию; asyncio — только когда нужен конкурентный I/O
  внутри одного запроса.
- БД — psycopg 3 с явным SQL; соединения `autocommit=True`, транзакции —
  явным `conn.transaction()`; ORM не используется.
- Деньги — `Decimal` и десятичные строки в контрактах, никогда не `float`
  (`services/AGENTS.md` §1.3).

## Зависимости

- Минимум: внешний пакет — когда решает задачу осмысленно лучше своего кода.
- Проверенный стек (без отдельного обоснования): FastAPI, uvicorn, Pydantic,
  pydantic-settings, httpx, psycopg 3 (+ psycopg-pool), structlog, PyYAML,
  официальные SDK LLM-провайдеров; dev — pytest, ruff, mypy, jsonschema.
- Остальное — строка обоснования в описании PR.

## Наблюдаемость

- structlog в JSON, сторонние логгеры (uvicorn) — через тот же форматтер.
- Обязательные поля и запреты — `services/AGENTS.md` §6.

## Тесты

- pytest; unit без сети и БД (HTTP — `httpx.MockTransport`).
- Интеграционные — на настоящем PostgreSQL (`AI_TEST_DB_DSN`, БД `<name>_test`
  из init-скрипта `services-postgres`), маркер `integration`; без DSN —
  skip, в CI DSN обязателен.
- Тест контракта: примеры `services/_contracts/<c>/v<N>/examples` валидируются
  по `schema.json`, ответы API в интеграционных тестах — тоже.

## Гейты (make/CI)

- `make <name>-lint` — `ruff check`, `ruff format --check`, `mypy` (strict,
  только `src/`).
- `make <name>-test` — pytest без evals; `make <name>-evals` — eval-наборы.
- Порядок: format → lint → mypy → test; красный гейт останавливает пайплайн.

## Docker

- multi-stage: `ghcr.io/astral-sh/uv` (пин версии) → сборка venv в
  `python:<версия>-slim-<debian>` → runtime без uv и dev-зависимостей,
  non-root, `HEALTHCHECK` по `/health`.
- `.dockerignore` в контексте сервиса.
