# Unit Extended (`/api/marketplace-analytics/unit-extended`) — контракт полей

## Выручка и возвраты

Для Wildberries отчёт строит юнит-экономику от сумм до скидки постоянного
покупателя (СПП):

- `revenue = SUM(price_per_unit × quantity)` — продажи без СПП;
- `returnsTotal = SUM(refund_amount × quantity)` — возвраты без СПП;
- база дальнейшего расчёта прибыли — `revenue − returnsTotal`.

Для Ozon и других маркетплейсов сохраняется прежний контракт:

- `revenue = SUM(total_revenue)`;
- `returnsTotal = SUM(refund_amount)`.

Те же суммы используют виджет
`/api/marketplace-analytics/unit-extended/widgets` (`WidgetSummaryQuery`) и
XLSX-экспорт `/api/marketplace-analytics/unit-extended/export`.

Это WB-специфичная семантика страницы Unit Extended. Отчёт эффективности
рекламы `/marketplace-ads/efficiency` по-прежнему использует
`marketplace_sales.total_revenue`, поэтому его WB-выручка и ДРР могут
отличаться. Рекламные расходы за одинаковый период при этом остаются
согласованными между отчётами.

## Поля остатков

Добавлены поля строки отчёта:

- `stockQty` — «Ост. шт.» (остаток в штуках).
- `stockCapitalRub` — «Кап. р.» (капитал в рублях).

## Источник данных

`stockQty` получается из модуля `Inventory` через `InventoryFacade`.

Правило выбора snapshot:

1. Если есть snapshot ровно на дату отчёта — используем его.
2. Если нет — используем последний snapshot с датой `<=` даты отчёта.
3. Если snapshot не найден — для строки отчёта используется безопасное значение `0`.

## Формула

- `stockCapitalRub = stockQty * costPriceUnit`
- `costPriceUnit` соответствует полю «Себест. ед.»
- Денежное округление — до 2 знаков после запятой (как у остальных рублёвых полей отчёта).


## Totals

- Колонки `stockQty` и `stockCapitalRub` в строке итогов (`totals`) **не агрегируются**.
- В UI и XLSX для них всегда отображается `—` (плейсхолдер), по аналогии с `costPriceUnit`.

## Свод по тегам

При запросе `withTagSummary=1` поле `tagSummary[].stockCapitalRub` содержит сумму
`stockCapitalRub` всех листингов соответствующего тега. Листинг с несколькими тегами
учитывается в каждом из них; листинги без тегов агрегируются в строке «Без тегов».
