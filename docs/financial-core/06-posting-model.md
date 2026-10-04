# 06. Posting model

**Решение Stage 0** (см. ADR-003). Таблицы `fincore_postings`,
`fincore_posting_groups`.

## 1. Что такое Posting

Posting — неизменяемая строка финансового воздействия события на одну
**книгу (ledger)**: «что изменилось, на сколько, в каком периоде, по какому
правилу». Проводки не содержат бизнес-логики и не читают источник — только то,
что вычислило правило из payload события.

Книги (`ledger`):

| Ledger | Что учитывает | Потребитель |
|---|---|---|
| `RECOGNITION` | признание доходов/расходов (категория P&L) | проекция P&L |
| `CASH` | движение денег по счёту ДДС | проекция Cash, остатки |
| `SETTLEMENT` | расчёты: AR/AP, авансы, аллокации, кредиты (ADR-005) | проекция AR/AP |

Набор книг расширяем (`ledger` — зарегистрированный enum, не жёсткая схема).
Balance — **не** книга ядра: его журнал наполняется Balance Intake Adapter'ом
из групп проводок (ADR-003 п.6).

**Почему не полная двойная запись.** Решения Q1 и Q3 принятые, но они не требуют
единого плана счетов в ядре: AR/AP выражается отдельной книгой `SETTLEMENT`, а
двусторонний контракт Balance закрывает adapter с маппингом (`04 §5`). Поэтому
строки односторонние со знаком, а баланс-условия задаёт **правило** на группу
(`balanced_within`) — **обязательные** для правил, затрагивающих `SETTLEMENT`:

| Событие | Кросс-книжное условие группы |
|---|---|
| `sales.invoice.issued` | Σ RECOGNITION(доход) = Σ SETTLEMENT(+AR) в валюте документа |
| `purchase.bill.received` | Σ RECOGNITION(расход) = Σ SETTLEMENT(+AP) |
| `payment.received` | Σ CASH(+) = Σ SETTLEMENT(−AR по аллокациям) + Σ SETTLEMENT(аванс) |
| `payment.made` | Σ CASH(−) = Σ SETTLEMENT(−AP по аллокациям) + Σ SETTLEMENT(аванс) |
| `payment.allocated` | Σ SETTLEMENT(−аванс) = Σ SETTLEMENT(−AR/AP) |
| `payment.refunded` | зеркально платежу |
| `sales.invoice.credit_issued` | Σ RECOGNITION(−доход) = Σ SETTLEMENT(−AR) |

Условия проверяются в unit-тестах правила и reconcile `A-01…A-03`. Если когда-либо
потребуется полная двойная запись, `account_code` и `balanced_within` уже на
месте — смена политики без смены схемы.

## 2. Связь Event → Posting

- Одно событие порождает **0..N групп** (по одной на применимое правило) и
  **1..M строк** в каждой.
- `fincore_posting_groups`: `group_id` (UUID v7), `company_id`, `event_id`,
  `rule_code`, `rule_version`, `effective_date`, `created_at`;
  `UNIQUE(event_id, rule_code, rule_version)`.
- `fincore_postings`: `posting_id` (UUID v7), `company_id`, `group_id`, `event_id`,
  `line_no` (≥1), `ledger`, `account_code` (категория P&L / `money_account:<id>` /
  `ar:<counterparty>`…), `dimensions` jsonb (`counterparty_id`,
  `project_direction_id`, `responsibility_center_id`, `document_ref`),
  `amount_minor` BIGINT со знаком (≠0), `currency` (валюта операции),
  `reporting_amount_minor` BIGINT NULL, `reporting_currency` CHAR(3) NULL,
  `fx_rate` NUMERIC NULL, `fx_rate_date` DATE NULL, `fx_rate_source` VARCHAR NULL
  (ADR-008; `CHECK`: пять `reporting_*/fx_*` полей — все NULL или все заданы),
  `effective_date`,
  `period` (`YYYY-MM`, вычисляется), `kind` (`original` | `reversal`),
  `reverses_posting_id` NULL, `posted_at`.
  `UNIQUE(group_id, line_no)`, `UNIQUE(reverses_posting_id) WHERE reverses_posting_id IS NOT NULL`,
  `CHECK(amount_minor <> 0)`, составные FK `(company_id, id)` с `RESTRICT`
  (приём из `Version20260914120000`).
- Индексы: `(company_id, ledger, effective_date)`, `(company_id, event_id)`,
  `(company_id, ledger, account_code, effective_date)`.

## 3. Идемпотентность проводок

Ключ идемпотентности группы — `(event_id, rule_code, rule_version)`; строки —
`(group_id, line_no)`. Повторная обработка события упирается в unique и
трактуется как «уже сделано» (см. `07`). Оркестратор работает как
`INSERT … ON CONFLICT DO NOTHING` + проверка, что существующая группа
побайтно совпадает (хэш строк; расхождение ⇒ `PostingDriftException` и
алерт — это баг правила/неизменяемости, не штатный случай).

