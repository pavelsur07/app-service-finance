# 10. Migration roadmap (Stage 1–N)

Каждый Stage — отдельная задача/ветка/Draft PR, ревьюится независимо, класс по
AGENTS.md §4. Порядок выбран так, чтобы (а) сначала закрыть P0-риски в legacy
дешёвыми точечными правками, (б) построить ядро без нагрузки на прод, (в)
подключать продюсеры по одному в **shadow-режиме** и только потом переключать
источник. Принцип отката: до Stage 10 любой Stage откатывается отключением
флага/продюсера без потери данных legacy.

Легенда: **BC** — что остаётся обратно-совместимым; **Legacy** — что ещё
работает; **Gate** — условие перехода дальше; **Approval** — что требует
отдельного согласия (§3.3 AGENTS.md).

Бизнес-решения Q1–Q5 приняты Владельцем и зафиксированы в ADR-005…008
(`00-overview.md`, «Owner decisions»). **Ни один Stage не ждёт решений
Владельца по Q1–Q5**; зависимости ниже — только между Stage.

## Сводка

| Stage | Название | Класс/риск | Зависит от | Меняет прод-поведение |
|---|---|---|---|---|
| 1 | Reliability hardening legacy | Large / HIGH-LOCAL (по подпунктам — Small) | Stage 0 | да, точечно |
| 2 | Core foundation: events + outbox | Large / HIGH-LOCAL (миграция) | 0 | нет (нет продюсеров) |
| 3 | Orchestrator, queues, DLQ, ops | Large / HIGH-LOCAL (messenger, workers) | 2 | нет |
| 4 | Producer: Marketplace month close (shadow) | Large / HIGH-LOCAL (финансовая логика) | 3 | нет (shadow) |
| 5 | P&L projection + reconcile + cutover P&L | Large / HIGH | 4 | да (после Gate) |
| 6 | Producer: Cash (+ атомарность, остатки) | Large / HIGH-LOCAL | 3 | частично |
| 7 | AR/AP, Invoice, Bill, Payment | Large / новый домен | 5, 6 | да (новая функция) |
| 8 | Balance intake | Large / HIGH-LOCAL | 5, 6 | да (Balance получает данные) |
| 9 | Reconciliation suite, replay tooling, runbook | Large / MEDIUM | 5, 6 | нет |
| 10 | Cutover и вывод legacy | Large / HIGH | 5–9 | да |

Stage 9 частично выполняется параллельно с 5–8 (каждая проверка `09 §4`
поставляется вместе со своим Stage); отдельный Stage 9 закрывает остаток и
инструменты.

## Stage 1 — Reliability hardening legacy (быстрые победы)

- **Scope** (каждый пункт — отдельный PR класса Small/XS, не один монолит):
  1. **[выполнено, Stage 1.1]** R-01: переклейм WB-дней в `raw_loaded/processing` старше 6 ч
     (`canClaimForQueue`/планировщик) + регрессионный тест, красный на старом коде.
  2. R-04/M-01: гейт «закрытие месяца невозможно/предупреждает, если есть дни не в
     `success` или строки с `document_id IS NULL` по периоду»; read-only cron-гейт.
  3. R-03: алерт на глубину `failed` и возраст сообщений (read-only гейт в cron).
  4. R-07: вернуть cron на `cash:auto-rules:enqueue` (после проверки причины,
     по которой он отключён) и ночной `DailyBalanceRecalc` — **уточнить у Владельца
     причину отключения** (операционный вопрос Stage 1, не Q1–Q5), не включать вслепую.
  5. R-12: объявить unique-индексы returns/costs/raw в ORM-атрибутах (без
     миграции, если индексы уже есть; сверка `schema:validate`); план по
     `uniq_marketplace_srid` без `company_id` — отдельно.
  6. R-18, R-23: убрать `DBG:`-маркеры; синхронизировать `ARCHITECTURE.md`
     «Cron-задачи» с `app.cron`.
- **Зависимости:** нет.
- **Результат:** закрыты P0 R-01/R-03/R-04 без нового ядра.
- **BC:** все публичные контракты. **Legacy:** всё работает как раньше.
- **Gate:** гейты зелёные на проде ≥ 7 дней.
- **Approval:** изменения cron/воркеров/очередей на проде — §3.3; миграция (если
  потребуется) — отдельный dispatch (`docs/workflow/release.md`).

## Stage 2 — Core foundation: события и outbox

- **Scope:** модуль `src/FinancialCore`; таблицы `fincore_events`,
  `fincore_outbox`, `fincore_event_processing`; `FinancialCoreFacade::recordEvent`
  (без flush); unique-ключи `07`; триггеры неизменяемости; **денежная схема
  ADR-008** (`amount_minor`+`currency`, `reporting_*`/`fx_*` с CHECK, value
  object `Money`, запрет `float`); **`fincore_periods` и
  `fincore_period_transitions`** (ADR-007; только схема и репозиторий);
  Builder'ы тестов;
  Outbox Publisher (команда + sweeper E-01/E-02), **без подключённых продюсеров**;
  обновление `ARCHITECTURE.md`; регистрация в `routes/doctrine/twig`.
