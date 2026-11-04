# Stage 3: план–факт и итог года (backend)

**Risk:** MEDIUM
**Stage base commit:** `cf083d2e7` (ветка `feat/pl-budget-plan-fact` от master после #2874)

## 1. Какую задачу решаем

План введён, но сравнить его с фактом негде. Нужен расчёт «план / факт /
отклонение» по дереву ОПиУ за месяцы года — для пары «проект × ЦФО» или по
всем — и годовой итог варианта в списке (решения Владельца 04.11, п. 6–7).

## 2. Что будет результатом

| Часть | Что |
|---|---|
| Finance | `FinanceFacade::getPlFactAmountsByMonth(companyId, year, ?projectDirectionId, ?responsibilityCenterId)` → `month → categoryId → сумма` в знаке отчёта (доход − расход) из `pl_daily_totals` одним `GROUP BY`; проект и ЦФО — точное совпадение: план хранится по точной паре, у дочернего проекта своя пара и свой план |
| Budget | `BudgetPlanFactBuilder`: план и факт — две прогонки `calculatePlTree` (12 месяцев + год), отклонение = факт − план, `%` = отклонение / |план|; флаг `significant` при |%| > 10 (план 0 и факт ≠ 0 — значимо, `%` нет) |
| API | `GET /finance/budget/versions/{id}/plan-fact?project=&center=` — JSON (чтение) |
| Список | колонка «Итог года»: строка с кодом чистой прибыли из `calculatePlTree` по году варианта; нет строки — колонки нет |

Константа порога — в Budget (`BudgetVariance::THRESHOLD_PERCENT = 10`),
настройки на компанию нет (после MVP).

## 3. Что считаем выполненной работой

- TDD: integration-тесты Facade-метода (знак, пара, точный проект без дочерних, чужая
  компания), builder'а (отклонение, порог, план 0), functional — эндпоинт и
  колонка списка.
- PHPStan/CS по изменённым файлам; Budget + Finance тесты.
- `ARCHITECTURE.md`, `checkpoint.md`, итог этапа; коммит.

## 4. Чек-лист приёмки

- [x] Факт по месяцам совпадает с отчётом ОПиУ за те же месяцы и пару
      (тот же калькулятор и знак).
- [x] Отклонение и `%` верны; |%| > 10 помечено; план 0 при факте ≠ 0 —
      помечено без `%`.
- [x] Пара фильтрует факт (точный проект, ЦФО); без пары — вся компания.
- [x] Чужой вариант — 404; невалидная пара — 422.
- [x] «Итог года» в списке — по строке чистой прибыли; без такой строки
      колонки нет.
- [x] Число запросов к БД на расчёт не растёт с числом статей.

## Work items

- 3.0 — код строки чистой прибыли — `NET_PROFIT` (как `ExtendPLTreeWithMarketplaceAction::CHECKED_TOTALS`).
- 3.1 — `getPlFactAmountsByMonth` (integration).
- 3.2 — `BudgetPlanFactBuilder` + эндпоинт (integration, functional).
- 3.3 — «Итог года» в списке (functional).
- 3.4 — `ARCHITECTURE.md`, ревью, итог.

---

### Stage 3: план–факт и итог года (backend) — DONE

**Risk:** MEDIUM
**Work items:** 3.0–3.4

#### What was done
- Finance: `PlFactMonthlyAmountsQuery` + `FinanceFacade::getPlFactAmountsByMonth()`
  — факт по месяцам из `pl_daily_totals` одним `GROUP BY`, знак отчёта,
  только статьи с `pl_category_id`, точная пара.
- Budget: `BudgetTreeCalculator` (дерево и колонки — общий для сетки и
  план–факт; `BudgetGridBuilder` переведён на него), `BudgetVariance` (порог
  10%), `BudgetPlanFactBuilder`, `BudgetPairResolver` (общий для `/grid` и
  `/plan-fact`), `GET …/plan-fact`, 422 `budget_invalid_pair`.
- «Итог года» в списке: `BudgetCellsQuery::yearTotalsByVersion()` +
  `BudgetYearTotals` — один расчёт на все варианты года, колонка только при
  строке `NET_PROFIT`.

#### Решения по ходу
- Проект в факте — точный, не с дочерними (так в первоначальном плане):
  план вводится по точной паре, иначе факт родителя включал бы факт дочернего
  проекта, у которого свой план. Тест сначала был на дочерних — переписан
  красным, затем зелёный.

#### Definition of Done
- [x] TDD: `PlFactAmountsByMonthTest` (4), `BudgetVarianceTest` (8),
      `BudgetPlanFactBuilderTest` (2), `BudgetYearTotalsTest` (2), functional —
      эндпоинт и колонка списка (красные → зелёные).
- [x] Запросы: факт — 1 запрос, план — 1, калькулятор — справочник статей;
      итог года — 1 запрос сумм на все варианты; не зависят от числа статей.
- [x] `ARCHITECTURE.md` 2.130, `checkpoint.md`.

#### External review
- required: no (MEDIUM); вся ветка — на handoff второго PR.

#### Next
- Stage 4: вкладка «План–факт» (frontend).
