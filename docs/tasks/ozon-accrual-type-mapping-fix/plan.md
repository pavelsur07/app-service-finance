# ozon-accrual-type-mapping-fix: неверная разметка начислений Ozon в Ingestion

Класс: **Large** — меняется разнесение по категориям (финансовая семантика),
миграция данных маппингов, ремонт истории на проде.

## Ограничение Владельца (18.10.2026)

Работаем **только в модуле Ingestion**: `site/src/Ingestion/`, его тесты,
миграция его таблиц `ingest_*`, документация Ingestion. Операции ОПиУ,
Marketplace, Cash и их маппинги не трогаем.

Проверено, что ОПиУ дефект не задевает: Marketplace разбирает by-day своим
`OzonAccrualServiceCategoryResolver` по `OzonCostCategory.accrualTypeNames`;
`OzonAccrualCategoryFacade` вызывающих вне Ingestion не имеет; поля
`_ozon_category_*` читают только запросы Ingestion (`FinancialSummaryQuery`,
админка категорий, discover/rebuild).

## Дефект — подтверждён на данных прода 18.10.2026

Каталог `OzonAccrualCategory` держит карту `typeIds`, расходящуюся со
справочником Ozon `/v1/finance/accrual/types` (комментарий в
`OzonAccrualCategoryFacade.php` фиксирует это с июня). Seed превратил её в
строки `ingest_external_category_mappings` с ключами `type:*`; резолвер
`OzonAccrualCategoryTaxonomyResolver` берёт их, когда не нашёл маппинг по коду и
имени. Статус категории `deprecated` применение не останавливает: репозиторий
фильтрует только `mapping.status = active`.

Часть алиасов каталога противоречит русскому `description` словаря Ozon.

| Ozon: id, name, description | Сейчас | Должно быть (по `description` и `OzonCostCategory.xlsxGroup`) | Тип | Строк |
|---|---|---|---|---|
| 29 LastMileCourier «Доставка до места выдачи» | `ozon_logistics`, Услуги доставки | `ozon_delivery_to_pickup_partners`, Услуги партнёров | logistics → **last_mile** | 150 137 |
| 45 PickUpPointReturnAcceptance «Обработка возвратов, отмен и невыкупов партнёрами» | `ozon_delivery_to_pickup_ozon`, Услуги доставки | `ozon_partner_return_processing`, Услуги партнёров | last_mile → **fee** | 10 452 |
| 71 SellerReturns «Вывоз товара со склада силами Ozon» | `ozon_partner_return_processing`, Услуги партнёров | `ozon_warehouse_export`, Услуги FBO | fee → **storage** | 464 |
| 46 Placements «Размещение товаров на складах Ozon» | `ozon_partner_placement`, Услуги партнёров | `ozon_warehouse_placement`, Услуги FBO | storage | 589 |
| 84 ItemPacking «Дополнительная упаковка на складе Ozon» | `ozon_partner_packaging`, Услуги партнёров | новая `ozon_warehouse_additional_packaging`, Услуги FBO | fee | 512 |
| 78 TemporaryPlacement «Краткосрочное размещение возврата FBS» | `ozon_temporary_partner_storage`, Услуги партнёров | новая `ozon_fbs_return_short_placement`, Услуги FBO | storage | 11 |
| 77 SupplyInbound «Обработка товара» | `ozon_cargo_place_item_processing` | без изменений — совпадает с Marketplace; ключ `type:77` заменяется алиасом | acceptance | 633 |
| 76 StockInsurance | `ozon_stock_insurance` | без изменений; `type:76` отключается, есть `code:stockinsurance` | fee | 242 |

Строки — все транзакции by-day с 2026-05-17 по 2026-10-17 у 4 компаний.
Суммы не теряются: искажены статья, группа и у трёх типов — `TransactionType`.

## Решение Владельца, нужное до Stage 1

`type` входит в естественный ключ транзакции
(`findByNaturalKey(companyId, source, externalId, type)`) и в группировку
ночной сверки. Урок `docs/tasks/ozon-accrual-retype-boundary/`: смена типа
красит `verify-rolling-refresh` на 45 дней, `refresh-category-metadata` такие
строки не находит, а чинить историю можно только `normalize-stored
--include-done` окнами полного владения снапшотов.

| Вариант | Что | Ремонт истории | Риск |
|---|---|---|---|
| **A** (рекомендую) | Исправить статью, группу **и тип** (29, 45, 71) | `normalize-stored --include-done --execute-inline`, 4 магазина × 2026-05-17…сегодня, окнами снапшотов | Выше: процедура доказана, но в сентябре дала два инцидента, оба закрыты в коде. Гейт сверки красный от деплоя до конца ремонта |
| B | Исправить статью и группу, тип оставить прежним (маппинги в БД несут свой `transaction_type`) | `daily-maintenance --from --to --execute` (меняет только `source_data`), cron сам чинит 45 дней | Ниже, но код статьи и тип расходятся (как уже у `ozon_drop_off`); тип чинить позже той же процедурой A |

Почему A: Ingestion ещё в shadow, типы никто вне модуля не читает. Сейчас
перетипизация дешевле всего, после переключения она станет дороже.

## Вне задачи

- Чтение `description` из словаря, таблица словаря, подсказки в админке,
  закрытый список групп — следующая задача.
- Удаление `OzonAccrualCategoryFacade` (вызывающих нет) — follow-up.
- `LastMile` (id 28) и прочие типы, не встреченные на проде, — не трогаем.

Baseline: записать перед Stage 1 — `make site-test-unit` по
`tests/Unit/Ingestion` и `tests/Integration/Ingestion` (точечно), PHPStan
по изменяемым файлам.

