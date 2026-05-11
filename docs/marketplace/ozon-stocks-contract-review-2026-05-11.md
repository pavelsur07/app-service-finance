# Ozon Seller API: проверка контракта `/v4/product/info/stocks` (2026-05-11)

## Источники

1. Ozon Seller API notification (официальный канал изменений):
   - 24 сентября 2025: для `/v4/product/info/stocks` «обновили примеры ответов», сам endpoint остаётся активным.
   - 2 октября 2025: добавлен отдельный beta endpoint `/v1/product/info/warehouse/stocks` (не замена `/v4/product/info/stocks`, а отдельный метод для складских остатков FBS/rFBS).
2. Ozon Help (Seller):
   - Разделы про остатки/доступность подтверждают, что **reserved** — это зарезервированное количество товара на складе.
   - Доступность в UI описана как отдельные bucket'ы Available и Reserved.
3. Текущий код модуля Inventory:
   - Используется endpoint `/v4/product/info/stocks`.
   - Пагинация строится по `result.last_id` и request-полю `last_id`.

## Проверка семантики полей

### `stocks[].present`
- По пользовательской и API-семантике Ozon это количество товара «в наличии» в конкретном `stocks[].type` bucket.
- Для этапа нормализации трактуем как `quantity` (внутри статуса `StockStatus::Available`).

### `stocks[].reserved`
- Ozon трактует reserved как зарезервированное количество товара на складе.
- Это **не отдельный lifecycle-статус строки остатка**, а отдельная количественная компонента внутри текущего остатка.
- Следовательно, в нормализованной модели корректно хранить это как `reservedQuantity`, а не как `StockStatus::Reserved`.

### `stocks[].sku`
- Идентификатор SKU на стороне Ozon для конкретного складского bucket'а (fbo/fbs и т.д.).
- Для первого этапа гипотеза `sourceSku = stocks[].sku` корректна.

### `stocks[].type`
- Тип fulfillment bucket (например, `fbo`, `fbs`).
- Для первого этапа гипотеза `fulfillmentType = stocks[].type` корректна.

### `item.offer_id`
- `offer_id` — внешний merchant SKU/артикул продавца в Ozon-каталоге.
- В `/v4/product/info/stocks` поле `offer_id` присутствует на уровне item (вместе с `product_id` и `stocks`).
- Для первой версии нормализации оно полезно как дополнительный идентификатор трассировки/дебага, но бизнес-гипотеза задачи (sourceSku от `stocks[].sku`) с ним не конфликтует.

## Endpoint и пагинация

- Актуальный endpoint для текущей интеграции: **`POST /v4/product/info/stocks`**.
- Пагинация: параметр запроса **`last_id`** и курсор ответа **`result.last_id`**.
- Признаков миграции этого метода на cursor-схему (`cursor`, `next_cursor`) в официальных changelog-публикациях не обнаружено.

## Итог по бизнес-гипотезе

Гипотеза для этапа 1 подтверждается:

- `source = MarketplaceType::OZON`
- `sourceSku = stocks[].sku`
- `fulfillmentType = stocks[].type`
- `status = StockStatus::Available`
- `quantity = stocks[].present`
- `reservedQuantity = stocks[].reserved`
- `availableForSale = quantity - reservedQuantity` — **вычисляемое значение** (Query/UI), не хранить отдельным полем.

Отдельно подтверждено:
- **Не добавлять** `StockStatus::Reserved`.
- **Не использовать** строковые статусы вместо enum-модели.

## Рекомендация для phpdoc/comment (без production-изменений)

Когда начнётся реализация нормализатора/DTO, добавить короткий контрактный комментарий рядом с mapping-логикой:

- `present` и `reserved` берутся из одного available bucket (`status=Available`).
- `reserved` хранится в `reservedQuantity`, не как отдельный `StockStatus`.
- `availableForSale` всегда считается динамически (`quantity - reservedQuantity`) в query/read-model/UI.
