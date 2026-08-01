# План: разбивка транзакции ДДС на несколько категорий (cash_transaction_split)

> Статус: Phase 0 — план, **ревизия 3** (внешнее ревью отработано, PROD-срез учтён).
> Спецификация: `TASK.md`. Чекпоинт: `checkpoint.md`.
> Ветка задачи: `agent/cash-transaction-splits` (создать от `master`).
> Принцип миграции: **expand → dual-write → backfill → switch read → UI → contract**.
> Что изменилось в ревизии 3 и почему — раздел «Разбор внешнего ревью» в конце.

---

## PROD-срез (2026-08-01, read-only)

Определяет масштаб и риски. Только агрегаты — сырые суммы и ID в репозиторий не пишем.

| Показатель | Значение |
|---|---|
| Транзакций всего / живых | 6034 / 5975 |
| Компаний | 7 |
| За последние 90 дней | 2557 |
| Без категории | 7 |
| В «Не распределено» | 2530 (42% живых) |
| Категорий ДДС | 340 |
| …с `allow_pl_document = true` | 20 |
| Транзакций в этих категориях | 401 (6.6%) |
| Документов ОПиУ из транзакций | 266 на 236 транзакциях (230×1, 4×3, 2×12) |
| Транзакций с документами **разных** статей ОПиУ | **0** |
| Частично разнесённых транзакций | **0** |
| Записей в `payment_plan_match` | 1 |

Выводы, на которых стоит план:
- База крошечная. Backfill — секунды, окно не нужно; можно позволить backfill на PHP
  с использованием доменных сервисов вместо raw SQL.
- Зона ОПиУ — 20 категорий и 6.6% транзакций, и ни одного мультистатейного разнесения.
  Значит разбивку можно ввести, вообще не заходя в эту зону (решение D1).
- Платёжные планы фактически не используются (1 матч) — цена отключения автомэтча нулевая.

---

## Решения по бизнес-семантике

**D1 — документы ОПиУ. Решено: разбивка не пересекается с ОПиУ.**
Транзакцию можно разбить (строк > 1) **только если все категории строк имеют
`allow_pl_document = false`** — это 320 категорий из 340 и 93% транзакций.
Тогда `CashTransactionToDocumentService` и `CreateDocumentFromTransactionAction`
всегда видят ровно одну строку, их семантика не меняется, ни один из 266 существующих
документов не затронут.
Ограничение снимается отдельной задачей, когда появится живой кейс. Условия снятия:
allocation на уровне строки (сейчас `allocatedAmount` знает только общую сумму, привязки
документа к строке нет), правило повторного создания документа и правило редактирования
строк после создания документов.

**D2 — платёжные планы. Решено: не автомэтчить мультисплиты в v1.**
`PaymentPlanMatch` имеет `UNIQUE(transaction_id)` — один матч на транзакцию. Матчинг
на строку потребовал бы менять модель. При одной записи в таблице на проде это не окупается.
Транзакция с одной строкой матчится как сейчас; транзакция с N строками из автомэтча
исключается. Существующий матч при разбивке транзакции — удаляется с записью в аудит.

**D3 — ведомость транзакций.** Одна строка на split, сумма = сумма split. Фильтр по категории
оставляет только совпавшие строки. Иначе итог ведомости не сойдётся с отчётом ДДС.

**D4 — экспорт XLSX. Подтверждено Владельцем 2026-08-01.** То же, что D3: одна строка
на split, сумма — сумма строки, дата/счёт/контрагент/описание дублируются. Альтернатива
(склейка категорий в одну ячейку) отвергнута: она сохраняет «строка = платёж», но делает
колонку категории непригодной для сводных таблиц и суммирования, то есть ломает ровно то,
ради чего категорию в выгрузку и кладут.

Принятое следствие: смысл строки файла меняется с «платёж» на «статья платежа». Владелец
подтвердил, что выгрузку читают глазами и внешних потребителей у неё нет, поэтому
отдельная колонка «№ платежа» для схлопывания строк на стороне получателя не нужна.
Если потребитель появится — это отдельная задача, а не правка Stage 3.

---

## Модель данных

