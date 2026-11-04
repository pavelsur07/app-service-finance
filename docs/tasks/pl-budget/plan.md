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
Экран 3 `ui-ux.md`: сетка «статьи × 12 месяцев», клавиатура, вставка из
Excel, автосохранение, комментарии. Ждёт ответа Владельца о знаке при вводе
(`ui-ux.md` §7.1) — до ответа ввод идёт по предложению, конверсия знака
только во фронте.

## Stage 3: план–факт
Risk: MEDIUM
Экран 5 `ui-ux.md`. Факт из `pl_daily_totals` через Finance, дерево — тем же
`FinanceFacade::calculatePlTree()`.

## Stage 4: помощники заполнения
Risk: MEDIUM
Экран 4 `ui-ux.md`.
