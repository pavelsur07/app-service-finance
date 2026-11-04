# Stage 8: правило «% от выручки» — модель и пересчёт

**Risk:** HIGH-LOCAL (миграция; производные данные в транзакции записи плана)

## 1. Какую задачу решаем

Переменные расходы (комиссия, эквайринг, логистика) планируют процентом от
выручки. Помощник `percent_of` считает один раз: план выручки поменяли — суммы
расходов устарели, их надо пересчитывать руками. Нужно живое правило, которое
держит «X % от базы» само.

## 2. Что будет результатом

| Часть | Что |
|---|---|
| Схема | `budget_rules`: `id`, `company_id` (FK companies CASCADE), `version_id` (FK budget_versions CASCADE), `pl_category_id`, `project_direction_id`, `responsibility_center_id`, `base_category_id`, `percent` NUMERIC(7,4) (0 < X ≤ 1000), `created_at`, `updated_at`; unique `(version_id, pl_category_id, project_direction_id, responsibility_center_id)`; индекс `company_id` |
| Domain | Entity `BudgetRule` (UUIDv7, инварианты процента и пары); `BudgetLineSource::RULE` (`rule`) |
| Application | `BudgetRuleCalculator::recalculate(companyId, version, pair)` — план пары → дерево (`BudgetTreeCalculator`) → для каждого правила X % от строки-базы по месяцам, знак по направлению статьи (`BudgetFillMath`), запись через `BudgetLinesWriter` с источником `rule`; `BudgetRulePolicy` — проверки: цель — видимый лист ввода, база — денежная строка дерева (лист или итог, не KPI), в базе нет статей с правилом этой пары и самой цели (цепочки и циклы запрещены) |
| Точки входа | `SetBudgetRuleAction` / `RemoveBudgetRuleAction` — под `BudgetVersionLock`, перепроверка статуса, пересчёт в той же транзакции; снятие правила оставляет суммы ручными (`rule` → `manual`). `SaveBudgetLinesAction`, `FillBudgetCellsAction`, `ImportBudgetCellsAction` после записи пересчитывают правила затронутых пар в своей транзакции |
| Защита | `BudgetLinesWriter::validate` отклоняет ручную запись в статью с правилом этой пары (422 «Статья считается по правилу»); помощники и импорт такие ячейки пропускают (`skipped`) |
| Копия варианта | `CreateBudgetVersionAction` копирует правила вместе со строками |
| API | `POST /finance/budget/versions/{id}/rules` `{project, center, categoryId, baseCategoryId, percent}` и `POST …/rules/delete` `{project, center, categoryId}` (CSRF `budget_cells`, запись FINANCE) → сетка пары; сетка (`grid`) отдаёт у строки `rule: {percent, baseCategoryId, baseName} | null` и `editable: false` для цели |

## 3. Что считаем выполненной работой

- TDD: unit `BudgetRulePolicy` (все ветки), Entity; integration
  `BudgetRuleCalculator` (знак, округление, итог как база, пустая база → 0 и
  удаление строк); `SetBudgetRuleAction` (повтор → те же строки, утверждённый →
  409, цикл/цепочка → 422), пересчёт после `cells`, `fill`, `import`; копия
  варианта; functional эндпоинтов (CSRF, 403, чужой вариант 404); тест
  конкурентной правки базы и правила (блокировка).
- Миграция: up/down на пустой БД, `SchemaInSyncWithMigrationsTest`.
- PHPStan/CS, Budget + Finance, полный набор; `ARCHITECTURE.md`.

## 4. Чек-лист приёмки

- [x] Правило «Комиссия = 12,5 % от Выручки» заполняет 12 месяцев с минусом.
- [x] Правка выручки (ввод, помощник, импорт) сразу меняет комиссию.
- [x] Ручной ввод в статью с правилом отклоняется; помощники и импорт её
      пропускают.
- [x] База с правилом внутри или цикл — отказ с понятным текстом.
- [x] Снятие правила оставляет суммы как ручные.
- [x] Копия варианта переносит правила; утверждённый вариант не меняется.

---

### Stage 8: правило — модель и пересчёт — DONE

#### What was done
- Миграция `Version20261104150000` (`budget_rules`), `BudgetRule` + Builder,
  `BudgetRuleRepository`, FK компании в `BudgetSchemaExtension`;
  `BudgetLineSource::RULE`.
- `BudgetRulesQuery`, `BudgetRuleCalculator`, `SetBudgetRuleAction`,
  `RemoveBudgetRuleAction`, `BudgetRuleController` (`/rules`,
  `/rules/delete`); сетка — `BudgetGridRule`.
- Пересчёт в `SaveBudgetLinesAction` (каждая затронутая пара),
  `FillBudgetCellsAction`, `ImportBudgetCellsAction`; запрет ручной записи в
  `BudgetLinesWriter::validate`; пропуск в помощниках и импорте; копия правил в
  `CreateBudgetVersionAction`.

#### Отклонение от плана
- База считается по плану пары **без статей с правилом** (как помощник
  `percent_of`), а не «база не должна содержать статей с правилом». Так итог
  «Прибыль» годится базой для комиссии, циклов нет по построению. Запреты
  остались два: база — статья с правилом; цель — база другого правила.
- Отказы под блокировкой возвращаются из транзакции, а не бросаются внутри
  (исключение в `wrapInTransaction` закрывает EntityManager).

#### Definition of Done
- [x] Unit `BudgetEntitiesTest` (+2: компания из варианта; база = цель,
      процент > 1000, три знака, минус), integration `BudgetRuleTest` (8:
      заполнение и повтор, итог как база, пересчёт после cells/fill/import,
      ручная запись 422, запреты, снятие + копия, сетка, ожидание блокировки),
      functional `BudgetRulesApiTest` (2: задать → сетка → снять; 409/404/422/
      CSRF/читатель).
- [x] `SchemaInSyncWithMigrationsTest` — OK; Budget + Finance + Doctrine — 324 OK.
- [x] PHPStan/CS по файлам; `ARCHITECTURE.md` 2.135.