```sql
CREATE TABLE cash_transaction_split (
    id                   uuid PRIMARY KEY,
    company_id           uuid NOT NULL,
    cash_transaction_id  uuid NOT NULL REFERENCES cash_transaction(id) ON DELETE CASCADE,
    cashflow_category_id uuid NOT NULL REFERENCES cashflow_categories(id) ON DELETE RESTRICT,
    amount               numeric(18,2) NOT NULL CHECK (amount > 0),
    source               varchar(16) NOT NULL,   -- manual | auto | import
    CONSTRAINT uniq_cts_tx_category UNIQUE (cash_transaction_id, cashflow_category_id)
);
CREATE INDEX idx_cts_transaction      ON cash_transaction_split (cash_transaction_id);
CREATE INDEX idx_cts_company_category ON cash_transaction_split (company_id, cashflow_category_id);
```

Проект, ЦФО и комментарий на строке **не заводим** — они остаются на транзакции.
Цель задачи — разбивка по категориям и суммам; per-split аналитика потребовала бы решений
о defaults vs второй источник правды, поведении автоправил, паре проект×ЦФО и миграции
всех отчётов и ОПиУ. Добавлять при явном продуктовом требовании, отдельной задачей.

`UNIQUE (cash_transaction_id, cashflow_category_id)` — две строки одной категории внутри
транзакции бессмысленны без per-split аналитики, а ограничение делает backfill
безопасным при конкурентной вставке (`ON CONFLICT DO NOTHING`).

### Инварианты

| # | Инвариант | Где живёт |
|---|---|---|
| 1 | `SUM(splits.amount) = transaction.amount` — **строго равно** | `CashTransaction::replaceSplits()` |
| 2 | Недостача автоматически добирается строкой в системной «Не распределено» | там же |
| 3 | `amount > 0`; знак — из `transaction.direction` | CHECK + Assert в конструкторе |
| 4 | Валюта одна — транзакции; в split валюты нет | конструкция схемы |
| 5 | Если строк > 1, все категории обязаны иметь `allow_pl_document = false` (D1) | доменная проверка |
| 6 | Округление при разбивке процентами: последняя строка забирает остаток | JS формы + инвариант 1 |
| 7 | Авторазбивка перезаписывает только строки `source = auto` | `CashTransactionAutoRuleService` |
| 8 | Изменение состава строк пишет один aggregate-`AuditLog` на транзакцию | см. «Аудит» |
| 9 | Каждый запрос по сплитам — с `company_id` (IDOR) | Repository/Query |

**Инварианта «flowKind категории ↔ direction транзакции» нет и не будет.**
Проверено: `CashflowFlowKind` — вид деятельности (OPERATING / INVESTING / FINANCING /
TECHNICAL), `CashflowCategory::$operationType` — `PaymentPlanType`. Признака направления
у категории не существует, категория допустима в обоих направлениях. В ревизии 2 инвариант
был выдуман — удалён.

**Инвариант 1 — строгое равенство, не `<=`.** При `<=` часть суммы после переключения
читателей просто исчезает из отчёта: в колонке она была отнесена куда-то, в строках —
никуда. Недостача обязана материализоваться строкой «Не распределено».

### Legacy-колонка в переходный период

При сохранении транзакции колонка заполняется:
- одна строка → её категория (как сейчас);
- строк > 1 → **системная «Не распределено»**, не `NULL`.

Это ключевое отличие от ревизии 2. Отчёт по колонке при откате остаётся суммарно верным
(деньги видны в «Не распределено», а не пропадают), rollback не требует уничтожать строки,
и **единственной точкой невозврата становится `DROP COLUMN`**, а не включение UI.

### `source` vs существующий provenance

`CashTransactionAutoRuleProvenanceResolver` восстанавливает «поле проставил человек или
правило» из истории `AuditLog` и используется в `ApplyAutoRulesForTransactionHandler:72`
вместе с `CashTransactionAutoRuleApplyMode::SAFE | REPLACE_AUTO_ASSIGNED`.

Колонка `source` — замена этому механизму для строк: резолвер сравнивает скалярное
значение с диффом аудита и на коллекции не работает.

Чтобы при переходе не потерять информацию:
- **backfill выполняется на PHP и проставляет `source` через существующий резолвер**
  (`auto` — если категория была назначена правилом, иначе `manual`). На 6 тыс. строк это
  дёшево и снимает проблему «всё стало `import`, режим SAFE перестал различать».
  `import` остаётся только для транзакций без истории аудита;
- для поля `splits` резолвер не расширяется — единственный источник правды `source`;
- ветка резолвера по полю `cashflowCategory` удаляется вместе с колонкой, в contract-задаче.

### Аудит

