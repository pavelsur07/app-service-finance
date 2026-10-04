# 02. Карта финансовых потоков (текущее состояние)

Формат каждого сценария: `Business Action → Data changed → Queue/Event → Worker → Financial records`.

## 0. Что из ТЗ существует в коде

Проверено grep по `src/` и `migrations/` (дебитор, кредитор, счёт, инвойс, акт,
предоплата, invoice, bill, receivable, payable, debt).

| Понятие ТЗ | Статус | Где / что есть вместо |
|---|---|---|
| Revenue Recognition | **есть, только маркетплейсы** | `CloseMonthStageAction` → `marketplace_pl` документ на конец периода |
| Expense Recognition | есть частично | затраты маркетплейса — тот же месяц-клоуз; Cash→P&L по кнопке (дата оплаты); Loan→P&L по кнопке (дата графика) |
| Invoice (счёт/акт/УПД) | **нет** | `DocumentType::DEAL_SALE` объявлен, продюсера нет; `Deal` — не документ расчётов |
| Vendor Bill | **нет** | — |
| AR / AP | **нет** | у `Counterparty` нет сальдо; «кредитор» — только подпись формы Loan |
| Customer Payment | частично | `CashTransaction` INFLOW + контрагент + категория, **без привязки к документу** |
| Vendor Payment | частично | `CashTransaction` OUTFLOW, то же |
| Bank transaction | есть | `CashTransaction`; банк API только **Alfa** (`BankImportHandler` match `'alfa'`) |
| Refund | частично | возвраты маркетплейса (`marketplace_returns`, `REV_*_RETURNS`); `DealAdjustment::RETURN` меняет только `Deal.totalAmount` |
| Prepayment | **нет** | — |
| Partial payment | частично | `CashTransaction.allocatedAmount` — доля транзакции, разнесённая по P&L-документам; не частичное погашение счёта |
| Invoice cancellation / correction | **нет** | для P&L — `ReopenMonthStageAction` (удаление документов) и мягкое удаление `Document` |
| Cash flow | есть | `money_account_daily_balance` |
| Balance update | есть, **изолированно** | `BalanceLedgerService`, ручной ввод |

**Вывод:** сценарии Invoice/AR/AP/Vendor Bill/Prepayment/Cancellation в
Stage 0 описываются как *целевые* (см. `04`, `06`); «текущий» поток для них
отсутствует. Это не пробел анализа, а свойство системы.

## 1. Revenue recognition — маркетплейс (основной)

```text
cron 03:10 / :20 (WB), 04:00 (Ozon)  или  кнопка «синхронизировать»
→ marketplace_financial_report_sync_statuses (claimForQueue: advisory-lock + commit)
→ SyncWbFinancialReportDayMessage [async_wb_finance] / SyncOzonAccrualByDayMessage [async_sync]
→ Sync*Handler: Redis lock, API, marketplace_raw_documents (+ flush)
→ dispatch ProcessDayReportMessage [async_pipeline]
→ ProcessDayReportHandler: сброс статуса, flush, 3× dispatch ProcessRawDocumentStepMessage (SALES, RETURNS, COSTS)
→ ProcessRawDocumentStepMessageHandler → ProcessMarketplaceRawDocumentAction → processor
→ marketplace_sales / marketplace_returns / marketplace_costs (document_id = NULL)
→ raw_document.succeeded_steps; 3 шага ⇒ COMPLETED; sync status ⇒ success
… (позже, вручную из UI или cron 04:45 «предварительно»)
→ CloseMonthStageMessage / RebuildPreliminaryForPeriodMessage [async_pipeline]
→ CloseMonthStageAction: ОДНА транзакция + advisory lock
      FinanceFacade::createPLDocument → documents(marketplace_pl, date = конец периода) + document_operations
      PLRegisterUpdater → pl_daily_totals (DELETE дня + upsert)
      MarkProcessedQuery → marketplace_*.document_id
      marketplace_month_closes.closeStage
```

Ozon-«Реализация»: `SyncOzonRealizationMessage` → `marketplace_raw_documents(realization)`
→ `ProcessOzonRealizationMessage` → `marketplace_ozon_realizations` (DELETE+recreate под
Redis-lock) → попадает в P&L через `RealizationDataSource` на шаге закрытия.

Особенности: признание на конец периода; выручка/COGS берутся из строк
(`cost_price × qty` зафиксирован в момент обработки продажи, не переоценивается);
затраты попадают в P&L только при наличии маппинга (`include_in_pl`).

