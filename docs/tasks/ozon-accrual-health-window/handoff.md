# Handoff: оконный health-гейт daily maintenance Ozon accrual

## Summary

Одноэтапная задача (Stage 1, 🟡 MEDIUM) по итогам инцидента 2026-07-22:
ночной `app:ingestion:ozon-accrual:daily-maintenance` давал вечный `logger->error()`,
потому что health-гейт считал неклассифицированные транзакции глобально, а ремонт
ограничен скользящим окном (`--days-back=45`). На проде 28 строк за 2026-06-03..04
(`type_id=95`, `customerreviews`) выпали из окна в день создания маппинга (2026-07-20)
и стали недостижимы для refresh.

Фикс: health-гейт получает окно ремонта команды и считает только строки,
которые этот прогон реально может переписать. ERROR снова означает
«свежие данные сломались», а не «есть исторический хвост».

## Изменённые файлы

- `site/src/Ingestion/Infrastructure/Query/ExternalCategoryAdminQuery.php` —
  `unclassifiedOzonAccrualTransactions(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null)`,
  окно по `occurred_at`, `$to` inclusive → `+1 day` exclusive (паттерн `canonicalGroups`).
- `site/src/Ingestion/Command/OzonAccrualDailyMaintenanceCommand.php` —
  `health()`/`printHealth()` принимают окно; оба вызова (execute и dry-run) оконные;
  формулировки «global taxonomy health» → «taxonomy health».
- Тесты: `ExternalCategoryAdminQueryTest` (окно с граничными датами: `$from`-день,
  `$to`-день in; `$to`+1 и до окна out), `OzonAccrualDailyMaintenanceCommandTest`
  (FAILURE при неклассифицированной строке в окне, SUCCESS вне окна).

## Миграции

Нет.

## Изменённые публичные контракты

- Сигнатура `ExternalCategoryAdminQuery::unclassifiedOzonAccrualTransactions()` —
  расширена опциональными параметрами, обратно совместима. Прочие потребители
  (админ-дашборд `IngestionExternalCategoriesController`, `MarketplaceCategoryStatusCommand`)
  сознательно остались глобальными.
- Семантика health-гейта maintenance-команды сужена до окна ремонта — это и есть цель.

## Review

Внутренний review — green. Внешний независимый read-only review — REVIEW_GREEN
(2 MINOR: граничные даты → исправлено усилением теста; baseline-допущение → отклонено
с обоснованием). Детали в `stages/stage-1.md`.

## Риски

- Хвост старше окна перестаёт влиять на exit code / ERROR ночного прогона. Он остаётся
  виден в админ-дашборде и `marketplace-categories:status`; чинится разовым прогоном
  с явными `--from/--to` (использовано для инцидентных 28 строк).
- Дрейф границ: naive-сравнение `occurred_at` идентично прецеденту `canonicalGroups`,
  колонка `DATETIME_IMMUTABLE` (naive) — расхождений с ремонтным окном нет.

## Follow-ups (сознательно вне scope)

1. Field-based строки без `_ingestion_type_id`: поле вне whitelist
   `OzonAccrualCategory::forField()` даёт NULL-категорию, невидимую для discover
   (на проде таких строк 0, дыра латентная).
2. `verify-rolling-refresh`: `unknownCategoryRows` слеп к NULL-категории
   (`ozonCategoryKnown ?? true`) — то же семейство.
3. Разовый backfill инцидентных строк на проде:
   `app:ingestion:ozon-accrual:daily-maintenance --from=2026-06-03 --to=2026-06-04 --execute`
   (dry-run проверен: 46 обновлений, 0 сбоев; запуск — за Владельцем).