`AuditLogSubscriber` подписан только на `postPersist` и `postUpdate` — удаление строки он
не увидит. `CashTransactionAuditController:29` фильтрует историю по `CashTransaction::class`.
Поэтому per-child аудит бесполезен вдвойне.

Решение: **один aggregate-`AuditLog` на `CashTransaction`** с before/after состава строк
(категория + сумма + source), пишется в том же месте, где сохраняется разбивка.
Двойное логирование автоправил исключается через существующий `AutoRuleDispatchGuard` и
`$applicationPlan->auditDiff()`. Для backfill per-row аудит не создаётся — фиксируется
явно в Stage Report.

---

## Карта потребителей

### Writers — создают транзакцию или назначают категорию

| Файл:строка | Контекст | source |
|---|---|---|
| `src/Cash/Service/Transaction/CashTransactionService.php:128` | ручное создание | `manual` |
| `src/Cash/Service/Transaction/CashTransactionService.php:207` | ручное редактирование | `manual` |
| `src/Cash/Service/Transaction/CashTransactionAutoRuleService.php:533` | автоправило | `auto` |
| `src/Cash/MessageHandler/ApplyAutoRulesForTransactionHandler.php:106` | fallback «Не распределено» | `auto` |
| `src/Cash/Service/Import/Bank/BankImportService.php:138` | импорт банка | `import` |
| `src/Cash/Service/Import/ClientBank1CImportService.php:342` | импорт 1С | `import` |
| `src/Cash/Service/Import/File/CashFileImportService.php:158` | импорт файла | `import` |
| `src/DataFixtures/CashTransactionsFixtures.php:61` | фикстуры | `import` |

Импорты создают транзакцию без категории. При инварианте 1 они обязаны создать строку
«Не распределено» — иначе транзакция нарушает инвариант с момента создания.

### Readers — читают категорию транзакции

| Файл:строка | Что это | Решение |
|---|---|---|
| `src/Finance/Controller/ReportCashflowOpsCheckController.php:212,234` | тех. сверка ops | перенос на JOIN |
| `src/Finance/Controller/ReportTransactionsStatementController.php:316,333` | ведомость | D3 |
| `src/Cash/Repository/Transaction/CashTransactionRepository.php:282` | фильтры, экспорт, дашборд | D4 |
| `src/Cash/Infrastructure/Query/CashTransactionAutoRuleCandidateQuery.php:37` | генерация кандидатов правил | raw SQL → JOIN |
| `src/Cash/Facade/CashFacade.php:304` | публичный контракт (MCP), одиночная категория | контракт меняется |
| `src/Cash/Application/Service/CashTransactionAutoRulePrefiller.php` | preview/prefill правил | перенос |
| `src/Ai/Service/Agent/CashflowAgent.php:104` | AI-агент | перенос |
| `src/Cash/Service/PaymentPlan/PaymentPlanMatcher.php:57,76` | матчинг планов | D2 |
| `src/Cash/Service/Transaction/CashTransactionToDocumentService.php:77–121` | документ ОПиУ | D1 — не меняется |
| `src/Cash/Application/CreateDocumentFromTransactionAction.php:21` | второй путь документа ОПиУ | D1 — не меняется |
| `src/Report/Cashflow/CashflowReportBuilder.php:192` | главный отчёт ДДС | перенос |

Шаблоны: `templates/transaction/index.html.twig`, `show.html.twig`, `_form.html.twig`,
`templates/cash/transaction/deleted_index.html.twig`.

**Проверено, влияния нет:** `CashflowCategoryStructureMigrator` (меняет только `parent_id`
и `flow_kind` в самих категориях), модули `Analytics` и `Balance` категорию ДДС не читают.

**Проверка полноты в DoD Stage 3** — grep с исключением собственной таблицы:

```bash
grep -rn "cashflowCategory\|cashflow_category_id" site/src/ --include=*.php \
  | grep -v "cash_transaction_split\|CashTransactionSplit\|CashflowCategory.php\|CashflowCategoryRepository"
```

Голый grep из ревизии 2 как gate непригоден — он находит легитимные поля самой
split-таблицы. Отдельная inventory-команда для 11 потребителей не окупается; полнота
проверяется этим grep плюс построчной сверкой с таблицей выше.

---

## Stage 1: Схема, Entity, все writers, аудит, backfill/verify

Risk: 🟠 HIGH-LOCAL
owner_gate: no
release_candidate: yes
independently_deployable: yes
stage_base_commit: `<зафиксировать перед реализацией>`

