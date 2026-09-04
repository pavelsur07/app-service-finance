# Plan: WB bug hunt

Task base commit: `e7111f424b8ed17202ff7f1c1dc60adf26a7eb84` (master, 2026-09-04)
Branch: `fix/wb-bug-hunt-2026-09`

## Phase 0: поиск дефектов

Источники и результат:

- **GlitchTip** (`./gt.sh list 50`): открытых инцидентов по WB нет. Все
  текущие issue — Ozon Performance 404, маршруты, Ozon accrual verify,
  cron `verify-rolling-refresh` (issue #2), FK при удалении PL-категории.
- **Бэклог** (`docs/plan/my_paln_app.md`, п.1): «при загрузке себестоимости
  Ozon по артикулу поставщика себестоимость утекла на листинги WB». По коду
  не воспроизводится: `ImportInventoryCostPriceFromFileAction`, оба
  Repository-метода по `supplier_sku`, `findByBarcode`, `SetInventoryCostPriceAction`
  и все читатели `marketplace_inventory_cost_prices` фильтруют по
  `marketplace` и `listing_id`; форма импорта требует явного выбора
  маркетплейса. Запись в бэклоге датирована 2026-07-26, импорт по артикулу
  для WB появился 2026-07-29 (`ee3222da`), и уже тогда фильтр по маркетплейсу
  был. Подтвердить или опровергнуть можно только по данным прода
  (`marketplace_job_logs`, тип `cost_price_import`) — требует запроса
  Владельца на PROD-проверку.
- **Аудит кода** (3 read-only агента, 12 находок). Подтверждены и взяты в
  Stage 1 пять IMPORTANT и три MINOR. Остальное — в FOLLOW-UP handoff.

Baseline до правок (2026-09-04):

- `vendor/bin/phpunit --testsuite unit --filter 'Wb|Wildberries'` — OK (553 tests)
- `vendor/bin/phpunit --testsuite integration --filter 'Wb|Wildberries'` — OK (188 tests)
- `composer test:unit` на master — OK (2273 tests, 4 pre-existing deprecations:
  `UserEntityTest`, `MarketplaceProcessCostsRouteTest`)

Рабочее дерево содержит посторонние незакоммиченные изменения Владельца
(`PATTERNS.md`, `docs/plan/my_paln_app.md`, удалённые `docs/tasks/ui-pnl/*`,
untracked `.mimocode/`, `docs/integrations/`, `site/ui-kit/_audit/*`). Они вне
задачи и не стейджатся.

## Stage 1: исправление подтверждённых дефектов WB

Risk: HIGH-LOCAL (финансовые данные: себестоимость возвратов, планировщик
синхронизации отчётов)
owner_gate: yes (финальный handoff)
release_candidate: yes
independently_deployable: yes
stage_base_commit: `e7111f424b8ed17202ff7f1c1dc60adf26a7eb84`

Work items:

- 1.1 `WildberriesAdClient`: транспортная ошибка (таймаут, обрыв) проходит
  через цикл из 3 попыток, как 429/5xx.
- 1.2 `MarketplaceCostPriceResolver` + `WbReturnsRawProcessor`: себестоимость
  возврата резолвится по `orderDt` (camelCase финансового API), а без даты
  заказа — по дате возврата, а не молча `0.00`.
- 1.3 `MarketplaceBarcodeCatalogService::fillFromWbRows`: читает camelCase
  строки через `WbSalesReportRowNormalizer`; раньше справочник
  barcode→size не наполнялся из текущего пайплайна.
- 1.4 `WbFinancialReportSyncPlanner::planRefreshRecentDays`: модель «одна
  строка статуса на день»; день после первого refresh снова попадает в
  скользящее окно, дни initial/missing тоже обновляются.
- 1.5 `WbFinanceReportConnector::pull`: переход на следующий день отдаёт
  `continuationDelaySeconds`, чтобы обработчик не упирался в локальный
  limiter и не тратил попытки rate-limit на каждый день отставания.
- 1.6 MINOR: `ProcessWbCostsAction` — `sellerOperName` через normalizer и один
  агрегированный `error` вместо `error` на строку; `WbAdDailySpendCommand` —
  `warning` для rate-limit/transient; `WbInventoryDailySyncCommand` —
  `warning` в лог при ошибке по компании.

Definition of Done:

- Регрессионные тесты 1.1–1.5 красные на `stage_base_commit`, зелёные после.
- `composer test:unit` зелёный; интеграционные тесты
  `Marketplace|Ingestion|Inventory` зелёные.
- PHPStan по изменённым файлам, php-cs-fixer по изменённым файлам — зелёные.
- Внешнее ревью Codex — `REVIEW_GREEN`.
- Stage Report, checkpoint, handoff записаны; Draft PR создан.
