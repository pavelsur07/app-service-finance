# Stage 3: Action сверки и ручной запуск

stage_base_commit: 189e0a6b
Risk: MEDIUM

## Планирование
- `OzonRawCoverage::expectedDays(from, to, now)`: дни периода от `OzonAccrualSyncPlanner::EARLIEST_SAFE_DAY` до вчера по Москве — сколько by-day документов обязано быть.
- Чистый `OzonReconciliationCalculator` (без БД) превращает `OzonReconciliationInputs` в `ReconciledLine[]` + общий статус:
  - REALIZATION_VS_RAW: SALES, RETURNS (источник — Реализация, цель — сырьё в базе sale_price);
  - RAW_VS_LEDGER: SALES, RETURNS (база sale_amount, с количествами), затраты по категориям (объединение кодов обеих сторон) + итог блока (пустой код);
  - нет «Реализации» → источник null → NO_DATA с пояснением; нет ни одного документа by-day → сырьё null → NO_DATA;
  - частичная загрузка дней — пояснение в строках, общий статус не выше NO_DATA (кроме MISMATCH);
  - блок UNRECOGNIZED с суммой — пояснение «нужен маппинг».
  - общий статус = худший из итоговых строк блоков с данными; mismatchCount = итоговые строки MISMATCH.
- `RunOzonReconciliationAction`: период в пределах одного месяца; собирает входы тремя Query, считает, в одной транзакции: удаляет строки прежнего снимка, пересоздаёт, обновляет Run (upsert по компании+периоду); `flush()` только здесь; лог старта/финиша с companyId.
- Команда `app:marketplace:ozon-reconciliation:run --company-id --month=YYYY-MM` (по умолчанию текущий месяц по Москве).
Тесты: unit калькулятора (все ветки) и покрытия; integration Action (happy path, расхождение, нет данных, идемпотентность — повтор не плодит строки и Run, чужая компания).
Вне охвата: страница, cron.

## Work items
- 3.1 покрытие + DTO + калькулятор + unit-тесты
- 3.2 Action + integration-тесты
- 3.3 команда
