### Stage 7: Marketplace — операции — DONE

**Risk:** MEDIUM · **База:** `feat/ds-rollout-base` (#2625)

#### Страницы
`marketplace/{sales,returns,costs,products}`, `marketplace/listings/index`,
`marketplace/unmapped/index`, `marketplace/inventory/{index,history}`.

#### Что сделано
- «₽» перенесён из каждой ячейки в заголовки колонок; ячейки — `vf_amount`, итоги
  «На странице» — `vf_money` с «₽» (DS: в итогах символ всегда).
- Возвраты и затраты — расход: основной цвет с U+2212 (были красными с дефисом);
  `negate` даёт верный знак и для сторно (раньше печаталось «--5»).
- Цены листингов в списках — вправо; количество — `vf_number`.
- Себестоимость и история цен — валюта записи символом после числа.
- «Не задана» у себестоимости остаётся красной — сигнал недостающих данных.

#### Проверки
- `lint:twig templates/marketplace` ✅; `phpunit tests/Functional/Marketplace tests/Functional/Inventory` ✅ 236.
- smoke: продажи, возвраты, затраты, товары, листинги, остатки — 200 (локальных данных
  маркетплейсов нет, строки с суммами покрыты функциональными тестами).
