# Financial Core — Stage 0: обзор

> Статус: Stage 0, архитектурный контракт. Production-поведение не менялось.
> Дата базового среза: 2026-10-04, `master` @ `524caaec`.
> Все утверждения о «сейчас» сверены с кодом; ссылки `файл:строка` даны там, где
> факт критичен. Всё, что помечено **Решение**, — проектное решение Stage 0 и
> обязательно для Stage 1–N; всё, что помечено **Вопрос Владельцу**, — бизнес-
> семантика, которую нельзя угадывать (AGENTS.md §3.4 п.1).

## Состав

| Файл | Содержание |
|---|---|
| `01-current-architecture.md` | модули, очереди, воркеры, cron, таблицы |
| `02-financial-flows.md` | сквозные потоки по сценариям ТЗ + что в коде отсутствует |
| `03-current-failure-model.md` | failure points, карта рисков R-xx |
| `04-target-architecture.md` | целевая схема, source of truth, инварианты, транзакции |
| `05-financial-events.md` | контракт Financial Event |
| `06-posting-model.md` | модель Posting, коррекции |
| `07-idempotency.md` | ключи, unique-констрейнты, идемпотентность консьюмеров |
| `08-queue-topology.md` | будущая топология очередей |
| `09-recovery-reconciliation.md` | retry → DLQ → correction → replay → reconciliation |
| `10-migration-roadmap.md` | Stage 1–N |
| `adr/001…004` | решения: события, outbox, оркестратор, разделение очередей |

## Главные выводы baseline (коротко)

1. **Единого финансового ядра нет.** Существуют три несвязанных контура:
   P&L (`documents` → `pl_daily_totals`, изменяемый регистр), Cash (ДДС:
   `cash_transaction`, остатки по счетам) и Balance (неизменяемый журнал
   `balance_operation_lines`, **ни один модуль в него не пишет**).
2. **Понятий из ТЗ нет в коде:** Invoice, Vendor Bill, AR, AP,
   Customer/Vendor Payment как привязанный к документу платёж, Prepayment,
   Invoice cancellation. Есть Deals (заказ без оплат), Loan (график без связи с
   Cash), PaymentPlan (прогноз ДДС), аллокация `CashTransaction` на P&L-документы
   (`allocatedAmount`). Подробно — `02-financial-flows.md` §0. Следствие: AR/AP
   — **новая возможность**, а не миграция; для неё нужны решения Владельца.
3. **Outbox, доменных событий и `doctrine_transaction`-middleware нет.** Публикация
   сообщений — прямой `dispatch()` после `flush()` (или из Doctrine-listener
   внутри `flush()`). Везде есть окно «БД закоммичена, сообщение не ушло».
4. **Транспорты бизнес-очередей — Redis** (`appendonly yes`, fsync everysec);
   в БД лежит только `failed`. Потеря Redis = потеря очереди, копии в БД нет.
5. **Восстановление частично есть** (Ingestion: sweeper `normalize-pending`,
   heartbeat/reaper; Ozon: grace 1 ч + cron; WB: claim 2 ч для `queued`), но
   **WB-день в `raw_loaded/processing` не переклеймится никогда** (R-01).
6. **Очередь `failed` пассивна:** нет алерта на глубину, нет автоповтора,
   только ручные команды по согласию Владельца.
7. **P&L-регистр пересобирается delete+insert по дню без блокировки**, коммит
   документа и пересчёт регистра — разные коммиты (R-05). Атомарен только
   `CloseMonthStageAction`.
8. Денежная арифметика P&L — `float`; Balance — целые minor units; признаки
   знака в P&L двойные (`marketplace_pl` со знаком vs legacy `abs`).

## Целевая модель одной строкой

```text
Business Operation ─┐ (одна БД-транзакция)
                    ├─ Business Data
                    └─ Outbox row  ──► Outbox Publisher ──► Financial Event
                                                              │
                                              Posting Orchestrator (идемпотентный)
                                                              │
                                                    Financial Postings (append-only)
                                          ┌──────────┬────────┴───┬──────────┐
                                        AR/AP       Cash         P&L       Balance
                                       (projection) (projection) (projection)(ledger intake)
                                                              │
                                                       Reconciliation
```

