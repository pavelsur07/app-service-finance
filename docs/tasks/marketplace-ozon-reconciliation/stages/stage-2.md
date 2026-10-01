# Stage 2: источники данных (только чтение)

stage_base_commit: 56edc595
Risk: HIGH-LOCAL (финансовая семантика)

## Планирование
Три Query (DBAL, `src/Marketplace/Ozon/Infrastructure/Query/Reconciliation/`), каждый с `companyId`, явными колонками, без float:
- `OzonRealizationTotalsQuery`: `marketplace_ozon_realizations` (period_from ≥ from, period_to ≤ to) → продажи `SUM(total_amount)`, возвраты `SUM(return_amount)`, количества; нет строк → null (нет данных).
- `OzonRawAccrualTotalsQuery`: документы by-day периода (`api_endpoint`, `period_from` в периоде) разворачиваются в SQL через jsonb
  (`accruals[]`, `posting.products[]`, `delivery.services[]`, `item_fees.fees[].fees[]`, `non_item_fee`); числа проверяются регуляркой, не-массивы пропускаются.
  Продажа: `sale_amount > 0`, возврат: `sale_amount < 0` (как процессоры); суммы в обеих базах (sale_amount и sale_price).
  Затраты — по (type_id, знак) в SQL, категория в PHP через `OzonAccrualServiceCategoryResolver`; комиссия = `ozon_sale_commission`.
  Нетто-расход = −Σ(сырая сумма Ozon) — та же конвенция, что у учёта (charge − storno).
  Покрытие: список дней, по которым есть документ.
- `OzonLedgerTotalsQuery`: `marketplace_sales`/`returns`/`costs` только с `raw_document_id` на by-day документ; затраты по коду категории; отдельно сумма затрат вне by-day.
DTO: `FlowTotals`, `CostTotals` с `Money`.
Тесты (integration, реальная БД): нормальный период; нет реализации (null); день без документа; сторно и знаки; легаси-затраты вне сверки;
чужая компания; не-массив в сырье не роняет запрос; неизвестная услуга → `ozon_unknown_*`.
Вне охвата: Action, UI, правка процессоров.

## Work items
- 2.1 DTO + Query реализации
- 2.2 Query сырья
- 2.3 Query учёта
- 2.4 integration-тесты
