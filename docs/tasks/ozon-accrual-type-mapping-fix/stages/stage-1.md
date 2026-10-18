# Stage 1: каталог и разбор без `type_id`

Решение Владельца 18.10.2026: **вариант A** — исправляются статья, группа и
`TransactionType`. Работаем только в модуле Ingestion.

## 1. Какую задачу решаем

Начисления Ozon by-day разбираются в чужие статьи, потому что каталог
`OzonAccrualCategory` выбирает статью по `type_id` через карту `typeIds`,
расходящуюся со справочником Ozon, а четыре алиаса противоречат русскому
`description` словаря. На проде это 162 165 транзакций у 4 компаний.

## 2. Что будет результатом

- `OzonAccrualCategory` без `typeIds` и `findByTypeId()`; ни один путь
  Ingestion (резолвер, seed, discover, превью) не выбирает статью по `type_id`.
- Алиасы по `description` Ozon:
  - `LastMileCourier` → `ozon_delivery_to_pickup_partners` (last_mile);
  - `PickUpPointReturnAcceptance` → `ozon_partner_return_processing` (fee);
  - `SellerReturns` → `ozon_warehouse_export` (storage);
  - `Placements` → `ozon_warehouse_placement` (storage);
  - `SupplyInbound` → `ozon_cargo_place_item_processing` (как сейчас, но по имени);
  - новые `ozon_warehouse_additional_packaging` «Дополнительная упаковка на
    складе Ozon» (Услуги FBO, fee) ← `ItemPacking`;
  - `ozon_fbs_return_short_placement` «Краткосрочное размещение возврата FBS»
    (Услуги FBO, storage) ← `TemporaryPlacement`.
- Неверные алиасы сняты с прежних категорий.
- Регрессионные тесты и инвариант по фикстуре словаря.
- Документация Ingestion и описание формы ответа словаря в `ozon-api.md`.

## 3. Что считаем выполненной работой

- Код в `site/src/Ingestion/` и тесты Ingestion; вне модуля — только docs.
- Регрессионный тест by-day `type_id=29, LastMileCourier` красный на
  `stage_base_commit` и зелёный после.
- `tests/Unit/Ingestion` и `tests/Integration/Ingestion` зелёные, PHPStan и CS
  по изменённым файлам чистые.
- Внутреннее ревью диффа Stage без BLOCKER/IMPORTANT.

## 4. Чек-лист приёмки

- [x] `grep -rn "typeIds\|findByTypeId" site/src/Ingestion` — пусто
- [x] для каждого из 29/45/71/46/84/78/77 тест показывает целевую статью
- [x] seed не создаёт `ExternalCategory` с ключом `type:*`
- [x] discover не автомаппит по `type_id`
- [x] инвариант: алиас-коды из фикстуры `accrual_types.json` ведут в ожидаемые статьи
- [x] новые code уникальны, `sortOrder` не конфликтует
- [x] прочие статьи каталога не изменились (дифф каталога только по таблице выше)
- [x] документация обновлена

## Итог

**DONE 2026-10-18.** stage_base_commit `fada74482`.

Сделано:
- `OzonAccrualCategory`: удалены `typeIds`, `findByTypeId()`, `byTypeId()`;
  `unknown()` больше не несёт `typeIds` (код `ozon_unknown_<id>` прежний).
- Seed не создаёт ключи `type:*`; discover и статический fallback резолвера не
  выбирают статью по `type_id`.
- Алиасы и две новые статьи — по таблице раздела 2.
- Комментарий `OzonAccrualCategoryFacade`, `docs/ingestion/ozon-mapping.md`,
  `ARCHITECTURE.md` (раздел фасада, Changelog 2.21).

Находка, изменившая Stage 3: тип транзакции берётся из статьи только у
`non_item_fee`; у затронутых строк прода тип не меняется (см. plan.md).

Проверки:
- регрессия `OzonAccrualByDayMapperTaxonomyTest` на `stage_base_commit`: 8
  падений (29→`ozon_logistics`, 45→`ozon_delivery_to_pickup_ozon`, 71, 46, 84,
  78 — прежние статьи партнёров; seed создал 4 ключа `type:*`; 29 без имени →
  известная статья); после — 17/17 зелёные; 76, 77, 32 — контроль, зелёные и до;
- `DiscoverExternalCategoriesActionTest::testTypeIdOnlyCategoryIsNotAutoMapped`:
  красный на старом коде (`autoMapped` 1), зелёный после;
- `tests/Unit/Ingestion` — OK (480; baseline 478);
  `tests/Integration/Ingestion` — OK (414; baseline 402);
- PHPStan по 12 изменённым файлам — No errors; php-cs-fixer (`.php-cs-fixer.php`,
  без кеша) — 0 из 12.

Внутреннее ревью: BLOCKER 0, IMPORTANT 0. Учтено: маппинги в БД
(`code:sellerreturns`, `code:placements`, `code:itempacking`,
`code:temporaryplacement`, `type:*`) сильнее каталога — их чинит миграция Stage 2,
которая по регламенту применяется до деплоя кода.
Вне модуля не менялось ничего, кроме `docs/` и `ARCHITECTURE.md`.
