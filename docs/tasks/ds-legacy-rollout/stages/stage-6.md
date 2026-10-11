### Stage 6: Catalog — DONE

**Risk:** MEDIUM · **База:** `feat/ds-rollout-base` (#2625)

#### Страницы
`catalog/product/show`, `catalog/product/purchase_price_history` (не была в реестре:
сумма выводилась сырой, без `number_format`, — сканер её не видел).

#### Что сделано
- Закупочные цены (сегодня, на дату, список, история) — `vf_amount`, валюта символом
  после числа приглушённо (DS-Money), «₽» для рублей.
- История цен: колонка цены — вправо.

#### Тесты
- Ожидания под формат DS: `ProductShowPurchasePriceBlockTest`,
  `ProductPurchasePriceCreateTest`, `ProductPurchasePriceHistoryPageTest`.
- `phpunit tests/Functional/Catalog` ✅ 13; `lint:twig` ✅; cs-fixer ✅.
- Локальных товаров нет — рендер карточки и истории покрыт функциональными тестами.
