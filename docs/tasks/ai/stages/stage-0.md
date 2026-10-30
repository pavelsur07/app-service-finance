# Этап 0.1 — удаление прототипа `site/src/Ai`

ТЗ: `docs/tasks/ai/TZ_AI_service_VashFinDir.md`, §11, этап 0.

## Задача

Убрать неработающий прототип ИИ-модуля (`CashflowAgent` с TODO, `LlmClient`,
`AiAgent`/`AiRun`/`AiSuggestion`, страница `/ai/suggestions`), чтобы в проекте
не осталось второй ИИ-архитектуры рядом с будущим `services/ai-service`.

## Результат

- нет `site/src/Ai`, `site/templates/ai`, тестов модуля и ссылок на него в
  конфигурации, `ModuleAccessMap`, baseline PHPStan, env и `ARCHITECTURE.md`;
- миграция удаляет таблицы `ai_suggestion`, `ai_run`, `ai_agent`; `down()`
  восстанавливает их структуру.

## Что считаем выполненным

- read-only проверка прода до удаления: строк в таблицах `ai_*` — 0;
- grep по `App\Ai`, `src/Ai`, `app_ai_`, `LLM_API`, `ai_agent|ai_run|ai_suggestion`
  вне истории миграций пуст;
- гейты handoff зелёные; baseline PHPStan только сократился;
- PR с упоминанием миграции в запросе «merge and deploy».

## Чек-лист приёмки

- [ ] прод: `ai_agent`, `ai_run`, `ai_suggestion` пустые
- [ ] удалены `site/src/Ai`, `site/templates/ai`, `site/tests/Unit/Ai`, `site/tests/Integration/Ai`
- [ ] `config/routes.yaml`, `config/packages/doctrine.yaml` — блоки `Ai` убраны
- [ ] `ModuleAccessMap` и его тест — префикс `App\Ai\` убран
- [ ] `LLM_API_URL`/`LLM_API_KEY` убраны из `site/.env`, `.env.dev`, `.env.test`
- [ ] baseline PHPStan — записи `src/Ai/*` удалены
- [ ] `ARCHITECTURE.md` — строка модуля и упоминание в `Module::FINANCE`, Changelog
- [ ] миграция `DROP TABLE` с обратимым `down()`
- [ ] `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`
- [ ] после деплоя: таблиц `ai_*` на проде нет

## Вне этапа

- `LLM_API_URL`/`LLM_API_KEY` в `docker-compose.prod.yml` — изменение
  прод-Docker (§3.3 `AGENTS.md`), отдельным одобрением; переменные никем не
  читаются, оставить их безвредно.
- Удаление `site/src/Mcp` — этап 0.2, отдельный PR.

## Итог

_Дописывается по завершении._
