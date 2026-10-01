# Stage 3 Report: Action сверки и ручной запуск

Сделано: `OzonRawCoverage`, DTO (`OzonReconciliationInputs`, `ReconciledLine`, `OzonReconciliationResult`), чистый `OzonReconciliationCalculator`,
`RunOzonReconciliationAction` (транзакция, upsert Run, пересоздание строк, логи старта/финиша с companyId), `InvalidReconciliationPeriodException`,
команда `app:marketplace:ozon-reconciliation:run`.
Проверки: unit калькулятора и покрытия 18 OK (все ветки: сошлось, расхождение, допуск 1 ₽, нет «Реализации», нет сырья, частичная загрузка,
нераспознанные услуги, перепутанный знак); integration Action 4 OK (снимок, идемпотентность, расхождение и обновление снимка, чужая компания, период > месяца);
модуль `tests/Unit/Marketplace` + `Integration/Marketplace/{Ozon,Repository}` — OK 895; PHPStan — 0 ошибок; cs:check — 0; `lint:container` — OK.
Окружение: часть интеграционных тестов каталога Ozon падала из-за не запущенного локального Redis (`site-redis`) — не связано с задачей, после старта зелёные.
Internal review: BLOCKER/IMPORTANT нет.
- Общий статус при неполной загрузке дней не лучше NO_DATA — осознанно: сверена только загруженная часть.
FOLLOW-UP: гонка двух первых запусков одного периода упрётся в уникальный индекс (запуски последовательные; cron в Stage 6 обходит компании по одной).
