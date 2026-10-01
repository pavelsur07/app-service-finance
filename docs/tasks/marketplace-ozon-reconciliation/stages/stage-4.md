# Stage 4: backend страницы и drill-down

stage_base_commit: 5a1fe96a
Risk: MEDIUM

## Планирование
- `ReconciliationMonth` (VO): разбор `YYYY-MM`, границы, подпись. Невалидное на странице → текущий месяц по Москве; в drill-down → 422.
- `OzonReconciliationViewFactory`: Run + строки → `OzonReconciliationView` (проверка → блоки в порядке enum → категории; строки с расхождением выше; суммы строками decimal, разница знаковая).
- `OzonLedgerOperationsQuery`: `QueryBuilder` для Pagerfanta — записи учёта (продажи/возвраты/затраты) из by-day документов за месяц, по блоку/категории;
  блок UNRECOGNIZED = коды вне `recognizedCodes()`; всё с `companyId`.
- Контроллеры (по одному action, `Ozon/Controller/`):
  - `GET /marketplace/ozon-reconciliation?month=` — сводка (READ);
  - `POST /marketplace/ozon-reconciliation/run` — запуск Action синхронно, CSRF, WRITE, flash + redirect;
  - `GET /marketplace/ozon-reconciliation/operations?month&kind&block&category&page&limit` — drill-down (READ), limit ≤ 200 иначе 422.
- Шаблоны этого Stage — рабочая разметка без вкладки навигации; оформление и вкладка — Stage 5.
Тесты: unit фабрики вида; functional: гость → редирект на логин, сводка своей компании, чужой снимок не виден (IDOR), POST без CSRF → 403, POST с CSRF создаёт снимок,
drill-down: пагинация, limit>200 → 422, невалидные параметры, чужие записи не попадают.
Вне охвата: вкладка в layout, стилизация, cron.

## Work items
- 4.1 ReconciliationMonth + ViewFactory + unit
- 4.2 OperationsQuery + integration
- 4.3 контроллеры + шаблоны + functional
