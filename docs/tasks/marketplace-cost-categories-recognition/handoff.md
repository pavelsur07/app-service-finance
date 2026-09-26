# marketplace-cost-categories-recognition — handoff

**Branch:** `feat/marketplace-cost-categories-recognition` · base `85038031`

## Summary
- Ozon: `PremiumSubscription` → `ozon_premium_promotion`, `TemporaryPlacement` → `ozon_temporary_storage`
  (решение Владельца); ещё 14 услуг справочника by-day размечены заранее — у каждой целевая
  категория уже получала деньги легаси-путём и имеет правило ОПиУ.
- Ozon: остальные 74 услуги явно перечислены в `OzonCostCategoryTest::AWAITING_CLASSIFICATION`;
  услуга справочника без решения роняет тест.
- WB: «WB Медиа», «Джем», «Баллы за отзывы» — в `WbCostCategory` под существующими кодами
  (миграция данных не нужна); правило `wb_okazanie_uslug_wb_media` → `PROMO_INTERNAL`.
  «Добровольная выплата за товары» — вне ОПиУ, без изменений.
- Миграция `Version20260926120000`: 5 строк (60 270,00 руб.) из `ozon_unknown_52/78` в категорию
  каталога той же компании; только вне документа ОПиУ и заблокированного периода; меняется
  только `category_id` и описание-имя; опустевшие неразобранные категории мягко удаляются.
  Обратима; бэкап не нужен — суммы, знаки, `external_id` не меняются.

## Checks
- Регрессия: 18 новых проверок Ozon и 3 WB красные на старом коде, зелёные на новом.
- unit 3026 OK; полный `make site-test` 5236 OK (до правок по ревью; после — затронутые наборы
  Ozon 286 и тест миграции 2/23 OK); PHPStan level 8 OK, baseline не изменён; cs, strict-types OK.

## Reviews
- internal (fresh session): BLOCKER 0, IMPORTANT 2, MINOR 5. Исправлено: down() учитывает
  блокировку периода; восстановление только категорий с вернувшимися строками; мягкое удаление
  не трогает категорию с правилом ОПиУ; тесты границы; `CustomerReviews` размечена.
  IMPORTANT «правило ОПиУ у компании» — не код, вынесен ниже. MINOR `RfbsServiceFee` оставлен:
  при ошибке строка ОПиУ та же (`OPEX_WH_MP_DEDUCTIONS`).
- external (Codex, medium): 1 раунд, `REVIEW_GREEN`.
- Метрики: internal BLOCKER 0 / IMPORTANT 2; external BLOCKER 0 / IMPORTANT 0.

## После деплоя (приёмка read-only)
- В `ozon_unknown_52/78` 0 строк, в целевых +5; `SUM(amount)` затрат затронутых компаний не
  изменился; `ozon_unknown_52/78` мягко удалены.

## Действие Владельца, не код
Категория распознана, но в ОПиУ строка попадёт, только если у компании есть правило. Нет правила:
- `ozon_premium_promotion` — «Вумджой ООО», «Музыкальные инструменты»;
- `ozon_temporary_storage` — «Музыкальные инструменты»;
- `wb_predostavlenie_uslug_po_podpiske_dzhem` — «ИП Лазарева».
Применить базовый маппинг: `/marketplace/cost-pl-mapping/default/apply` в этих компаниях.

## Follow-ups
- Ingestion-каталог относит `TemporaryPlacement` к `ozon_temporary_partner_storage`; конвейеры
  расходятся по группе xlsx-сверки.
- Правило `ozon_charity` в yaml помечено `confidence: low`.
- Пустые категории-пустышки прежнего разбора (`ozon_unknown_29/45/77`, `ozon_logistics` и др.).
- Помесячные коды утилизации WB → `wb_warehouse_disposal` (6 строк, 5 054 руб.).
