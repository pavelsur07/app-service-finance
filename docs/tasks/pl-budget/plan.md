# pl-budget: модуль планирования ОПиУ (MVP)

Спецификация и решения Владельца — `TASK.md`. Класс — Large (новый модуль,
схема, миграция). Разработка через TDD: тест → красный → код → зелёный.
Каждый этап начинается с `stages/stage-N.md`: задача, результат, что считаем
выполненным, чек-лист приёмки; итог дописывается туда же.

Baseline (04.11.2026, ветка `feat/pl-budget` от `ecf23ca53`): тестовая БД
своей парой pg+redis (`asfb-*`), миграции до `Version20261103150000` накатились;
`tests/Integration/Billing/AssignTariffActionTest.php` — OK (6 тестов).

## Stage 0: модель и операции без UI
Risk: HIGH-LOCAL (миграция, новый модуль)
Подробно — `stages/stage-0.md`.

## Stage 1: страницы и API бюджета (backend)
Risk: MEDIUM
Подробно — `stages/stage-1.md`.

## Stage 2: сетка ввода плана (frontend, React-остров)
Risk: MEDIUM
Подробно — `stages/stage-2.md`. Знак при вводе — решение Владельца 04.11.

## Stage 3: план–факт и итог года (backend)
Risk: MEDIUM
Факт из `pl_daily_totals` через Finance, дерево — тем же
`FinanceFacade::calculatePlTree()`; отклонение с порогом 10%; колонка «Итог
года» в списке вариантов (строка с кодом чистой прибыли).

## Stage 4: план–факт (frontend)
Risk: MEDIUM
Экран 5 `ui-ux.md`, вкладка «План–факт».

## Stage 5: помощники заполнения
Risk: MEDIUM
Экран 4 `ui-ux.md`.

## Часть 4 (после MVP): Excel и правило «% от выручки»

Решения по умолчанию — `TASK.md`, «Часть 4». Два PR: Excel (этапы 6–7, без
миграции) и правило (этапы 8–9, миграция).

## Stage 6: экспорт в Excel
Risk: LOW–MEDIUM. Подробно — `stages/stage-6.md`.

## Stage 7: импорт из Excel
Risk: MEDIUM (запись плана). Подробно — `stages/stage-7.md`.

## Stage 8: правило «% от выручки» — модель и пересчёт
Risk: HIGH-LOCAL (миграция, производные данные в транзакции записи).

## Stage 9: правило в сетке (frontend)
Risk: MEDIUM.