- **Зависимости:** Stage 0 (решения Q5, Q4 — ADR-008, ADR-007: схема денег, FX-поля и периодов закладывается сразу, чтобы не мигрировать позже).
- **Результат:** можно записать событие и получить его опубликованным;
  идемпотентность и атомарность доказаны тестами (`07 §6`).
- **BC:** полностью; пустые таблицы.
- **Legacy:** всё.
- **Gate:** тесты `07 §6` зелёные; миграция применена на проде без данных.
- **Approval:** миграция на проде — отдельный dispatch; запуск publisher-сервиса
  (docker/workers) — §3.3.
- **Rollback:** публикатор выключается; таблицы остаются пустыми.

## Stage 3 — Orchestrator, транспорты, DLQ, эксплуатация

- **Scope:** `PostingRule` интерфейс и реестр; Posting Orchestrator;
  `fincore_postings`, `fincore_posting_groups`; транспорты `fincore_*`,
  `failure_transport`, воркеры; `fincore:dlq:*`; алерты `E-03/E-04`; runbook;
  `CompanyAwareMessage` для сообщений ядра; **политика периода в оркестраторе**
  (ADR-007): состояние периода `OPEN/SOFT_CLOSED/CLOSED`, эффективное состояние
  = max(ядро, `Company::financeLockBefore`), статус processing `blocked`,
  adjustment-flow `fincore.adjustment.posted` (причина, пользователь, исходное
  событие, аудит; роль для `CLOSED` выбирается по модели ролей `Company`),
  `--as-adjustment` у replay; проверки `E-05`, `X-01`, `P-02`.
- **Зависимости:** Stage 2.
- **Результат:** событие, записанное вручную/тестом, проходит
  event → posting с проверкой периода; replay-команда (dry-run) работает и
  соблюдает политику периода.
- **BC:** да; **Legacy:** все очереди и воркеры прежние.
- **Gate:** нагрузочный прогон на тестовых событиях; chaos-тесты (убить воркер
  между шагами, дубль доставки) зелёные.
- **Approval:** `messenger.yaml` (HIGH-LOCAL, без STOP), новые воркеры/DSN/Redis —
  §3.3.

## Stage 4 — Producer: закрытие месяца Marketplace (shadow)

- **Scope:** `CloseMonthStageAction` пишет событие `marketplace.month_stage.closed`
  **в той же транзакции**; `Reopen` — `…reopened`; правило
  `marketplace_month_stage_to_recognition`; проекция-тень `fincore_pnl_daily`;
  сверка S-01 с `pl_daily_totals`; починка R-06 (reopen+close в одной
  транзакции) и R-09 (processing-строки вместо JSON `succeeded_steps`) — как
  отдельные Work items с регрессионными тестами.
- **Зависимости:** Stage 3. Правило признания — `marketplace_month_stage_v1` (текущая семантика «конец отчётного периода», ADR-006), не универсальное.
- **Результат:** shadow-сверка расхождений 0 за N закрытых месяцев.
- **BC:** P&L-документы и регистр создаются как раньше; событие — дополнительная запись.
- **Legacy:** `documents`/`pl_daily_totals` — источник истины.
- **Gate:** S-01 = 0 расхождений за 2 закрытых периода всех подключений либо
  каждое расхождение объяснено (баг legacy / правило).
- **Rollback:** флаг продюсера off; события остаются как журнал.

## Stage 5 — P&L projection, reconcile, cutover P&L

- **Scope:** полноценная проекция P&L из `RECOGNITION`; события
  `finance.document.created/voided` для ручных/Cash/Loan документов
  (совместимость) с правилами `loan_schedule_v1` и `legacy_cash_basis_v1`
  (ADR-006); `FinancialPeriodFacade::assertWritable()` для legacy-писателей
  (Finance, Loan, импорты Cash — закрывает R-14, ADR-007); проверка `F-01`
  (`fx_missing`); устранение R-05/R-11/R-13–R-15 в новом контуре
  (decimal/minor units, идемпотентность, период, отсутствие тихих пропусков:
  невалидное — в `fincore_reconcile_findings`); чтение отчётов переключается
  за флагом на проекцию; X-01, P-01.
- **Зависимости:** Stage 4 (ADR-006, ADR-007, ADR-008).
- **Результат:** отчёты ОПиУ читают проекцию ядра; регистр legacy ведётся
  параллельно для сверки.
