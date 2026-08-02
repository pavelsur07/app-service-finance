# Handoff: ночная загрузка остатков Wildberries

## Итог

Одна причина, одна правка: у WB Inventory не было ни консольной команды, ни строки в
кроне — работала только кнопка в UI. Добавлено зеркало Ozon-схемы. Сам pipeline загрузки
и нормализации не менялся: он исправен и подтверждён данными (1164 строки за два дня,
когда кнопку нажимали).

## Stage

| Stage | Результат | Риск | Review |
|---|---|---|---|
| 1 | `app:inventory:wb-daily-sync` + cron 04:15 MSK + докиs | 🟡 MEDIUM | REVIEW_GREEN, 3 итерации |

Work items: 1.1 команда, 1.2 тест, 1.3 cron, 1.4 `ARCHITECTURE.md`.
Детали и разбор находок ревью — `stages/stage-1.md`.

## Миграции

Нет. Схема БД не менялась, деструктивных операций нет, backfill не требуется.

## Изменённые публичные контракты

Нет. Новая консольная команда — единственная новая точка входа; HTTP-эндпоинты, Facade,
Message и Enum не менялись.

## Проверки

- `composer test:unit` → OK (1720 tests)
- `composer test:integration` → OK (914 tests)
- php-cs-fixer по трём изменённым PHP-файлам → чисто (репозиторный baseline красный до задачи)
- Внешнее ревью Codex CLI 0.146.0 → `REVIEW_GREEN`

## Риски

1. **Расширение охвата.** Активных WB SELLER-подключений три, снимки делала одна компания.
   Первый ночной прогон затронет все три.
2. **Застрявшая сессия блокирует навсегда.** `findLatestActiveByCompanyAndSource()` не
   ограничен возрастом: сессия в `pending`/`in_progress` после смерти воркера отменит все
   последующие ночные прогоны молча. Сейчас застрявших нет.
3. **Разрыв не восстановим.** WB отдаёт остатки на текущий момент; 15.07–02.08 останется дырой.

## Follow-ups (сознательно вне scope)

- Age-limit или ручной сброс для застрявших `pending`/`in_progress` сессий — касается и
  Ozon, отдельная задача.
- Общая база для двух daily-sync команд: они различаются только маркетплейсом и Action'ом.
  Не сделано, чтобы не трогать работающий Ozon-путь ради косметики.
- Маскирование `$e->getMessage()` в cron-выводе, если решим ужесточить политику — тогда
  сразу для обеих команд.

## Production Gate — что делать после мержа

Разрешения на это ещё нет; ниже — план, не выполненные действия.

1. Деплой (cron-файл попадает в контейнер `scheduler`).
2. Проверить, что supercronic подхватил строку:
   `ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-docker-ps"`
3. После первого прогона (04:15 MSK) сверить:
   `ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-psql-ro -c \"SELECT source, trigger_type, status, started_at, received_pages FROM inventory_snapshot_sessions WHERE source = 'wildberries' ORDER BY started_at DESC LIMIT 5;\""`
   Ожидание: свежая строка с `trigger_type = scheduled_night`, `status = completed`.
4. Проверить, что данные легли:
   `ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-psql-ro -c \"SELECT snapshot_date, count(*) FROM inventory_stock_snapshots WHERE source = 'wildberries' GROUP BY snapshot_date ORDER BY snapshot_date DESC LIMIT 5;\""`
   Ожидание: новая дата поверх 2026-07-14.
