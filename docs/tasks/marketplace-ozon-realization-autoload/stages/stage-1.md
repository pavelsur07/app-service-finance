# Stage 1: окно опроса и команда-планировщик

stage_base_commit: a86e6a21
Risk: MEDIUM

## Планирование
- `FinancialReportSyncMode::POLL` ('poll', строка в БД, миграции нет): режим планировщика опроса. Существующие `DAILY/MISSING/INITIAL` не позволяют повторно взять пару в статусе `EMPTY` (`canClaimForQueue`), а опросу это нужно каждый час.
- `OzonRealizationReport`: константы `REPORT_TYPE = 'ozon_realization'`, endpoint и бизнес-дата (первый день отчётного месяца).
- `OzonRealizationPollWindow::at(now)` — чистый: МСК; открыто с 18:00 1-го до 23:59:59 8-го; отчётный месяц — предыдущий; иначе `null`.
- Команда `app:marketplace:ozon-realization-poll [--company-id] [--dry-run]`: вне окна — выход 0; в окне — по одной компании (дедуп по company_id): пропуск терминальных статусов (success, auth_failed, failed_final, conflict), `claimForQueue(POLL)` (advisory-блокировка, учёт `nextRetryAt`, залипших `loading/queued`), постановка `SyncOzonRealizationMessage`.
  `--dry-run` ничего не пишет и не ставит.
- Логи: info агрегированно (компаний, поставлено, пропущено), без ключей.
Тесты: unit окна (граница 17:59/18:00 1-го, 8-е 23:59:59/9-е 00:00, январь→предыдущий декабрь, МСК vs UTC); integration команды (вне окна, в окне, success/auth_failed пропускаются, retry в будущем пропускается, повторный прогон не дублирует постановку, чужая и неактивная компании, dry-run, один месяц/одна задача на компанию при двух подключениях).
Вне охвата: обработчик загрузки, обработка, сверка, cron.

## Work items
- 1.1 POLL + Report + окно + unit
- 1.2 команда + integration-тесты
