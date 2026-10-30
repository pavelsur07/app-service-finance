# Новые типы начислений Ozon 136 и 137

## Задача

30.10.2026 02:30 ночной `sync-types` добавил в справочник Ozon два типа:

| type_id | Имя | Описание Ozon |
|---|---|---|
| 136 | SellerReturnPreparationByPartner | Подготовка товара к вывозу партнёрами |
| 137 | SellerReturnDelivery | Доставка вывоза |

Ни Ingestion (`OzonAccrualCategory`), ни Маркетплейс (`OzonCostCategory`) их
не знают. Строк с ними на проде ещё нет (проверено SQL 30.10), но первые
начисления ушли бы в `ozon_unknown_136/137` с `known=false` — в очередь
неизвестных типов и мимо ОПиУ.

Решение Владельца 30.10: 136 → «Хранение» (`OPEX_WH_STORAGE`),
137 → «Логистика возвратов» (`COGS_RETURNS_DELIVERY`).

## Результат

- Маркетплейс: категории `ozon_return_preparation_partner` и
  `ozon_return_export_delivery` с `accrualTypeNames`, правила в
  `default_cost_mapping.yaml`. Ночной `cost-pl-mapping:sync-default` создаст
  правила компаниям, у которых есть статьи шаблона.
- Ingestion: статьи «Подготовка товара к вывозу партнёрами»
  (`ozon_warehouse_export_preparation_partner`, STORAGE) и «Доставка вывоза»
  (`ozon_warehouse_export_delivery`, Услуги FBO, LOGISTICS).

## Что считаем выполненным

PR с кодом и тестами, гейты зелёные, после деплоя первые строки 136/137
получают известную категорию и статью ОПиУ без ручных действий.

## Чек-лист приёмки

- [x] `OzonAccrualServiceCategoryResolver` разбирает 136/137 в новые коды, `known=true`
- [x] by-day маппер Ingestion даёт `_ozon_category_known=true` для 136/137
- [x] правила шаблона: 136 → `OPEX_WH_STORAGE`, 137 → `COGS_RETURNS_DELIVERY`
- [x] backward-compat тесты групп виджета дополнены
- [x] `make site-stan`, `site-cs-check`, unit + затронутые integration — зелёные
- [ ] после деплоя: первые строки 136/137 сверены SQL (категория, правило ОПиУ)

## Итог (30.10.2026)

Unit 4207 — OK; integration Ingestion + Marketplace 1030 — OK; Onboarding +
Functional/Marketplace 255 — OK; php-cs-fixer (`.php-cs-fixer.php`, без кеша)
— 0 файлов; PHPStan по изменённым файлам — без ошибок. Фикстура справочника
дополнена типами 136/137, счётчики в тестах фикстуры 126 → 128. Сверка на
проде — после деплоя, по первым строкам.
