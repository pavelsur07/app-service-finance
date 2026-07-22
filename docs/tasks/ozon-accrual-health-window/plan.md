# Task: оконный health-гейт daily maintenance Ozon accrual

## Проблема (инцидент 2026-07-22)

Ночной `app:ingestion:ozon-accrual:daily-maintenance` чинит метаданные категорий
только внутри скользящего окна (`--days-back=45`), а health-гейт считал
неклассифицированные транзакции **глобально**
(`ExternalCategoryAdminQuery::unclassifiedOzonAccrualTransactions()` без фильтра дат).

На проде 28 строк за 2026-06-03..04 (`type_id=95`, `customerreviews`) выпали из окна
19-20 июля — в тот же день, когда оператор создал маппинг категории. Refresh их больше
никогда не сканирует → гейт красный вечно → ночной `logger->error()` каждый день
(ложный алерт в GlitchTip, категория при этом давно mapped, `unmappedCategories=0`).

Разовый backfill (`--from=2026-06-03 --to=2026-06-04 --execute`) гасит текущий алерт;
этот фикс устраняет класс проблемы: гонку «замапили в день выпадения из окна».

## Scope

Один этап (🟡 MEDIUM):

1. `ExternalCategoryAdminQuery::unclassifiedOzonAccrualTransactions(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null)`
   — опциональное окно по `occurred_at` (`$to` включительно → `+1 day` exclusive,
   паттерн как в `OzonAccrualVerifyRollingRefreshCommand::canonicalGroups`).
2. `OzonAccrualDailyMaintenanceCommand::health()` / `printHealth()` принимают окно
   и передают его в запрос (оба вызова: execute-путь и dry-run).
3. Прочие потребители запроса — админ-дашборд (`IngestionExternalCategoriesController`)
   и `MarketplaceCategoryStatusCommand` — сознательно остаются глобальными (без аргументов).
4. Тесты: оконный запрос (in/out-of-window) + команда (FAILURE при неклассифицированной
   строке в окне, SUCCESS при строке вне окна).

## Вне scope (follow-ups)

- Латентная дыра: field-based строки без `_ingestion_type_id` с полем вне whitelist
  `OzonAccrualCategory::forField()` получают NULL-категорию и невидимы для discover.
  На проде таких строк 0 — отдельная задача.
- `verify-rolling-refresh` считает unknown только по `ozonCategoryKnown === false`
  (дефолт `?? true` при NULL-категории) — то же семейство, тот же follow-up.
