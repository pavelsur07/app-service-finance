# services/ai-service/AGENTS.md

ИИ-сервис Ваш Финдир. ТЗ — `docs/tasks/ai/TZ_AI_service_VashFinDir.md`, этапы —
`docs/tasks/ai/stages/`. Общие правила — `services/AGENTS.md`, Python —
`services/python.md`.

## Границы

- Не источник финансовых цифр и не владелец бизнес-данных: БД `ai_service` —
  только очередь `ai_task`, результаты `ai_result` (output обнуляется после
  ack) и трассы `ai_trace` (TTL 30 дней, исключение `services/AGENTS.md` §9).
- Ничего не применяет сам: результат забирает и применяет Symfony.
- Говорит только с Symfony по внутренней сети (`ai-task/v1`), наружу не
  публикуется; к LLM-провайдерам — через `llm/`, задачи SDK не импортируют.

## Как устроено

| Где | Что |
|---|---|
| `api/` | FastAPI: `/health`, `/ready`, `/internal/status`, API задач |
| `store/` | SQL очереди, результатов, трасс; миграции |
| `worker/` | `FOR UPDATE SKIP LOCKED`, аренда, повторы, финализация одной транзакцией |
| `core/pipeline.py` | prepare → generate → parse → verify → decide → finalize |
| `core/task.py`, `tasks/` | `TaskSpec` + реестр; одна папка — одна задача |
| `llm/` | gateway (`single`, `fallback`), маршруты `config/routes.yaml`, адаптеры |
| `privacy/` | маскирование ПДн до вызова модели |
| `verification/` | проверки и вердикт |

## Новая ИИ-задача

1. Контракт payload/output — `services/_contracts/ai-<task>/v1`.
2. Папка `tasks/<task>/`: `schemas.py`, `checks.py`, `prompts/v1.md`, `task.py`;
   строка в `tasks/registry.py`; маршрут в `config/routes.yaml`.
3. Eval-набор `evals/<task>/cases.jsonl` (≥ 20 кейсов, ловушки «недостаточно
   данных») и порог в тесте.
4. Потребитель в Symfony — `AiTaskResultConsumerInterface` в модуле-владельце.
5. Изменение ядра (pipeline, gateway, verification) — строка ADR в
   `ARCHITECTURE.md`.

## Провайдеры

Адаптер — на семейство API: `openai_compatible` (OpenAI, DeepSeek, vLLM,
Ollama…), `anthropic` (официальный SDK, без параметров сэмплинга — их
отвергает текущая версия API), `yandexgpt` (Foundation Models), `stub` (без
сети). Ключ — env из `api_key_env`; провайдер без ключа выключен. Какие три
провайдера на старте — открытый вопрос ТЗ §12; живые вызовы — после решения и
отдельного одобрения.

## Гейты

`make ai-service-lint`, `make ai-service-test`, `make ai-service-evals`
(профиль `services`, БД `services-postgres`).
