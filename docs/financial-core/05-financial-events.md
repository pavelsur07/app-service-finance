# 05. Financial Event — контракт

**Решение Stage 0.** Реализация — Stage 2. Таблица `fincore_events`, неизменяемая.

## 1. Поля

| Поле | Тип | Обяз. | Смысл |
|---|---|---|---|
| `event_id` | UUID v7 | да | генерируется при записи (`Uuid::uuid7()`, как Entity в проекте) |
| `event_type` | string | да | `<домен>.<агрегат>.<действие>` snake/dot, стабильный; напр. `cash.transaction.booked` |
| `event_version` | int ≥1 | да | версия **схемы payload**; консьюмер обязан поддерживать N и N−1 |
| `company_id` | UUID | да | организация (в терминах ТЗ `organization_id`; в проекте — `companyId`) |
| `source_type` | string | да | агрегат-источник: `cash_transaction`, `marketplace_month_stage`, `loan_schedule_item`, `finance_document`, будущие `invoice`, `bill`, `payment` |
| `source_id` | string(UUID/составной) | да | ID агрегата |
| `source_revision` | int ≥1 | да | номер ревизии факта; исправление = новая ревизия с `corrects_event_id` |
| `idempotency_key` | string ≤200 | да | `{source_type}:{source_id}:{event_type}:r{source_revision}`; `UNIQUE(company_id, idempotency_key)` |
| `occurred_at` | timestamptz | да | **когда произошёл бизнес-факт** в мире (оказание услуги, отгрузка, платёж) |
| `effective_date` | date | да | **в какой финансовый период относится факт** (календарь компании); задаёт продюсер, `PostingRule` использует её как есть; см. §4 |
| `recorded_at` | timestamptz | да | **когда событие записано системой** (серверное время транзакции); не участвует в определении периода |
| `correlation_id` | UUID | да | сквозной ID пользовательского действия/запуска cron |
| `causation_id` | UUID? | нет | `event_id` родителя |
| `corrects_event_id` | UUID? | нет | какое событие исправляет/сторнирует (correction/reversal reference); обязательно при `source_revision` > 1 и для adjustment |
| `currency` | CHAR(3) | да* | ISO 4217 валюта **операции** (не приводится к валюте компании); `*` для событий без суммы — `XXX` |
| `amount_minor` | BIGINT? | нет | заголовочная сумма в валюте операции, целые minor units (для выборки/сверки) |
| `payload` | jsonb | да | данные события (см. §3), ≤ 64 KB |
| `metadata` | jsonb | да | `{origin: ui|api|cron|replay, actor_user_id?, trace_id?, app_version}` |

## 2. Правила

1. **Любая денежная величина — пара `amount_minor` + `currency`** (ADR-008):
   целое число minor units по ISO 4217 (RUB: копейки, JPY: иены) и код валюты;
   в JSON — строка (`"amount_minor":"123456"`). `float` для денег запрещён.
   Конвертации из legacy `decimal(…,2)` — на границе продюсера, `bcmul`/строки.
   Каждая сумма в `payload` оформляется **денежным объектом**:

   ```json
   {"amount_minor":"105000","currency":"USD",
    "fx":{"reporting_amount_minor":"9900000","reporting_currency":"RUB",
          "fx_rate":"94.285714","fx_rate_date":"2026-10-02","fx_rate_source":"bank"}}
   ```

   Блок `fx` необязателен, но если задан — задан целиком. Событие
   **сохраняет исходную валюту**; необратимое приведение к RUB при записи
   запрещено. Курс поставляет продюсер, если он его знает (например,
   FX-перевод Cash).
2. **Событие — факт, а не команда:** прошедшее время (`booked`, `closed`,
   `issued`), без «сделай».
3. **Payload самодостаточен для постинга**: правилу не разрешено читать
   изменяемые данные источника (иначе replay даст другой результат). Нужные
   справочные значения (код P&L-категории, счёт ДДС, контрагент) снимаются в
   payload в момент записи.
4. **Запрещено в payload и metadata:** секреты, токены, ФИО, ИНН, тела ответов
   внешних API (CLAUDE.md «Логирование»). Ссылки на сырьё — по ID.
5. **Неизменяемость.** Исправление — новое событие (`source_revision+1`,
   `corrects_event_id`). `UPDATE/DELETE` запрещены триггером БД.
6. **Версионирование.** Несовместимое изменение payload = новый `event_version`;
   старые события не мигрируются, оркестратор держит upcaster `vN→vN+1`.
7. **Идентичность и повтор.** Повторная запись того же `idempotency_key` — не
   ошибка: `recordEvent` возвращает существующий `event_id` (проверка hash
   payload; расхождение ⇒ `EventPayloadConflictException`, 409/422 — по образцу
   `request_hash` в Balance).
