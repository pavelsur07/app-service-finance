# Checkpoint — cron-failure-observability

### Текущее состояние
- Ветка: `fix/cron-failure-observability`
- `stage_base_commit`: `63a8db2ad4d35059f43863a653a90416bccf26d2`
- Stage 1 — DONE, см. `stages/stage-1.md`. Подписчик и трекер удалены по итогам внешнего ревью.

### Что показали данные прода
- Issue 2 в GlitchTip — 553 события с 2026-02-20. В выборке последних 100 событий:
  `app:mailer:healthcheck` 44 (8–10 августа), `ozon-accrual:verify-rolling-refresh` 22,
  `ozon-accrual:daily-maintenance` 22, `app:marketplace-ads:scheduler` 10 (20 авг – 4 сен),
  `app:storage:healthcheck` 2.
- У `app:marketplace-ads:scheduler` собственного issue в GlitchTip нет вообще.

### Exact next action
- Ждём решения Владельца по merge и по корзине supercronic.

### Files to inspect first on resume
- `site/config/packages/monolog.yaml`
- `site/tests/Unit/Shared/Infrastructure/Monolog/SentryChannelCoverageTest.php`
- `site/src/MarketplaceAds/Command/AdBatchSchedulerCommand.php`
