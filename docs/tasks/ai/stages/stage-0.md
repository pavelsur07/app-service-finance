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
  восстанавливает их структуру;
- удалены методы `CashFacade`/`CashReportQuery`/`PaymentPlanRepository`, у
  которых единственным потребителем был агент (`sum*IncludingDeleted`,
  `listPlannedPayments`, `findPlannedByCompanyAndPeriod`), и DTO
  `CashCategoryTurnover`, `PlannedPaymentSummary`.

## Что считаем выполненным

- read-only проверка прода до удаления: строк в таблицах `ai_*` — 0;
- grep по `App\Ai`, `src/Ai`, `app_ai_`, `LLM_API`, `ai_agent|ai_run|ai_suggestion`
  вне истории миграций пуст;
- гейты handoff зелёные; baseline PHPStan только сократился;
- PR с упоминанием миграции в запросе «merge and deploy».

## Чек-лист приёмки

- [x] прод: `ai_agent`, `ai_run`, `ai_suggestion` пустые
- [x] удалены `site/src/Ai`, `site/templates/ai`, `site/tests/Unit/Ai`, `site/tests/Integration/Ai`
- [x] `config/routes.yaml`, `config/packages/doctrine.yaml` — блоки `Ai` убраны
- [x] `ModuleAccessMap` и его тест — префикс `App\Ai\` убран
- [x] `LLM_API_URL`/`LLM_API_KEY` убраны из `site/.env`, `.env.dev`, `.env.test`
- [x] baseline PHPStan — записи `src/Ai/*` удалены
- [x] `ARCHITECTURE.md` — строка модуля и упоминание в `Module::FINANCE`, Changelog
- [x] миграция `DROP TABLE` с обратимым `down()`
- [x] мёртвые методы Cash, нужные только агенту, удалены вместе с тестами
- [x] `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`
- [ ] после деплоя: таблиц `ai_*` на проде нет

## Вне этапа

- `LLM_API_URL`/`LLM_API_KEY` в `docker-compose.prod.yml` — изменение
  прод-Docker (§3.3 `AGENTS.md`), отдельным одобрением; переменные никем не
  читаются, оставить их безвредно.
- Удаление `site/src/Mcp` — этап 0.2, отдельный PR.

## Итог

30.10.2026, ветка `chore/remove-ai-prototype`.

- Прод (read-only, `codex-psql-ro`): `ai_agent`, `ai_run`, `ai_suggestion` — по 0 строк.
- Удалено: модуль `site/src/Ai` (24 файла), шаблон, 2 теста, конфигурация,
  env, префикс в `ModuleAccessMap`; 13 записей baseline PHPStan.
- Внутреннее ревью (свежая сессия): BLOCKER 0, IMPORTANT 2 — устаревшие
  упоминания агента в `ARCHITECTURE.md`/Cash и четыре метода `CashFacade` без
  потребителей. Оба исправлены: методы, запросы, метод репозитория, два DTO,
  два интеграционных теста и неиспользуемая зависимость `CashFacade` удалены.
  MINOR: `down()` без `COMMENT ON COLUMN` — намеренно (DBAL 4); исторические
  упоминания в `docs/tasks/cash-architecture/plan.md` и `MoneyFloatCastRule` —
  оставлены как история.
- Проверки: `lint:container`; миграция up → down → up на чистой БД (таблиц
  0 → 3 → 0), `doctrine:schema:validate --skip-sync`; PHP CS Fixer (весь
  проект, без кеша) — 0; strict_types — 0; PHPStan (1 воркер) — No errors;
  unit — 4215 OK; `tests/Integration/Cash` — 298 OK.
- Внешнее ревью (Codex, effort medium, 1 раунд): `REVIEW_GREEN`.

Чек-лист приёмки выполнен, кроме пункта «после деплоя».