## 4. Effective date, период, валюта

- `effective_date` проводки = `effective_date` события. **Правило её не
  переопределяет** (ADR-006): если признание и оплата в разные даты, это два
  события (`loan.payment.due` → `RECOGNITION` на `dueDate`;
  `cash.transaction.booked` → `CASH` на дату оплаты).
- `period` не хранится как источник истины; это производное от
  `effective_date` в календаре компании. Оркестратор проверяет состояние
  периода до записи проводок (ADR-007): в `SOFT_CLOSED/CLOSED` обычное событие
  не порождает проводок (processing=`blocked`), adjustment-событие допускается.
- Валюта — на **каждой строке** (ADR-008): `currency` + `amount_minor` — это
  исходная валюта операции, она сохраняется всегда. `reporting_*`/`fx_*`
  заполняются, если курс известен из события (или будущего провайдера курсов);
  если валюта ≠ reporting currency компании и курса нет — строка создаётся с
  пустыми `reporting_*`, проекция помечает её `fx_missing` (finding F-01).
  Курс 1.0 по умолчанию **не подставляется**.
- Группа может содержать несколько валют только если правило явно объявило
  FX-пару; условия `balanced_within` проверяются **по каждой валюте отдельно**.
- Float запрещён: значения денег — `Money` (целое + валюта), `fx_rate` —
  десятичная строка/`NUMERIC`.

## 5. Исправление ошибочных проводок

1. Проводки **никогда** не обновляются и не удаляются.
2. **Исправление события** (новая ревизия, `corrects_event_id`) в периоде
   `OPEN`: оркестратор в одной транзакции создаёт сторно-строки
   (`kind='reversal'`, `reverses_posting_id`, противоположный знак,
   `effective_date` исходной проводки — сторно попадает в тот же период) и затем
   проводки по новой ревизии.
3. Если период исходной проводки `SOFT_CLOSED/CLOSED`, обычная ревизия
   **блокируется** (`blocked`); исправление оформляется adjustment-событием
   (п.5), а не молчаливым сдвигом даты.
4. **Смена правила** (bugfix `rule_version+1`): replay события создаёт новую
   группу с новой версией и сторнирует группу прежней версии (`09 §3`). В
   `SOFT_CLOSED/CLOSED` — только как adjustment (`--as-adjustment --reason`).
   Старая версия остаётся в журнале.
5. **Ручная корректировка и late adjustment** — только событие
   `fincore.adjustment.posted` с обязательными `reason`, `actor_user_id`,
   `original_event_id` и записью аудита; оно может проводиться в
   `SOFT_CLOSED` (с причиной и пользователем) и `CLOSED` (с повышенным правом,
   ADR-007). Прямая запись проводок запрещена. Проводки adjustment помечаются
   `dimensions.adjustment=true`, чтобы отчёты по закрытому периоду могли
   показывать их отдельно.
6. Проекции после коррекции перестраиваются по затронутым ключам
   (`company, ledger, account, day`), а не целиком.

## 6. Правила (PostingRule)

```text
interface PostingRule { code(): string; version(): int; supports(eventType, eventVersion): bool;
                        post(FinancialEvent): PostingDraft  /* чистая функция, без I/O */ }
```

- Регистрация — tagged service (`app.fincore.posting_rule`), реестр по
  `(event_type, event_version)`; несколько правил на тип допустимы.
- Чистота: результат зависит только от события → replay детерминирован.
- **Граница ответственности** (ADR-006): бизнес-модуль определяет, **что
  произошло** (тип события, `occurred_at`, `effective_date`); `PostingRule`
  определяет **финансовый эффект** этого события. Правило не определяет
  бизнес-факт, не читает источник, не выбирает и не меняет `effective_date`,
  не обращается к состоянию периода (его проверяет оркестратор).
- Каждое правило имеет unit-тесты на все ветки (CLAUDE.md «Domain Policy») и
  property-тест «`post(e)` дважды ⇒ идентичный драфт».
- Начальные правила — **версионируемые** кодировки текущей семантики для
  shadow-сверки (ADR-006), а не универсальные правила системы:
  `marketplace_month_stage_v1` (RECOGNITION на последний день периода),
  `loan_schedule_v1` (RECOGNITION процентов/комиссии на `dueDate`),
  `cash_booking_v1` (только CASH; **без RECOGNITION**),
  `legacy_cash_basis_v1` (RECOGNITION по дате оплаты — только по явному
  `finance.document.created`). Правила Stage 7 (`sales_invoice_v1`,
  `purchase_bill_v1`, `payment_*_v1`) пишут RECOGNITION только из документов,
  SETTLEMENT и CASH — из платежей.

## 7. Что не входит

Курсовые разницы, амортизация, переоценка запасов (в проекте нет оценки запасов,
`InventoryCostPriceResolver` отдаёт `0.00` как fallback — это отдельная задача),
налоговый учёт.