- **BC:** старые URL/API отчётов; переключение по флагу на компанию.
- **Legacy:** `PLRegisterUpdater` продолжает писать.
- **Gate:** S-01/P-01 чисто ≥ 1 закрытого периода на всех компаниях; Владелец
  принимает расхождения, если они — исправленные баги legacy (семантика).
- **Rollback:** флаг чтения обратно на `pl_daily_totals`.

## Stage 6 — Producer: Cash

- **Scope:** `cash.transaction.booked/reversed`, `cash.transfer.booked`
  (правило `cash_booking_v1`: только CASH, без RECOGNITION — ADR-006; FX
  перевода передаётся в `fx`-блок, ADR-008);
  `CashTransactionService::add/update/delete/restore` — в одной транзакции вместе
  с событием; импорты (Alfa, файл, 1С) переводятся на единый путь (пересчёт
  остатков, период, кэш) — закрывает R-07; CASH-проекция; C-01/C-02;
  `loan.payment.due`; Telegram `occurredAt` по времени сообщения (R-19, это
  изменение поведения — явно в PR).
- **Зависимости:** Stage 3.
- **BC:** HTTP/Facade API Cash не меняется; `CashFacade::createTransaction` сигнатура прежняя.
- **Legacy:** `money_account_daily_balance` остаётся источником до Gate.
- **Gate:** C-01 = 0 расхождений ≥ 30 дней.
- **Approval:** изменение поведения импортов — в описании «merge and deploy».

## Stage 7 — AR/AP: Invoice, Bill, Payment

- **Scope:** **новый домен** (ADR-005): состав бизнес-модулей Invoice/Bill/Payment определяется в Phase 0; первичные документы,
  события `sales.invoice.*`, `purchase.bill.*`, `payment.*`; `SETTLEMENT`-правила;
  AR/AP-проекция; связь платёж ↔ банковская транзакция; предоплата/частичный
  платёж/возврат/сторно; UI и API (отдельные Stage-ы бэкенд/фронтенд).
- **Зависимости:** завершённые Stage 5 и 6 (ADR-005, ADR-006).
- **BC:** новая функциональность, существующее не затрагивается.
- **Gate:** A-01…A-03 зелёные; сценарии ADR-005 п.4 (частичная оплата, аванс, переплата, возврат, credit, отмена) покрыты тестами.
- Расчёты с маркетплейсом как задолженность и курсовые разницы в Stage 7 не входят (ADR-005 п.6, ADR-008 п.8).

## Stage 8 — Balance intake

- **Scope:** adapter «группа проводок → операция Balance» с `request_key
  =fincore:{group_id}`; конфигурируемый версионируемый маппинг
  `posting/account/category → Balance account/article/dimension`
  (`fincore_balance_mappings`, ADR-003 п.6); сверка B-01/B-02; режим
  `draft/suggestion` (`saveDraft`), затем автоматический `post` после
  подтверждения маппинга; передача суммы в валюте книги Balance (ADR-008 п.7).
- **Зависимости:** завершённые Stage 5 и 6 (ADR-003 п.6).
- **BC:** ручной ввод Balance остаётся; auto-операции помечены источником.
- **Gate:** B-01 = 0 в режиме `draft`; конфигурация маппинга подтверждена Владельцем как данные (не архитектурное решение) — переход на `auto`.

## Stage 9 — Reconciliation suite и replay tooling

- **Scope:** недостающие проверки `09 §4`, дашборд findings, `replay --execute` с
  аудитом, runbook, регулярные учения replay на копии данных.
- **Gate:** учебный replay по закрытому периоду на копии даёт те же суммы.

## Stage 10 — Cutover и вывод legacy

- **Scope:** ядро — источник истины; отключение записи в `pl_daily_totals`
  через `PLRegisterUpdater` (регистр становится проекцией ядра), удаление
  обходов Facade (`new Document` из Cash), вывод дублирующих cron-задач;
  устранение зависимости модулей от `Company::financeLockBefore` (период —
  только модель ADR-007).
  Необратимая миграция данных — только с бэкапом и явным упоминанием в
  «merge and deploy» (CLAUDE.md «Практики»).
- **Условие удаления legacy:** ≥ 2 закрытых периода без расхождений S-01/P-01/C-01/B-01
  при включённом чтении из ядра; откат проверен на копии.
- **Rollback:** до удаления кода — флаг чтения; после — восстановление из бэкапа
  (поэтому удаление кода — последним, отдельным PR).

## Сквозные требования ко всем Stage

- Тесты: Builder'ы, happy-path + негативный на каждый Action, регрессия на
  каждый баг, тесты идемпотентности `07 §6`.
- Миграции — с индексами по `(company_id, …)`; данные — по
  `docs/workflow/data-migrations.md`.
- Любая смена финансовых формул/знаков/категорий не молчаливая: явно в PR и
  согласована Владельцем.
- Каждый Stage обновляет `ARCHITECTURE.md` (Facade, Enum, Entity, Messenger routing).
