# Этап 2. Журнал запусков и проверка «тишины»

## Задача
Видеть, что cron ИИ работает, и узнавать, когда перестал.

## Результат
- Таблица `ai_run`: строка на компанию за запуск `ai-categorize` — время, отправлено операций,
  задач, переотправлено, просрочено, причина пропуска (`no_categories`, `budget_exceeded`, …),
  ошибка. Пишет `CashAiCategorizeCommand` через `AiTaskFacadeInterface::recordRun()`.
- Команда `app:ai-service:health-check` (cron раз в час, :50): при включённом сервисе
  и подключённых компаниях —
  - `error`, если у подключённой компании нет записи в `ai_run` дольше 3 ч;
  - `error`, если за 24 ч ≥ 10 результатов и из них ≥ 50 % `failed`;
  - `warning` по компаниям, исчерпавшим лимит (этап 3).
  Выключенный сервис или ни одной компании — молча 0.
- Строка в `docker/cron/app.cron`, описание в `docs/workflow/health-gates.md`.

## Что считаем выполненным
Код, миграция, тесты, cron-строка в той же ветке.

## Чек-лист приёмки
- [x] запуск cron по компании пишет строку `ai_run`, в т. ч. при пропуске и при сбое компании
- [x] health-check: тишина > 3 ч → error с id компаний; свежий запуск → 0 ошибок
- [x] health-check: доля failed ≥ 50 % при ≥ 10 результатах → error; меньше 10 — молчит
- [x] выключенный сервис → exit 0 без сообщений
- [x] старые строки `ai_run` чистятся (хранить 90 дней) — без роста таблицы

## Итог
Готово. Миграция `Version20261106100000` — `ai_run`; `CashAiCategorizeCommand --all-enabled` идёт по
всем подключённым компаниям и пишет запуск, пропуск (`disabled`/`locked`/`budget_exceeded`/`awaiting_results`)
и сбой. Гейт `app:ai-service:health-check` — cron :50, «Одиннадцатый пример» в `health-gates.md`.
Тесты: `AiServiceHealthCheckCommandTest`, `CashAiCategorizeCommandTest`.
