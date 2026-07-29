# Handoff: товары и себестоимость в финансовом отчёте WB

## Результат

В финансовом отчёте WB добавлена товарная аналитика по каждому варианту SKU,
построенная только по завершённым raw-документам и истории себестоимости.
Таблицы продаж, возвратов и финансовых транзакций не читаются и не изменяются.

## Как открыть

1. Открыть `/marketplace/wb-finance-report`.
2. Выбрать период до 93 дней и при необходимости `reportId`.
3. Блок «Товары и себестоимость» находится после «Расшифровка удержаний и
   выплат» и перед «Сводка по reportId».
4. Кнопка «Скачать CSV» экспортирует тот же период, фильтр и SKU-раздел.

## Что показано

- общие количества проданных, возвращённых и нетто-товаров;
- продажи без СПП и с СПП;
- товарное `К перечислению`;
- себестоимость продаж, возвратов и нетто-себестоимость;
- mapping, missing, partial, fallback и conflict quality;
- результат и рентабельность по каждому SKU;
- нераспределённые корректировки `forPay` и sales reconciliation.

## Формулы

```text
net_quantity = sold_quantity − returned_quantity
net_sales_without_spp = sales_without_spp − returns_without_spp
net_cost = sold_cost − returned_cost
sku_result = sku_for_pay − net_cost
profitability = sku_result / net_sales_without_spp
```

Результат — до общих расходов WB и не является чистой прибылью. При missing
cost результат не показывается как достоверный. Return rate относится к
выбранному периоду.

## Проверки

- targeted integration + functional: `OK (10 tests, 173 assertions)`;
- Marketplace: `OK (788 tests, 5509 assertions)`;
- unit: `OK (1645 tests, 9560 assertions)`;
- Twig/container lint и targeted PHP CS Fixer: green;
- Stage 1, Stage 2 и Stage 3 external reviews: `REVIEW_GREEN`;
- final full-task external review: `REVIEW_GREEN`.

Глобальный `make site-cs-check` остаётся красным на 582 из 2156 ранее
существовавших файлов; task-owned PHP-файлы зелёные.

## Доставка

- Branch: `agent/wb-finance-sku-cost-result`
- Draft PR: #2257
- Base: `master` at `cbc5775c7474e266486c07ea49bcedc09f97bd09`
- Миграций и production actions нет.
- Посторонние untracked-файлы не входят в задачу и не должны быть staged.

## Follow-up

- для очень больших периодов отдельно согласовать пагинацию или top-N SKU;
- предупредить позиционных потребителей CSV о новом обязательном разделе;
- при экстремальном числе идентификаторов разбивать SQL `IN`-списки на части;
- отдельно сигнализировать расхождение размера при barcode fallback.

## Release Gate

После push требуется явное решение владельца: перевести Draft PR #2257 в
Ready и мержить в `master` либо оставить Draft для дополнительной проверки.