**Definition of Done:**
- Таблица, Entity `CashTransactionSplit`, Repository (каждый метод с `string $companyId`), Builder.
- Доменные методы на `CashTransaction`: `replaceSplits()`, `getSplitsTotal()`, проверка
  инвариантов 1–3, 5, 7 и автодобор «Не распределено».
- **Все 8 writers** пишут колонку и строки. Инвариант этапа: `SUM(splits) = amount` у каждой
  новой или изменённой транзакции.
- Legacy-колонка при строках > 1 → «Не распределено» (пока UI не даёт >1, ветка покрыта тестом).
- Aggregate-`AuditLog` на изменение состава строк; двойного логирования автоправил нет.
- Команда `app:cash:backfill-transaction-splits` — PHP, батчами, идемпотентная,
  `ON CONFLICT DO NOTHING`, `source` через provenance-резолвер, `--dry-run`.
- Команда `app:cash:verify-transaction-splits` — **per-transaction** сверка, печатает покрытие.
- Тесты: инварианты 1–3, 5, 7, 9; каждый writer; аудит; идемпотентность backfill;
  импорт создаёт «Не распределено».
- `ARCHITECTURE.md` — новая Entity.

**Work items:** 1.1 миграция DDL · 1.2 Entity + доменные методы · 1.3 Repository ·
1.4 ручные writers · 1.5 автоправила · 1.6 три импорта + фикстуры · 1.7 aggregate-аудит ·
1.8 backfill-команда · 1.9 verify-команда · 1.10 тесты.

**Verify-команда проверяет по каждой транзакции:**
- число строк и точное равенство суммы;
- совпадение `company_id` строки и транзакции (нет cross-company);
- отсутствие orphan-строк;
- разрезы: живые и soft-deleted отдельно;
- итог по `company + category + direction + currency`.

Агрегата по одной категории недостаточно — взаимно компенсирующие ошибки в нём не видны.

**Stage checks:** `make site-test` (Cash, Finance, Report), точечный `make site-cs-check`,
smoke: создание/редактирование/импорт/автоправило дают корректные строки и запись в аудит.

**Reviewer focus:** IDOR; повторный прогон автоправил не дублирует и не стирает строки;
`flush()` не в Repository; `source` не конфликтует с резолвером.

---

## Stage 2: Backfill на PROD

Risk: 🔴 HIGH-EXTERNAL — Production Gate A
owner_gate: yes
release_candidate: no
independently_deployable: n/a
stage_base_commit: `<совпадает со Stage 1 + фиксы>`

**🛑 STOP.** Каждый шаг — отдельное разрешение Владельца непосредственно перед запуском.
Оператор и wrapper для backfill/verify/backup определяются до входа в Gate: существующий
`codex-console` allowlist мутирующих команд не содержит, расширение allowlist — отдельное
решение Владельца.

1. Оценка объёма и замеры «до» (read-only, через `codex-psql-ro`).
2. Бэкап `cash_transaction` в `/var/backups/app-service-finance/`.
3. Backfill.
4. Verify: per-transaction сверка зелёная, счётчики «до» сошлись, оба разреза пустые.
5. Расхождение — сначала объяснить, потом докладывать: часть аномалий может быть старше миграции.

В Stage Report — только результат сверки. Детальный вывод с суммами и ID остаётся
в operational artifact на проде.

**Замечание по данным:** у одной компании системная «Не распределено» имеет legacy-код
`UNALLOCATED`, у остальных — `CF_UNALLOC`. Проверено: не дефект,
`CashflowCategoryRepository::findSystemUnallocatedByCompany()` знает оба. Backfill и verify
обязаны использовать этот метод, а не константу `CODE_UNALLOCATED`, иначе 2388 транзакций
выпадут из сверки.

---

## Stage 3: Переключение всех читателей и контрактов

Risk: 🟠 HIGH-LOCAL
owner_gate: **yes** — Release Gate со сверкой отчётов до перехода к UI
release_candidate: yes
independently_deployable: yes
stage_base_commit: `<зафиксировать>`

Один Stage, один релиз. Work items — внутренние шаги, не PR/deploy boundary (`AGENTS.md`,
раздел Work item). Пока строки 1:1 с колонкой, старый и новый вывод обязаны совпадать —
это бесплатный тест каждого Work item.

**Definition of Done:**
- Все 11 потребителей читают строки; grep-проверка выше чистая; таблица потребителей
  пройдена построчно.
