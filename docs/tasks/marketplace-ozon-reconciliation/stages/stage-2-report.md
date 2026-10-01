# Stage 2 Report: источники данных

Сделано: `OzonRealizationTotalsQuery`, `OzonRawAccrualTotalsQuery`, `OzonLedgerTotalsQuery`, DTO (`RealizationTotals`, `RawFlowTotals`, `LedgerFlowTotals`, `CostBucket`).
Проверки: integration `OzonReconciliationQueriesTest` 6 OK (суммы посчитаны вручную: продажи/возвраты в обеих базах, комиссия net 161.92 = 1379.54 − 1217.62,
категории, ITEM/NON_ITEM, неизвестная услуга → `ozon_unknown_99`, мусор в JSON, начисление вне периода, чужая компания, легаси-строки вне сверки, «Реализация» null без отчёта);
PHPStan новых каталогов — 0 ошибок; cs:check — 0.
Internal review: BLOCKER/IMPORTANT нет. Найдено и исправлено в ходе работы: `GROUP BY` с параметром в `COALESCE` (ошибка PG) → `GROUP BY 1`.
Замечания для Stage 3:
- сырьё считает все `sale_amount > 0`/`< 0`, не повторяя пропуски процессора (нулевая `seller_price`, нецелое количество, пустой sku, дедуп по external_id) —
  такие строки должны появляться расхождением, а не прятаться;
- пустой набор затрат в сырье и в учёте даёт отсутствующие ключи, Action обязан объединять коды обеих сторон.
FOLLOW-UP: проверка на прод-объёме (месяц ≈ 7000 начислений/день×30) после деплоя — время запроса jsonb.
