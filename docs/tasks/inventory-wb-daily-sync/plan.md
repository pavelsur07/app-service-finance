# Задача: ночная загрузка остатков Wildberries

## Контекст

Владелец сообщил, что остатки WB не загружаются несколько недель. Диагностика на PROD
(read-only) показала, что автоматизации не существовало: у Ozon есть команда
`app:inventory:ozon-daily-sync` и строка в `docker/cron/app.cron`, у WB — только кнопка
в UI. Последний ручной запуск был 2026-07-14, с тех пор данных нет.

Замеры «до» (PROD, 2026-08-02):

| Показатель | Ozon | Wildberries |
|---|---|---|
| Сессий всего | 249 completed, 1 failed | 3 completed, 1 failed |
| Тип триггера | scheduled_night | все 4 — manual |
| Последняя сессия | 2026-08-02 04:05 | 2026-07-14 07:49 |
| Строк в `inventory_stock_snapshots` | 33103 за 84 дня | 1164 за 2 дня |
| Raw необработанных | 7 (все от 2026-05-11) | 0 |
| Активных SELLER-подключений | — | 3 |

Исключено проверкой: воркеры живы, `async_sync` пуст, в failed-очереди 3 сообщения
(`Finance`, `Company` ×2, ни одного Inventory), зависших `pending`/`in_progress`
сессий нет, WB API-клиент рабочий (3 из 4 ручных прогонов успешны).

## Stage 1: ночной cron для WB Inventory

Risk: 🟡 MEDIUM
owner_gate: no
release_candidate: yes
independently_deployable: yes
stage_base_commit: `b68e0d5ed93fc95a2047eb673fecdb73c156d16d`

Definition of Done:
- Команда `app:inventory:wb-daily-sync` диспатчит снимок по всем активным WB SELLER-подключениям.
- Фильтрация по marketplace: Ozon-подключения не порождают WB-сессий.
- Идемпотентность: `LockableTrait` + active-session guard не дают дублей.
- Прогон, не давший ни одной задачи из-за ошибок, завершается ненулевым exit code.
- Строка в `docker/cron/app.cron`, `ARCHITECTURE.md` синхронизирован с кодом.
- Тесты зелёные, php-cs-fixer по изменённым файлам чист, внешнее ревью `REVIEW_GREEN`.

Work items:
- 1.1 — `WbInventoryDailySyncCommand` (зеркало Ozon-команды)
- 1.2 — интеграционный тест команды
- 1.3 — cron-строка `15 4 * * *` (04:15 MSK)
- 1.4 — `ARCHITECTURE.md`: таблица cron-задач и раздел WB FBW normalization

Карта изменений: новых Entity, Repository, Facade, Message и миграций нет — задача
переиспользует готовый pipeline `RequestWbInventorySnapshotAction` →
`SyncWbInventorySnapshotMessage` → `SyncWbInventorySnapshotHandler` →
`NormalizeInventorySnapshotAction`.

Stage checks:
- `php bin/phpunit --testsuite=integration --filter WbInventoryDailySyncCommandTest`
- `composer test:unit`, `composer test:integration`
- php-cs-fixer по изменённым файлам (репозиторный baseline красный)

Reviewer focus:
- команда диспатчит WB, а не Ozon;
- выбор cron-слота и конфликты с соседними задачами;
- поведение при нуле подключений и при ошибках.

## Production Gate (не входит в Stage 1)

Деплой и первый ночной прогон — отдельное разрешение Владельца. Разрыв 15.07–02.08
не восстанавливается: `/api/v3/stocks` отдаёт остатки на текущий момент.