## Принципы реализации (для всех Stage)

- **Strangler, не rewrite.** Новое ядро растёт рядом (`src/FinancialCore`),
  работает в shadow-режиме, сверяется с legacy-регистрами, только потом
  становится источником. До cutover legacy-контуры считаются источником истины.
- **At-least-once + идемпотентный консьюмер = effectively-once.** Exactly-once
  не обещаем.
- **Posting неизменяем.** Исправление — сторно + новая проводка; `UPDATE`/`DELETE`
  фактов запрещены (по образцу Balance, `Version20260914120000`).
- **Деньги** — целые minor units + ISO-валюта; в JSON — строка (см. `05`).
- **Повторное использование** найденных в коде паттернов: `request_key` +
  `request_hash` (Balance), content-hash + natural-key upsert и `pending`-sweeper
  (Ingestion), `claimForQueue` с advisory-lock (Marketplace sync status).

## Открытые вопросы Владельцу (блокируют только указанные Stage)

| # | Вопрос | Блокирует |
|---|---|---|
| Q1 | Нужен ли в продукте AR/AP (дебиторка/кредиторка) и по каким первичным документам (счёт, акт, УПД, накладная), или достаточно маркетплейсных расчётов? | Stage 7 |
| Q2 | Единое правило признания: выручка/расход — по дате документа, отгрузки, периода отчёта маркетплейса или оплаты? Сейчас: маркетплейс — конец месяца, Cash — дата оплаты, Loan — дата платежа по графику | Stage 4–5 |
| Q3 | Должен ли Balance получать данные автоматически (какие счета/статьи соответствуют ДДС, AR/AP, остаткам маркетплейсов)? | Stage 8 |
| Q4 | Единая политика закрытия периода: достаточно `Company::financeLockBefore` или нужны статусы периодов (open/soft-closed/closed) в ядре? | Stage 5 |
| Q5 | Многовалютность: все проводки в RUB или хранить валюту операции + курс? Сейчас Cash хранит `currency` и FX у переводов, P&L — только суммы | Stage 2 (схема), Stage 6 |

До ответа Stage 1–6 реализуются без этих решений: ядро проектируется
валюто-нейтральным, правило признания — параметром posting-rule.

## Чек-лист готовности Stage 0

| Блок | Статус | Где |
|---|---|---|
| Entry points, сервисы, воркеры, очереди, cron, retry, DLQ, таблицы, связи модулей | да | `01` |
| Потоки: revenue/expense recognition, bank, refund, cancellation, balance; Invoice/AR/AP/Bill/Prepayment | описаны; **Invoice/AR/AP/Bill/Prepayment в коде отсутствуют**, зафиксировано | `02 §0` |
| Потеря сообщения, duplicate delivery/processing, рестарт воркера, отказ очереди, частичная транзакция, replay | да | `03`, `09` |
| Financial Event, Posting, source of truth, Outbox, Orchestrator, idempotency, queue topology, reconciliation, recovery | да | `04`–`09`, ADR |
| Инварианты, связи Revenue↔AR, Expense↔AP, Payment↔AR/AP/Cash, Postings↔Balance | да (AR/AP — условно, Q1) | `04 §4–5` |
| Roadmap Stage 1–10 со scope/зависимостями/BC/Legacy/rollback | да | `10` |
| Production-поведение, схема БД, API, очереди не менялись | да: изменены только файлы `docs/financial-core/**` | `git diff --stat` |

Границы проверки: не читались `WbFinancialReportReconciliationService`,
`WbGeneratedRowsSafeReplaceService`, `MarketplaceListingLinkingFacade`; не
проверены `redeliver_timeout` Redis-транспорта и `maxmemory`; прод-данные не
запрашивались (замеров «сколько WB-дней сейчас застряло» нет — это первый
замер Stage 1). Список вопросов Владельцу — выше (Q1–Q5).