## Stage 1: каталог и разбор без `type_id`
Risk: HIGH-LOCAL (разнесение по категориям)
stage_base_commit: <записать перед 1.1>
Definition of Done:
- `OzonAccrualCategory` без поля `typeIds` и `findByTypeId()`; seed и discover
  не создают ключей `type:*`; статический fallback резолвера не разбирает по id.
- Алиасы: `LastMileCourier` → `ozon_delivery_to_pickup_partners`,
  `PickUpPointReturnAcceptance` → `ozon_partner_return_processing`,
  `SellerReturns` → `ozon_warehouse_export`, `Placements` →
  `ozon_warehouse_placement`, `SupplyInbound` → `ozon_cargo_place_item_processing`;
  `ItemPacking`, `TemporaryPlacement` — две новые категории с label из
  `description` Ozon и группой «Услуги FBO». Неверные алиасы сняты с прежних категорий.
- Вариант B: типы перечисленных категорий не меняются — см. 2.2.
- Регрессионные тесты красные на старом коде: by-day строка `type_id=29,
  name=LastMileCourier` разбирается в `ozon_delivery_to_pickup_partners`; seed не
  создаёт `type:*`; сопоставление каждого исправленного алиаса.
- Инвариант-тест каталога: каждый алиас-код, который есть в фикстуре
  `accrual_types.json`, ведёт в категорию, согласованную с его `description`
  (таблица ожиданий в тесте, без эвристик).
Work items:
- 1.1 — убрать `typeIds`/`findByTypeId` из каталога и вызовов (Seed, Discover, TaxonomyResolver)
- 1.2 — исправить алиасы, добавить две категории
- 1.3 — тесты: регрессия, seed, discover, инвариант по фикстуре; обновить затронутые
- 1.4 — комментарий `OzonAccrualCategoryFacade`, `docs/ingestion/ozon-mapping.md`
  (type_id не используется; у словаря есть `description`), `services/ozon-fetcher/docs/ozon-api.md` — только описание формы ответа
Stage checks:
- `tests/Unit/Ingestion`, `tests/Integration/Ingestion`, PHPStan и cs по изменённым файлам
Reviewer focus:
- не осталось пути, где `type_id` из by-day выбирает категорию
- новые категории: code, label, group, type, sortOrder не конфликтуют

## Stage 2: миграция маппингов в БД
Risk: HIGH-LOCAL (миграция данных)
stage_base_commit: <записать>
Definition of Done:
- Doctrine-миграция по `ingest_external_category_mappings` через join на
  `ingest_external_categories (source, resource_type, normalized_key)`:
  - `status = 'disabled'` у всех маппингов ключей `type:29`, `type:45`, `type:76`, `type:77` (все scope);
  - перенацелить `code:`/`name:` ключи `sellerreturns`, `placements`,
    `itempacking`, `temporaryplacement` (все scope) на новые code/label/group/type/sort.
  - русские `name:`-ключи, которые описывают сами наши статьи («размещение
    товара партнерами» и т. п.), не трогать.
- `down()` восстанавливает прежние значения дословно (снимок 16 строк — `measurements/before-mappings.txt`).
- Только строки с `updated_by IS NULL` (ручных правок нет, 0 на 18.10); иначе миграция падает, а не перезаписывает.
- 2.2 (только вариант B): перенацеленные строки сохраняют прежний `transaction_type`.
- Прогон на локальной БД со сценарием: все ключи из таблицы + контрольные ключи, которые меняться не должны.
Work items:
- 2.1 — миграция up/down + guard на ручные правки
- 2.2 — (вариант B) сохранение типов
- 2.3 — функциональный тест: после миграции резолвер разбирает 29/45/71/46/84/78 в целевые категории, контрольные ключи не изменились
Stage checks:
- миграция up → down → up на локальной БД; `tests/Integration/Ingestion`
Reviewer focus:
- сгенерированный SQL, идемпотентность, совместимость старого кода с новыми данными во время деплоя

## Handoff
Полные гейты (`make site-stan`, `site-cs-check`, `site-cs-strict-types`,
`site-test-unit`, `site-test`), финальное ревью в свежей сессии, внешнее
ревью Codex (Large), `handoff.md`, PR Ready. В запросе сразу три действия:
merge+deploy, `production_action: migrations`, ремонт истории (§3.3).

## Stage 3: прод — миграция, ремонт истории, приёмка
Risk: HIGH-EXTERNAL — только после одобрения Владельца
Definition of Done:
- Замеры «до» (в день ремонта, `codex-psql-ro`), по магазину и дню за
  2026-05-17…сегодня: живые строки, `sum(amount_minor)`, разрез
  `(type_id, _ozon_category_code, type)`, max raw на день, погашенные строки;
  статус `verify-rolling-refresh`.
- Миграция (dispatch), деплой.
- Ремонт:
  - A: `normalize-stored --company-id --shop-ref --from --to --include-done`
    сначала `--dry-run`, затем `--execute-inline`, окна — полное владение
    снапшота (урок retype-boundary), по одному магазину, сверка инвариантов между окнами;
  - B: `daily-maintenance --from=2026-05-17 --to=<сегодня-46> --company-id --execute` по компаниям; последние 45 дней — ночной cron 05:15.
- Приёмка: число живых строк и сумма по магазину совпали с «до» (расхождение из-за правок Ozon задним числом объяснено);
  у 29/45/71/46/84/78 0 строк в прежних категориях; дублей 0; max raw на день = 1;
  A — `verify-rolling-refresh` зелёный; новых `type:*` маппингов нет.
- Итог дописан в `stages/stage-3.md`, память обновлена.
