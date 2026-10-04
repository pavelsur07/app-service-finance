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
| `SETTLEMENT` | расчёты: AR/AP, авансы, аллокации | проекция AR/AP (Q1) |

Balance — **не** книга ядра: его журнал наполняется adapter'ом из групп проводок.

**Почему не полная двойная запись.** Сегодня нет AR/AP, а деньги маркетплейса до
выплаты — неучтённые расчёты; обязать каждую проводку иметь контрпартию значит
придумывать бухгалтерский план, не согласованный с Владельцем (Q1/Q3). Поэтому:
строки односторонние со знаком; баланс-условия задаёт **правило** на группу
(`balanced_within`: например, платёж: Σ SETTLEMENT = −Σ CASH). Если Q1/Q3 потребуют
двойной записи, `account_code` и `balanced_within` уже на месте — смена
политики без смены схемы.

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
  `amount_minor` BIGINT со знаком (≠0), `currency`, `effective_date`,
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

- `effective_date` проводки = `effective_date` события, если правило не
  переопределяет (например, признание процентов по Loan — `dueDate`, а
  CASH-проводка — дата оплаты). Переопределение явно фиксируется в правиле и в
  тестах.
- `period` не хранится как источник истины; это производное от
  `effective_date` в календаре компании.
- Валюта — на **каждой строке**. Внутри группы допускается несколько валют
  только если правило явно объявляет `fx`. Конвертация в учётную валюту — отдельная
  строка с `dimensions.fx_rate` (Q5). До ответа Q5 ядро принимает любую ISO-валюту
  и **не конвертирует**; проекция P&L работает по валюте компании.

## 5. Исправление ошибочных проводок

1. Проводки **никогда** не обновляются и не удаляются.
2. **Исправление события** (новая ревизия, `corrects_event_id`): оркестратор в
   одной транзакции создаёт сторно-строки (`kind='reversal'`,
   `reverses_posting_id`, противоположный знак, `effective_date` исходной
   проводки — чтобы сторно попало в тот же период) и затем проводки по новой
   ревизии. Если период закрыт (INV-09), сторно/новые строки получают
   `effective_date` = первый открытый день и пометку `dimensions.late_correction`.
3. **Смена правила** (bugfix `rule_version+1`): replay события создаёт новую
   группу с новой версией и сторнирует группу прежней версии (`09`).
   Старая версия остаётся в журнале.
4. **Ручная корректировка** — только событие `fincore.manual_adjustment` с
   обязательным `reason`, `approved_by` и ссылкой на корректируемое событие;
   прямая запись проводок запрещена.
5. Проекции после коррекции перестраиваются по затронутым ключам
   (`company, ledger, account, day`), а не целиком.

## 6. Правила (PostingRule)

```text
interface PostingRule { code(): string; version(): int; supports(eventType, eventVersion): bool;
                        post(FinancialEvent): PostingDraft  /* чистая функция, без I/O */ }
```

- Регистрация — tagged service (`app.fincore.posting_rule`), реестр по
  `(event_type, event_version)`; несколько правил на тип допустимы.
- Чистота: результат зависит только от события → replay детерминирован.
- Каждое правило имеет unit-тесты на все ветки (CLAUDE.md «Domain Policy») и
  property-тест «`post(e)` дважды ⇒ идентичный драфт».
- Начальные правила (соответствие текущей семантике, для shadow-сверки):
  `marketplace_month_stage_to_recognition`, `cash_booking_to_cash`,
  `cash_booking_to_recognition` (если у категории ДДС задан `plCategory` и
  включена опция), `loan_due_to_recognition`.

## 7. Что не входит

Курсовые разницы, амортизация, переоценка запасов (в проекте нет оценки запасов,
`InventoryCostPriceResolver` отдаёт `0.00` как fallback — это отдельная задача),
налоговый учёт.
