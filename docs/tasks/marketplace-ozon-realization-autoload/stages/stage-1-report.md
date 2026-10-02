# Stage 1 Report: окно опроса и команда-планировщик

Сделано: `FinancialReportSyncMode::POLL` (строка в БД, миграции нет), `OzonRealizationReport`, `OzonRealizationPollWindow` (МСК, с 18:00 1-го до 23:59:59 8-го), команда `app:marketplace:ozon-realization-poll` (`--company-id`, `--dry-run`).
Проверки: unit окна 19 OK (границы 17:59/18:00 1-го, 8-е/9-е, декабрь, февраль 2028, МСК против UTC); integration команды 8 OK (вне окна, в окне — одна задача на компанию за прошлый месяц, повторный прогон в тот же час не дублирует, EMPTY с будущим `nextRetryAt` не берётся и берётся после срока, терминальные статусы пропускаются, неактивная компания, `--company-id`, dry-run, валидация);
`FinancialReportSync*` 116 OK; PHPStan `src/Marketplace` — 0; cs — 0; `lint:container` — OK.
Находки по ходу: `claimForQueue` не уважает `nextRetryAt` у статуса EMPTY, поэтому срок повтора проверяется в команде для всех статусов; seller-подключение у компании одно (уникальный индекс), дедупликация по компании остаётся страховкой.
Internal review: BLOCKER/IMPORTANT нет.