8. **Один факт — одно событие.** Событие не дробится по получателям; получатели
   — consumers (`posting`, проекции, `balance_intake`).

## 3. Каталог событий (начальный)

| `event_type` | Продюсер | Когда | Stage |
|---|---|---|---|
| `marketplace.month_stage.closed` | Marketplace `CloseMonthStageAction` | закрытие стадии `SALES_RETURNS`/`COSTS` (в той же транзакции); payload: маркетплейс, период, строки по P&L-категориям `{category_code, amount_minor, flow}` | 4 |
| `marketplace.month_stage.reopened` | `ReopenMonthStageAction` | сторно предыдущего (`corrects_event_id`) | 4 |
| `cash.transaction.booked` | Cash (полный путь и импорты) | запись транзакции; payload: счёт, направление, сумма, контрагент, категория ДДС | 6 |
| `cash.transaction.reversed` | Cash | мягкое удаление/исправление | 6 |
| `cash.transfer.booked` | `CreateCashTransferAction` | перевод между счетами | 6 |
| `loan.payment.due` | Loan | строка графика → признание процентов/комиссии | 6 |
| `finance.document.created/voided` | Finance (ручные документы, «документ из транзакции Cash», Loan) | совместимость на время миграции; единственный триггер `legacy_cash_basis_v1` | 5 |
| `fincore.adjustment.posted` | FinancialCore (Action пользователя) | корректировка закрытого/мягко закрытого периода: payload обязан содержать `reason`, `actor_user_id`, `original_event_id` (ADR-007) | 3 |
| `sales.invoice.issued/cancelled`, `sales.invoice.credit_issued` | модуль Invoice (Stage 7) | Revenue + AR (ADR-005) | 7 |
| `purchase.bill.received/cancelled`, `purchase.bill.credit_received` | модуль Bill (Stage 7) | Expense + AP | 7 |
| `payment.received/made/allocated/refunded` | модуль Payment (Stage 7) | Cash + Settlement; **без** Recognition (ADR-006) | 7 |

## 4. effective_date, occurred_at, recorded_at

Решение Q2 (ADR-006): признание определяется экономическим фактом, а не
движением денег.

- `occurred_at` — когда произошёл бизнес-факт; `effective_date` — в какой
  финансовый период он относится; `recorded_at` — когда записан системой.
  Периодом управляет **только** `effective_date`.
- Бизнес-модуль определяет, **что произошло и в какой период это относится**
  (тип события и `effective_date`). Один факт с разными датами признания и
  оплаты — **два события** (например, `loan.payment.due` и
  `cash.transaction.booked`), а не одно событие с двумя датами.
- `PostingRule` использует `effective_date` события и **не переопределяет её**;
  правило не определяет бизнес-факт (`06 §6`).
- До миграции продюсеры сохраняют текущую семантику как versioned-правила, а
  не как универсальное правило: Marketplace — последний день отчётного периода
  (`marketplace_month_stage_v1`); Loan — `dueDate` (`loan_schedule_v1`);
  ручной документ из Cash-транзакции — дата оплаты (`legacy_cash_basis_v1`,
  только по явному `finance.document.created`).
- Смена политики = новая версия правила и коррекция по `06 §5`, а не правка
  старых событий.
- Период (`YYYY-MM`) выводится из `effective_date` в календаре компании;
  состояние периода (`OPEN/SOFT_CLOSED/CLOSED`) проверяет оркестратор
  (ADR-007). Для обычного события в закрытом периоде продюсер может записать
  новую ревизию с другой `effective_date` (если это бизнес-корректно) или
  оформить adjustment (§3).

## 5. Минимальный пример

```json
{
  "event_id": "0197…",
  "event_type": "cash.transaction.booked",
  "event_version": 1,
  "company_id": "…",
  "source_type": "cash_transaction",
  "source_id": "…",
  "source_revision": 1,
  "idempotency_key": "cash_transaction:…:cash.transaction.booked:r1",
  "occurred_at": "2026-10-02T09:15:00+03:00",
  "effective_date": "2026-10-02",
  "correlation_id": "…",
  "causation_id": null,
  "currency": "RUB",
  "amount_minor": "-1250000",
  "payload": {
    "money_account_id": "…",
    "direction": "OUTFLOW",
    "counterparty_id": "…",
    "cashflow_category_id": "…",
    "pl_category_code": "EXP_RENT"
  },
  "metadata": {"origin": "ui", "app_version": "…"}
}
```

Знак `amount_minor` в заголовке: «+» поступление/увеличение, «−» выбытие, —
договорённость события; проводки задают знак явно (`06`).
