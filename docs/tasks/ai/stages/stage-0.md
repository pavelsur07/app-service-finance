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
- [x] после деплоя: таблиц `ai_*` на проде нет (30.10, read-only)

## Вне этапа

- `LLM_API_URL`/`LLM_API_KEY` в `docker-compose.prod.yml` — изменение
  прод-Docker (§3.3 `AGENTS.md`), отдельным одобрением; переменные никем не
  читаются, оставить их безвредно.
- Удаление `site/src/Mcp` — этап 0.2, ниже.

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

Чек-лист приёмки выполнен. На проде 30.10: PR #2824, миграция `Version20261030120000` применена, таблиц `ai_*` нет.

---

# Этап 0.2 — удаление заглушки `site/src/Mcp`

## Задача

Убрать локальный stdio MCP-сервер `app:mcp:serve` (7 инструментов над
`CashFacade`/`CompanyFacade`): он нигде не запускается, на проде не
используется. Инструменты для ИИ (этап 5 ТЗ) проектируются заново поверх
Facade в `services/ai-service`.

## Результат

- нет `site/src/Mcp`, `docs/mcp/`, тестов `Unit/Mcp`, `Integration/Mcp`,
  `Functional/Mcp`, `CashFacadeMcpSurfaceTest`;
- удалены методы, у которых единственным потребителем был MCP:
  `CashFacade::listTransactions`, `listCashflowCategories`, `listAutoRules`,
  `upsertCashflowCategory`, `upsertAutoRule` (с приватными помощниками и
  зависимостями), DTO `CashflowCategoryInput`, `AutoRuleInput`,
  `AutoRuleConditionInput`; `CompanyFacade::resolveIdByName`,
  `CompanyRepository::findIdsByExactName`;
- миграций нет: у MCP своих таблиц не было.

## Что считаем выполненным

- grep по `App\Mcp`, `src/Mcp`, `app:mcp`, удалённым методам вне истории
  (`Changelog`, ТЗ) пуст;
- гейты handoff зелёные; baseline PHPStan только сократился.

## Чек-лист приёмки

- [x] удалены `site/src/Mcp`, `docs/mcp/`, тесты MCP
- [x] методы `CashFacade`/`CompanyFacade`/`CompanyRepository`, нужные только MCP, удалены (с `CashFacade::MAX_PER_PAGE` и `CashTransactionAutoRuleRepository::findByCompany`)
- [x] `ModuleAccessMap` — exempt-префикс `App\Mcp\` убран
- [x] комментарии «для MCP-инструмента» в Cash убраны
- [x] `ARCHITECTURE.md` — строка модуля, методы Facade, exempt-зоны, Changelog
- [x] baseline PHPStan — запись `findIdsByExactName` удалена
- [x] `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`, интеграционные Cash/Company

## Итог

30.10.2026, ветка `chore/remove-mcp-stub`.

- Удалено: `site/src/Mcp` (13 файлов), `docs/mcp/`, 4 теста, exempt-префикс в
  `ModuleAccessMap`; 5 методов `CashFacade` с 3 DTO, константа `MAX_PER_PAGE`,
  `CashTransactionAutoRuleRepository::findByCompany`,
  `CompanyFacade::resolveIdByName`, `CompanyRepository::findIdsByExactName`;
  3 записи baseline PHPStan.
- Внутреннее ревью (свежая сессия): BLOCKER 0, IMPORTANT 0, MINOR 3 —
  неиспользуемая константа, осиротевший метод репозитория, неотмеченный
  чек-лист; все исправлены.
- Проверки: `lint:container`; PHP CS Fixer (весь проект, без кеша) — 0;
  strict_types — 0; PHPStan (1 воркер) — No errors; unit — 4205 OK;
  `tests/Integration/Cash` — 291 OK, `tests/Integration/Company` — 144 OK,
  `tests/Functional/Cash` — 166 OK.
- Внешнее ревью (Codex, effort medium, 1 раунд): `REVIEW_GREEN`.

Чек-лист приёмки выполнен.
