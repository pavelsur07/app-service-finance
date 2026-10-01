# Stage 6: автоматизация и алерт

stage_base_commit: 501590b5
Risk: MEDIUM (cron)

## Планирование
- Команда-гейт `app:marketplace:ozon-reconciliation:check [--company-id] [--report-only]`: по каждой компании с активным Ozon SELLER-подключением
  (`ActiveOzonConnectionsQuery`, дедуп по компании) пересчитывает снимки за текущий и прошлый месяц (МСК) через `RunOzonReconciliationAction`.
- Охват гейта = охват починки (`docs/workflow/health-gates.md`): красным считаем только расхождения проверки «сырьё ↔ учёт» (чинится `ozon-financial-reports:sync` + `app:marketplace:reprocess`)
  и сбои самой сверки. Расхождения «Реализация ↔ сырьё» — данные самого Ozon, чинить нечем: `warning` и строка в выводе, exit 0.
  `NO_DATA` — не красное (нет «Реализации» за текущий месяц — норма).
- Один агрегированный `error` со счётчиками (компании, месяцы, строки, сбои; список компаний ≤ 20), без сумм и без тел; ошибка компании не обрывает обход (счётчик сбоев).
- `--report-only` — пересчитать и вывести, не падать (для ручного просмотра).
- Cron 07:10 (after by-day 04:00 и freshness-check 06:50), строка в `docker/cron/app.cron`; документация: `docs/workflow/health-gates.md`, `docs/maintenance/prod-access.md`, `ARCHITECTURE.md`.
- Разрешение в `codex-console` НЕ добавляется: команда пишет снимки (мутация прода), решение за Владельцем — в handoff.
Тесты (integration, реальная БД): красный при расхождении «сырьё ↔ учёт» (exit 1, один error), зелёный при совпадении, расхождение «Реализации» → warning/exit 0, компания без Ozon-подключения не трогается,
`--report-only` не падает, `--company-id` ограничивает обход, неверный аргумент → INVALID.
Вне охвата: Messenger, прод-деплой cron.

## Work items
- 6.1 команда + тесты
- 6.2 cron + документация
