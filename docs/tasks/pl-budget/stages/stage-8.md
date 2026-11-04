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

- [ ] Правило «Комиссия = 12,5 % от Выручки» заполняет 12 месяцев с минусом.
- [ ] Правка выручки (ввод, помощник, импорт) сразу меняет комиссию.
- [ ] Ручной ввод в статью с правилом отклоняется; помощники и импорт её
      пропускают.
- [ ] База с правилом внутри или цикл — отказ с понятным текстом.
- [ ] Снятие правила оставляет суммы как ручные.
- [ ] Копия варианта переносит правила; утверждённый вариант не меняется.
