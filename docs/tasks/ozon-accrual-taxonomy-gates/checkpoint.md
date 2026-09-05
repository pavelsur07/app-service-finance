# Checkpoint — ozon-accrual-taxonomy-gates

### Текущее состояние
- Ветка: `fix/ozon-accrual-taxonomy-gates`
- `stage_base_commit`: `63a8db2ad4d35059f43863a653a90416bccf26d2`
- Stage 1 — DONE, см. `stages/stage-1.md`.

### Решения Владельца (получены в чате 2026-09-05)
- `LabelBrandVerified` → своя статья `ozon_brand_verification_labeling`, группа «Другие услуги и штрафы», FEE
- `Installment` → своя статья `ozon_installment`, группа «Другие услуги и штрафы», FEE
- Исторические 36 строк пересчитывать за август и сентябрь

### Exact next action
- Ждём решения Владельца по merge и сроку деплоя (окно пересчёта закрывается ~15 сентября 2026).

### Files to inspect first on resume
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualCategory.php`
- `site/src/Ingestion/Infrastructure/Query/ExternalCategoryAdminQuery.php`
- `site/src/Ingestion/Command/OzonAccrualDailyMaintenanceCommand.php`
- `site/src/Ingestion/Command/OzonAccrualVerifyRollingRefreshCommand.php`
