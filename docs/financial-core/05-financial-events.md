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
| `occurred_at` | timestamptz | да | когда факт случился в мире (дата платежа, отгрузки…) |
| `effective_date` | date | да | дата, по которой факт попадает в учётный период (МСК-календарь компании); см. §4 |
| `recorded_at` | timestamptz | да | когда записан (серверное время транзакции) |
| `correlation_id` | UUID | да | сквозной ID пользовательского действия/запуска cron |
| `causation_id` | UUID? | нет | `event_id` родителя |
| `corrects_event_id` | UUID? | нет | какое событие исправляет |
| `currency` | CHAR(3) | да* | ISO 4217; `*` для событий без суммы — `XXX` |
| `amount_minor` | BIGINT? | нет | заголовочная сумма, целые minor units (для ускоренной выборки/сверки) |
| `payload` | jsonb | да | данные события (см. §3), ≤ 64 KB |
| `metadata` | jsonb | да | `{origin: ui|api|cron|replay, actor_user_id?, trace_id?, app_version}` |

## 2. Правила

1. **Сумма — целое число minor units** (RUB: копейки) плюс валюта; в JSON —
   строка (`"amount_minor":"123456"`), чтобы не терять точность. Конвертации из
   `decimal(…,2)` — `bcmul`/строки, не `float`.
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
| `finance.document.created/voided` | Finance (ручные документы) | совместимость на время миграции | 5 |
| `sales.invoice.issued/cancelled` | будущий Invoice | AR (Q1) | 7 |
| `purchase.bill.received/cancelled` | будущий Bill | AP (Q1) | 7 |
| `payment.received/made/allocated/refunded` | будущий Payment | Q1 | 7 |

## 4. effective_date и occurred_at

- `occurred_at` — момент в мире; `effective_date` — учётная дата, выбираемая
  **правилом продюсера** (политика признания, Q2). До ответа Q2 продюсеры
  сохраняют текущую семантику: Marketplace — последний день периода; Cash —
  дата оплаты; Loan — `dueDate`.
- Правило — чистая функция; смена политики = новая версия правила и коррекция
  по `06`, а не правка старых событий.
- Период определяется как `effective_date` в календаре компании; граница
  `financeLockBefore` проверяется оркестратором (Q4).

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