- Для каждого — сравнение старого и нового вывода: идентично.
- Публичный контракт `CashFacade::serializeTransaction` отдаёт массив категорий;
  изменение зафиксировано в `ARCHITECTURE.md` и README модуля.
- Шаблоны показывают категории из строк.
- Отчёт ДДС без N+1 (проверено Profiler).

**Work items:** 3.1 ops-check · 3.2 ведомость (D3) · 3.3 репозиторий фильтров/экспорта (D4) ·
3.4 candidate-query автоправил · 3.5 `CashFacade` + MCP-контракт · 3.6 prefiller/preview ·
3.7 AI-агент · 3.8 `PaymentPlanMatcher` (D2) · 3.9 `CashflowReportBuilder` · 3.10 шаблоны.

`CashTransactionToDocumentService` и `CreateDocumentFromTransactionAction` в этом Stage
меняются механически: «колонка → единственная строка». Семантика не меняется (D1).

**Stage checks:** `make site-test`, точечный cs-check, Profiler-smoke на отчёте ДДС,
ручная сверка отчёта ДДС и ведомости за закрытый период до/после.

**Release Gate (owner_gate: yes):** Владелец подтверждает на проде, что отчёты сошлись,
**до** того как включается UI мультисплита.

---

## Stage 4: UI разбивки

Risk: 🟠 HIGH-LOCAL
owner_gate: no (гейт отработал в конце Stage 3)
release_candidate: yes
independently_deployable: yes
stage_base_commit: `<зафиксировать>`

**Definition of Done:**
- Форма: коллекция строк (категория + сумма), автострока «Не распределено» с остатком,
  серверная валидация инвариантов 1, 3, 5.
- Попытка разбить транзакцию в категории с `allow_pl_document = true` → понятная ошибка
  со ссылкой на причину (D1).
- Разбивка процентами — JS-помощник; в БД суммы; последняя строка забирает остаток.
- Legacy-колонка при строках > 1 → «Не распределено».
- Автоправила не трогают транзакции с ручной разбивкой; существующий `PaymentPlanMatch`
  при разбивке удаляется с записью в аудит (D2).
- Повторный импорт (`uniq_cashflow_import`) обновляет транзакцию, но не пересоздаёт строки.
  Регрессионный тест обязателен.
- UI по UI Kit: Table, EntityPicker, Money-формат.

**Work items:** 4.1 Form/DTO + валидация · 4.2 Twig + JS-помощник · 4.3 защита от автоправил,
импорта и матчера · 4.4 отображение мультисплитов в списках и детализации.

Точкой невозврата этот Stage **не является** — legacy-колонка проецируется в
«Не распределено», суммы при откате сохраняются, теряется только детализация.

---

## Отдельная задача: contract (не входит в эту задачу)

`DROP COLUMN` — деструктивная операция, требующая периода наблюдения. Оформляется
отдельным task ID после закрытого отчётного периода:

1. Прекратить запись в `cashflow_category_id`; удалить из
   `CashTransactionAutoRuleProvenanceResolver` ветку по полю `cashflowCategory`.
2. Период наблюдения — минимум один закрытый отчётный период.
3. Отдельный Stage + Production Gate B на `DROP COLUMN`.

---

## Порядок

```
Решения D2–D4 подтверждены Владельцем
Stage 1 (schema + writers + audit + backfill/verify)  → Stage Report → deploy
Stage 2 = Production Gate A (STOP): бэкап → backfill → per-transaction verify
Stage 3 (все readers и контракты, один релиз)         → Release Gate (owner_gate: yes)
Stage 4 (UI мультисплита)                             → deploy
   … закрытый отчётный период …
Отдельная задача: contract → Production Gate B → DROP COLUMN
```

## Сквозные риски

| Риск | Митигация |
|---|---|
| Часть суммы исчезает из отчёта после переключения | Инвариант 1 — строгое равенство, автодобор «Не распределено» |
| Откат после включения UI теряет деньги | Legacy-проекция в «Не распределено»; необратим только `DROP COLUMN` |
| Разбивка ломает документы ОПиУ | D1: разбивка запрещена в категориях с `allow_pl_document` |
| Потеря provenance после backfill | Backfill на PHP через существующий резолвер |
| Удаление строки не попадает в историю | Aggregate-аудит на транзакции, а не per-child |
| Взаимно компенсирующие ошибки backfill | Per-transaction сверка, а не агрегат по категории |
| Конкурентная вставка при backfill | `UNIQUE (tx, category)` + `ON CONFLICT DO NOTHING` |
| Импорт создаёт транзакцию без строки | Три импорта — writers Stage 1, тест обязателен |
| 2388 транзакций выпадают из сверки | `findSystemUnallocatedByCompany()`, не константа |
| Матчер планов ломается на мультисплите | D2: мультисплит исключён из автомэтча |
| Публичный MCP-контракт меняется молча | 3.5 + `ARCHITECTURE.md` и README модуля |
| IDOR через новую таблицу | `companyId` в каждом методе Repository |
| N+1 в отчётах | Агрегация одним запросом, Profiler-smoke в Stage 3 |

