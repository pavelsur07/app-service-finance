# Financial Core — Stage 0: обзор

> Статус: Stage 0, архитектурный контракт, Q1–Q5 утверждены 2026-10-04.
> Production-поведение не менялось.
> Дата базового среза: 2026-10-04, `master` @ `524caaec`.
> Все утверждения о «сейчас» сверены с кодом; ссылки `файл:строка` даны там, где
> факт критичен. Всё, что помечено **Решение**, — проектное решение Stage 0 и
> обязательно для Stage 1–N. Бизнес-вопросы Владельцу Q1–Q5 закрыты
> (раздел «Owner decisions» ниже, ADR-005…008).

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
| `adr/001…004` | решения: события, outbox, оркестратор (+Balance adapter), разделение очередей |
| `adr/005…008` | решения Владельца Q1–Q5: AR/AP, признание отдельно от денег, состояния периода, денежная модель |

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
   — **новая возможность**, а не миграция; решение о ней принято (Q1, ADR-005), раздел «Owner decisions».
3. **Outbox, доменных событий и `doctrine_transaction`-middleware нет.** Публикация
   сообщений — прямой `dispatch()` после `flush()` (или из Doctrine-listener
   внутри `flush()`). Везде есть окно «БД закоммичена, сообщение не ушло».
4. **Транспорты бизнес-очередей — Redis** (`appendonly yes`, fsync everysec);
   в БД лежит только `failed`. Потеря Redis = потеря очереди, копии в БД нет.
5. **Восстановление частично есть** (Ingestion: sweeper `normalize-pending`,
   heartbeat/reaper; Ozon: grace 1 ч + cron; WB: claim 2 ч для `queued`), но
   **WB-день в `raw_loaded/processing` не переклеймится никогда** (R-01; закрыт Stage 1.1:
   перехват после 6 ч, `reclaimStaleProcessing()`).
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

## Owner decisions (Q1–Q5) — утверждённый контракт Stage 0

Вопросы Q1–Q5 закрыты Владельцем 2026-10-04. Решения — часть архитектурного
контракта и **не пересматриваются внутри Stage 1–10**; изменение — только новым
ADR. Подробности и альтернативы — в указанных ADR.

| # | Решение | Архитектурное следствие | ADR | Зависящие Stage |
|---|---|---|---|---|
| Q1 | **AR/AP входит в целевую функциональность.** Invoice, Bill, Payment, allocation, частичная оплата, предоплата/аванс, возврат, отмена/сторно, credit-adjustment. Это отдельный settlement-контур, не производная Cash. Нормализованная семантика, без привязки к российским первичным документам (акт/УПД/накладная — источники событий) | книга `SETTLEMENT` + проекция AR/AP; оплата не признаёт доход/расход повторно; бизнес-модули владеют документами и платежами, ядро — проводками; инварианты INV-10…12 обязательны | ADR-005 | 6 (CASH-основа), **7** |
| Q2 | **Признание — по экономическому факту, не по деньгам.** Дата оплаты влияет на Cash и settlement, но не определяет recognition. Marketplace сохраняет текущую семантику как versioned-правило `marketplace_month_stage_v1`, не универсальное | `occurred_at` / `effective_date` / `recorded_at` в событии; `PostingRule` использует `effective_date`, не определяет бизнес-факт и не переопределяет дату; payment-события порождают только CASH/SETTLEMENT; legacy-семантики оформлены правилами `marketplace_month_stage_v1`, `loan_schedule_v1`, `legacy_cash_basis_v1` | ADR-006 | 4, 5, 6, 7 |
| Q3 | **Balance получает данные из ядра автоматически**, но ядро не пишет в его таблицы: только `Balance Intake Adapter` | `Posting Group → Balance operation`, `request_key = fincore:{posting_group_id}`; маппинг `posting/account/category → Balance account/article/dimension` — отдельная версионируемая конфигурация, не в Orchestrator; первый режим — `draft/suggestion`, затем автоматический `post`; Balance остаётся отдельным ledger | ADR-003 (п.6), ADR-008 (п.7) | **8** |
| Q4 | **`financeLockBefore` — только переходная совместимость.** Целевая модель — состояния периода `OPEN / SOFT_CLOSED / CLOSED` | Orchestrator проверяет период перед проводками; обычные события в закрытый период → `blocked`, корректировка — только adjustment-flow (причина, пользователь, исходное событие, аудит); replay соблюдает ту же политику; эффективное состояние = более строгое из ядра и `financeLockBefore` | ADR-007 | 2 (схема), **3** (проверка), 5 (legacy-писатели), 10 |
| Q5 | **Ядро не RUB-only.** Каждая сумма — `amount_minor` + `currency` (ISO 4217); `float` запрещён; исходная валюта сохраняется всегда; FX-поля (`reporting_amount_minor`, `reporting_currency`, `fx_rate`, `fx_rate_date`, `fx_rate_source`) предусмотрены в схеме, полный FX engine не требуется | value object `Money`; CHECK «reporting/fx — все или ничего»; reporting currency — настройка компании; отсутствие курса — finding `fx_missing`, не подстановка 1.0; курсовые разницы вне Stage 0–8 | ADR-008 | **2** (схема), 5, 6, 8 |

Следствие для планирования: **открытых вопросов Владельцу в Stage 0 нет.**
Stage 7 зависит от завершения Stage 5 и 6, Stage 8 — от 5 и 6; решений
Владельца ждать не нужно. Конкретное содержимое маппинга Balance (какие
статьи/счета) — это конфигурационные данные Stage 8 (Phase 0), а не
архитектурное решение.

## Чек-лист готовности Stage 0

| Блок | Статус | Где |
|---|---|---|
| Entry points, сервисы, воркеры, очереди, cron, retry, DLQ, таблицы, связи модулей | да | `01` |
| Потоки: revenue/expense recognition, bank, refund, cancellation, balance; Invoice/AR/AP/Bill/Prepayment | описаны; **Invoice/AR/AP/Bill/Prepayment в коде отсутствуют**, зафиксировано | `02 §0` |
| Потеря сообщения, duplicate delivery/processing, рестарт воркера, отказ очереди, частичная транзакция, replay | да | `03`, `09` |
| Financial Event, Posting, source of truth, Outbox, Orchestrator, idempotency, queue topology, reconciliation, recovery | да | `04`–`09`, ADR |
| Инварианты, связи Revenue↔AR, Expense↔AP, Payment↔AR/AP/Cash, Postings↔Balance | да | `04 §4–5`, ADR-005 |
| Roadmap Stage 1–10 со scope/зависимостями/BC/Legacy/rollback | да | `10` |
| Production-поведение, схема БД, API, очереди не менялись | да: изменены только файлы `docs/financial-core/**` | `git diff --stat` |

Границы проверки: не читались `WbFinancialReportReconciliationService`,
`WbGeneratedRowsSafeReplaceService`, `MarketplaceListingLinkingFacade`; не
проверены `redeliver_timeout` Redis-транспорта и `maxmemory`; прод-данные не
запрашивались (замеров «сколько WB-дней сейчас застряло» нет — это первый
замер Stage 1). Решения Владельца Q1–Q5 приняты и перечислены выше.
