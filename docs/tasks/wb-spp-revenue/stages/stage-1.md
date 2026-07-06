# Stage 1: Формула «Выручка без СПП» и ключи комиссии WB finance API — DONE

**Риск:** 🔴 HIGH (правка в финансовом pipeline Marketplace)
**Следующее действие:** 🛑 STOP — ревью Владельцем, затем Этап 2 (пересчёт данных PROD за июнь и июль)

## Контекст (диагностика)

При закрытии июня 2026 (компания `b57d7682…`) статьи «Продажи без СПП» (REV_NOT_SPP)
и «Продажи с СПП» (REV_SPP_SALES) получились равны: 1 584 515.68 (разница в отчёте
3 225.00 — это возвраты, не СПП). Причины:

1. Июньские `marketplace_sales` импортированы кодом до `709ad606` (2026-07-02),
   где и `price_per_unit`, и `total_revenue` заполнялись из `retailPriceWithDisc`.
2. Новый код (после `709ad606`) тоже неверен:
   - реконструкция `|forPay| + комиссия + эквайринг` не сходится с ценой продавца:
     в finance API `forPay` учитывает СПП-компенсации WB (PROD: 0 из 2423 строк сошлись);
   - комиссия читалась по ключам `ppvzVw`/`ppvz_vw`, а finance API отдаёт `vw`/`vwNds`
     → комиссия всегда 0 (ломало и `WbCommissionCalculator`).

Правильные значения за июнь (из raw_data PROD):
- без СПП = `retailPriceWithDisc × qty` = 1 584 515.68
- с СПП = `retailAmount` = 916 861.78 (СПП ≈ 45%)

## Что сделано

- `WbSalesReportRowNormalizer::grossWithoutSpp()` → `abs(retailPriceWithDisc) × abs(quantity)`.
  Правка в общей функции чинит все три пути: `WbSalesRawProcessor`, `ProcessWbSalesAction`,
  `WildberriesAdapter::fetchSales`.
- `ppvzVw()` / `ppvzVwNds()` → добавлены алиасы `vw` / `vwNds`.
- `WbFinanceSalesReportDetailedPreviewMapper` → те же алиасы (превью инжеста).
- Регрессионный тест на реальной строке finance API (ключи `vw`/`vwNds`, СПП 44.85%):
  `testFinanceApiRowGrossWithoutSppIsSellerPriceAndVwKeysAreRead` — красный на старом коде.
- Обновлены тесты, «приглаженные» под старую формулу (ожидания 2493→3000 и т.п.).
- `WB_API_V5_FIELDS.md`: формула и ключи исправлены, старая формула помечена как неверная.

## Затронутые файлы

- `site/src/Marketplace/Infrastructure/Normalizer/Wildberries/WbSalesReportRowNormalizer.php` — modified
- `site/src/Ingestion/Application/Source/Wildberries/WbFinanceSalesReportDetailedPreviewMapper.php` — modified
- `site/src/Marketplace/WB_API_V5_FIELDS.md` — modified (docs)
- `site/tests/Unit/Marketplace/Infrastructure/Normalizer/Wildberries/WbSalesReportRowNormalizerTest.php` — modified (+регрессионный тест)
- `site/tests/Unit/Marketplace/Infrastructure/Wildberries/WbSalesReportRowNormalizerTest.php` — modified
- `site/tests/Unit/Marketplace/Application/Processor/WbSalesRawProcessorRevenueTest.php` — modified
- `site/tests/Unit/Marketplace/Service/Integration/WildberriesAdapterTest.php` — modified
- `site/tests/Integration/Marketplace/Application/WbRawForceReprocessRegressionTest.php` — modified

## Self-review

- [x] Scope compliance — только формула/ключи + тесты + docs
- [x] Patterns / naming — без изменений структуры
- [x] Forbidden actions — none
- [x] Security (companyId, IDOR) — N/A, запросы не менялись
- [x] Тесты: unit suite 1269 OK; WB-фильтр 91 OK; интеграционные WB regression 4 OK
- [x] CS-Fixer — чисто на изменённых файлах (репо-wide долг 672 файлов — pre-existing)
- [ ] PHPStan — в проекте не установлен (vendor/bin/phpstan отсутствует)
- [x] ARCHITECTURE.md — N/A (Facade/Enum/Entity не менялись)

## Команды для проверки

- `docker compose run --rm -T site-php-cli php bin/phpunit --filter "WbSalesReportRowNormalizerTest|WbSalesRawProcessorRevenueTest|WildberriesAdapterTest|WbRawForceReprocessRegressionTest"`

## Риски / на что обратить внимание ревьюеру

- Семантика `grossWithoutSpp` изменилась с «реконструированная сумма» на «цена продавца».
  Все места, где ожидалась старая (неверная) сумма, обновлены; других потребителей нет.
- Существующие строки PROD не изменятся сами — нужен Этап 2 (пересчёт июня/июля).
- В `WbRawForceReprocessRegressionTest` helper получил явный параметр `retailPriceWithDisc`,
  чтобы тест по-прежнему доказывал факт репроцессинга (gross 1584 → 2099).

## Открытые вопросы

- Этап 2: пересчёт PROD (июнь+июль, обе компании) — UPDATE из raw_data + перепроведение
  закрытия июня. Ждёт одобрения и деплоя этого PR.