## Документация

- `ARCHITECTURE.md` — Entity `CashTransactionSplit`, изменение контракта `CashFacade`.
- `docs/tasks/cash-transaction-splits/stages/stage-<N>.md` — Stage Report на каждый Stage.
- `checkpoint.md` — обновляется после каждого Work item.
- `handoff.md` — на Final Release Gate.

---

## Разбор внешнего ревью (ревизия 2 → 3)

Каждый пункт проверен по коду перед принятием.

| # | Замечание | Вердикт | Что сделано |
|---|---|---|---|
| 1 | Инвариант `flowKind ↔ direction` неверен | **Принято.** Подтверждено: `CashflowFlowKind` = вид деятельности, `operationType` = `PaymentPlanType`. Признака направления у категории нет | Инвариант удалён, замена не вводится |
| 2 | `SUM <= amount` теряет деньги; NULL-категории пропущены; legacy-колонка должна быть `CF_UNALLOC`, а не `NULL` | **Принято полностью.** Сильнее исходного варианта | Инвариант 1 — строгое равенство + автодобор; backfill покрывает все транзакции; legacy-проекция в «Не распределено»; точка невозврата сдвинулась на `DROP COLUMN` |
| 3 | D1 недостаточен (нет allocation на строку, два пути создания документа); D2 конфликтует с `UNIQUE(transaction_id)` | **Принято по существу, решено иначе.** Подтверждены оба пути и уникальность. PROD-срез показал: 20 категорий с ОПиУ, 0 мультистатейных разнесений | D1 → разбивка запрещена в категориях с `allow_pl_document`; проблема allocation не наступает и вынесена в условия снятия. D2 → мультисплит исключён из автомэтча |
| 4 | Карта writers/readers неполна | **Принято.** Подтверждены 3 импорта-writer'а и 3 reader'а (`Repository:282`, `CandidateQuery:37`, `CashFacade:304`) | Карта дополнена до 8 writers и 11 readers |
| 4а | Нужна автоматическая inventory-команда; grep как gate непригоден | **Принято частично.** Grep действительно ловит собственную таблицу | Grep с исключением + построчная сверка по таблице в DoD. Отдельная команда для 11 потребителей не окупается |
| 5 | Аудит: нет `postRemove`, UI фильтрует `CashTransaction::class` | **Принято полностью.** Подтверждено: `getSubscribedEvents()` = postPersist + postUpdate | Один aggregate-`AuditLog` на транзакцию с before/after состава; per-child аудит отменён; для backfill отсутствие per-row аудита фиксируется явно |
| 6 | Backfill/verify недостаточно строгие; потеря provenance | **Принято.** | Per-transaction сверка по 6 критериям; `UNIQUE` + `ON CONFLICT DO NOTHING`; backfill на PHP через существующий резолвер вместо потери provenance |
| 7 | Per-split проект/ЦФО/comment — расширение scope | **Принято.** Отменяет собственную более раннюю рекомендацию | Схема v1: transaction + category + amount + source. Проект и ЦФО остаются на транзакции |
| 8 | Rollout конфликтует с `AGENTS.md`: Work item не может быть deploy boundary; owner_gate не в том месте; Stage 5 = два релиза; нет DoD/checkpoint/TASK.md | **Принято полностью.** Подтверждено по `AGENTS.md`, раздел Work item | 4 Stage вместо 5 «этапов с деплоями»; owner_gate перенесён на конец Stage 3; contract вынесен отдельной задачей; DoD/checks/reviewer focus у каждого Stage; созданы `TASK.md` и `checkpoint.md` |
| 9 | Не хранить сырые production-суммы и ID в репозиторных отчётах; определить оператора для backfill/backup | **Принято.** | В плане только агрегаты; Stage Report — результат сверки; оператор и wrapper определяются до входа в Production Gate A |