## 2. Expense recognition

| Источник | Поток | Дата признания |
|---|---|---|
| Затраты маркетплейса | как в §1, `CloseStage::COSTS` → поток `COSTS` | конец периода |
| Cash → P&L | кнопка → `CreateDocumentFromTransactionAction` → `FinanceFacade::createDocumentFromCashTransaction` → `flush` → `PLRegisterUpdater` (отдельный коммит) | `CashTransaction.occurredAt` (платёж) |
| Cash → P&L (вариант) | `CashTransactionToDocumentService` (`new Document`, обход Facade; `beginTransaction`) | то же |
| Loan → P&L | кнопка → `LoanScheduleToDocumentService` → `Document(LOANS)` (flush, **регистр не пересчитывается**) | `dueDate` графика; сумма = % + комиссия (+ тело при флаге) |
| Ручной документ | `DocumentController` | введённая дата |

Единого правила признания нет (целевое правило — ADR-006). Тип документа у Cash→P&L всегда
`CASHFLOW_EXPENSE`, включая поступления.

## 3. Bank transaction

```text
(a) Alfa API:   кнопка/CLI cash:bank:enqueue → BankImportMessage [async_sync] → BankImportService
                 → CashTransaction (persist, flush по дню; курсор BankImportCursor)
(b) Файл XLS/CSV: контроллер → CashFileImportJob(commit) → CashFileImportMessage [async_sync] → CashFileImportService (батчи)
(c) 1С:         синхронно в HTTP → ClientBank1CImportService
(d) Telegram:   webhook → CreateTelegramCashTransactionAction → CashFacade::createTransaction (полный путь)
(e) UI:         CashTransactionService::add (полный путь)
```

Полный путь (`CashTransactionService::add`): проверка периода → `flush` (в нём
`postPersist`: аудит + `dispatch` авто-правил с `DelayStamp(10s)`) →
`PaymentPlanMatcher` → `DailyBalanceRecalculator` (транзакция + advisory lock) →
инвалидация кэша снапшотов. **Импорты (a)(b)(c) пропускают** пересчёт остатков,
матчер, инвалидацию кэша; (a)(b)(c) не проверяют `financeLockBefore`.

Авто-правила: `postPersist` → `DebouncedRangeEnqueuer` (Redis lock 120 с) →
`EnqueueAutoRulesForRange` / `ApplyAutoRulesForTransaction` [async_pipeline] →
меняют категорию/контрагента/сплиты + аудит. P&L и остатки не затрагиваются.

## 4. Customer / Vendor Payment, AR, AP, Prepayment, Partial payment

Отдельных потоков нет. Эквивалент «платёж клиента» = (3) + категория ДДС.
Связь платёж → обязательство не хранится. «Кто кому должен» из данных
вывести нельзя. `PaymentPlan`/`payment_plan_match` — календарь планируемых
платежей (прогноз), сопоставляется по сумме/категории/контрагенту.

## 5. Refund

Только маркетплейсные возвраты: `ProcessRawDocumentStepMessage(RETURNS)` →
`marketplace_returns` → закрытие месяца → `REV_*_RETURNS` в P&L (знак +X
в категории возвратов, `marketplace_pl`).

## 6. Invoice cancellation / correction

P&L: `ReopenMonthStageAction` — удаление PL-документов (`FinanceFacade::deletePLDocument`,
по flush на документ) → снятие `document_id` → стадия `REOPENED` → повторное закрытие.
Не транзакционно (см. R-06). Cash: мягкое удаление/восстановление транзакции.
Balance: `reverse()` — сторно-операция (единственный полноценный механизм коррекции).

## 7. Balance update

```text
пользователь (UI) → BalanceLedgerService.saveDraft/post/reverse
→ ОДНА транзакция: lock компании → books FOR UPDATE → balance_operations + lines → balance_account_states → audit
```

Входящих интеграций нет. Идемпотентность: `UNIQUE(company_id, request_key)` +
`request_hash` (тот же ключ, другой payload ⇒ 409).

## 8. Cash flow / остатки

`AccountBalanceService::recalculateDailyRange` (транзакция + advisory lock) →
`money_account_daily_balance` + `money_account.current_balance`. Запускается из
записывающих путей полного потока и CLI `DailyBalanceRecalcCommand`; по расписанию
не запускается.
