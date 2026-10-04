# 03. Текущая модель отказов и карта рисков

Метод: для каждого критичного потока — что происходит при падении после
коммита, при потере публикации, дубле доставки, падении воркера, повторе.
Все пункты получены чтением кода; места, не проверенные до конца, помечены.

## 1. Базовые свойства, определяющие все риски

| Свойство | Факт | Последствие |
|---|---|---|
| Нет outbox | `dispatch()` после `flush()` либо из `postPersist` внутри `flush()` | окно «коммит есть, сообщения нет» везде |
| Транспорт — Redis AOF everysec | копии сообщения в БД нет | рестарт/flush Redis теряет очередь; до ~1 с подтверждённых сообщений |
| Нет `doctrine_transaction` | handler сам решает границы | частичные записи при падении |
| `failed` пассивна | нет алерта/автоповтора | исчерпавшее ретраи сообщение «тихо» остаётся |
| Один воркер на транспорт | нет конкурентности | race-ы скрыты; масштабирование их проявит (R-09) |
| Locks в Redis с TTL | Redis-lock не переживает failover; TTL может истечь раньше handler'а | возможна параллельная обработка (R-10) |
| Duplicate delivery | Redis Streams + Messenger — at-least-once | handler'ы должны быть идемпотентны; часть — нет (см. ниже) |

## 2. Failure points по потокам

Обозначения: **L** — потеря обработки, **D** — дубль, **I** — рассинхронизация,
**Rec** — есть ли восстановление.

### 2.1 Marketplace

| # | Точка | Сбой | Эффект | Rec |
|---|---|---|---|---|
| G1 | `SyncWbFinancialReportDayHandler:316 flush` → `:318 dispatch(ProcessDayReport)` | kill/OOM между ними | день `processing`, сырьё загружено, обработки нет — **L** | **Закрыто Stage 1.1:** день `processing` старше 6 ч перехватывается `reclaimStaleProcessing()` (повторный `ProcessDayReportMessage`). `canClaimForQueue` по-прежнему возвращает false для `RAW_LOADED/PROCESSING`, его контракт не менялся. Примечание: у WB `raw_loaded` не сохраняется отдельно (выставляется вместе с `processing` одним flush), кроме legacy-reconcile |
| G2 | `ProcessDayReportHandler:59 flush` → цикл из 3 dispatch | смерть после 1–2 из 3 | raw-документ не достигает `COMPLETED`, sync-статус вечно `processing` — **L/I** | WB — **закрыто Stage 1.1** (перехват через 6 ч, повтор идемпотентен: sales/returns по srid, затраты — удаление открытых и пересоздание); Ozon by-day — grace 3600 с + cron |
| G3 | `claimForQueue` commit `queued` → `planner->dispatch` | потеря сообщения | `queued` без retry-time — **L** | Да, через 2 ч (`STUCK_RECLAIM_INTERVAL`) |
| G4 | flush страницы → `dispatchContinuation` | смерть между | пагинация остановилась — **L** | Да: `next_retry_at` + ежечасный orchestrate |
| G5 | Ozon by-day flush → dispatch | kill | день не обработан — **L** | Да: grace + 04:00 sync + rolling refresh |
| G6 | Ozon realization `markRawLoaded` → dispatch | kill | реализация не применена — **L** | Да: poll :17, catchup 05:30, гейты 07:20/07:25 |
| G7 | `MarketplaceController:166 flush` → `TriggerInitialSyncMessage` (Ozon) | смерть | подключение без истории — **L** | Частично (только последние дни) |
| G8 | `MonthCloseController:168` dispatch без записи состояния | потеря сообщения | клик «закрыть» пропал, состояния нет — **L** | Пользователь повторяет |
| G9 | `RebuildPreliminary…`: reopen (коммит) → close (другая транзакция) | падение между | PL-документы удалены, нового нет — **I** | Ретрай сообщения + 04:45 |
| G10 | `ReopenMonthStageAction`: удаление документов (flush на каждый) → снятие меток → save | падение между | висячие `document_id` или снятые метки при `CLOSED` — **I** | Нет |
| G11 | `ByDayRowReplacement` отвязывает строки от предварительного документа | между заменой и 04:45 | документ содержит суммы удалённых/перепривязанных строк — **I** | Следующий rebuild; контрольная сумма только на закрытии |
| G12 | WB-процессоры (flush батчами, DELETE затрат отдельным коммитом) | падение посреди | частичная запись — **I** | Ретрай безопасен (srid-дедуп, unique); после 3 ретраев — `failed_final`, без автоповтора |

Дубли (**D**): sales/returns/costs защищены unique-индексами (в т.ч. только в
миграциях, не в ORM — R-12); `marketplace_ozon_realizations` без unique — защита
Redis-lock + DELETE/recreate; `documents` без ключа идемпотентности — повторный
`createPLDocument` создаст второй документ (защита — workflow закрытия +
`markProcessed`).

### 2.2 Cash

| # | Точка | Сбой | Эффект | Rec |
|---|---|---|---|---|
| C1 | `postPersist` dispatch авто-правил внутри `flush()` (для переводов — внутри открытой транзакции) | rollback после dispatch / worker быстрее коммита | сообщение уйдёт, строки нет → handler логирует warning и **молча отбрасывает** | `DelayStamp(10s)` как единственная защита |
| C2 | commit → dispatch | kill | транзакция без категоризации — **L** | ручная кнопка/CLI; cron закомментирован |
| C3 | `CashTransactionService::add`: flush → матчер → пересчёт остатков | падение между | остатки/`current_balance` устарели — **I** | только позднейшие записи в тот же счёт или CLI |
| C4 | Alfa-импорт | не пересчитывает остатки/кэш/матчер, не проверяет период | остатки стареют до следующего пересчёта — **I** | нет по расписанию |
| C5 | Файловый импорт | батчи коммитятся, падение в позднем | частичный импорт + пересчёт остатков пропущен — **I** | job `failed`, повтор — загрузка заново (дедуп по `dedupe_hash`, но без unique-индекса) |
| C6 | `uniq_cashflow_import` включает мягко удалённые | удалённая импортированная строка блокирует повторный импорт | **L** | нет |
| C7 | Cash→P&L: flush документа → отдельный flush регистра | падение между | документ есть, регистра дня нет — **I** | `PLRegisterUpdater::recalcRange` вручную |
| C8 | Debounce lock 120 с | потеряно range-сообщение | строки в окне 120 с без правил — **L** | нет |

### 2.3 Finance / P&L

| # | Точка | Эффект |
|---|---|---|
| F1 | `PLRegisterUpdater`: DQL DELETE дня + upsert **без блокировки**; два пересчёта одного дня могут пересечься, читатель видит пустой/частичный день |
| F2 | Документ и регистр — разные коммиты в `CreatePLDocumentAction` (вне закрытия месяца), `DocumentController`, `SoftDelete/Restore/Delete…` |
| F3 | Регистр = сумма ACTIVE-документов только дисциплиной пересчёта; **нет ни гейта, ни health-check**, сравнивающего их |
| F4 | Тихий пропуск операций без проекта/категории/природы в `PLRegisterUpdater` — данные исчезают из регистра без ошибки |
| F5 | `abs()` после суточного неттинга: чистый минус по категории дохода превращается в плюс; регистр не хранит отрицательное |
| F6 | `float` в `PLRegisterUpdater`, `LoanScheduleToDocumentService`, `PLDailyTotalFactsProvider`, контрольной сумме закрытия (допуск 0.01) |
| F7 | Loan→P&L: повторный POST создаёт дубль; регистр не пересчитывается; `isPaid` не ставится |
| F8 | `financeLockBefore` не проверяется при записи Document/Loan-документов |

### 2.4 Balance

Внутренне самый надёжный контур (одна транзакция, `request_key`, CHECK-и,
составные FK). Риски: неизменяемость только на уровне приложения (нет
триггеров); `balance_account_states` — кэш, дрейф только по out-of-band записи;
нет планового reconcile (только при закрытии месяца и по запросу владельца);
**нет ни одного источника данных** → числа не сверяются с Cash/P&L.

### 2.5 Ingestion (эталон)

Защиты: content-hash raw-дедуп; естественный ключ `uniq_ftx_natural_key`;
`pending`-статус + sweeper каждые 10 мин; heartbeat + `reap-stale-jobs`;
subscriber «ретраи исчерпаны ⇒ FAILED»; Redis-lock rate-guard. Остаточные риски:
осиротевший объект в хранилище при падении между записью объекта и строкой (логируется,
не удаляется), нет транзакционной публикации (закрывается sweeper'ом).

## 3. Ответы на вопросы из ТЗ §3.3

| Вопрос | Ответ по текущему коду |
|---|---|
| Приложение упало после «saved» | Данные сохранены; последующая обработка теряется там, где нет sweeper'а: **WB (G1/G2), Cash (C2), Ozon initial (G7), закрытие месяца (G8)**. Покрыты: Ingestion, Ozon by-day/realization, WB `queued` |
| `publish` не выполнился | то же; исключение dispatch перехвачено только в Ozon by-day и Ozon realization |
| Duplicate delivery | безопасно для sales/returns/costs/raw/Ingestion/Balance; **небезопасно/неочевидно** для создания PL-документа, Loan→P&L, `succeeded_steps` (read-modify-write), Cash-импорта файлом (без unique на hash) |
| Падение воркера | Redis-сообщение возвращается по `redeliver_timeout` (дефолт, **не проверено**); незавершённые батчи WB — частичная запись |
| Можно ли повторить | Marketplace: да (`app:marketplace:reprocess`, кнопка, rebuild); Cash: пересчёт остатков/правил — да; P&L-регистр — `recalcRange`; Balance — `rebuildCurrentStates` |
| Появятся ли дубли | см. «Duplicate delivery» |

## 4. Реестр рисков

Приоритет: **P0** — потеря/искажение денег без сигнала; **P1** — рассинхрон с
восстановлением вручную; **P2** — гигиена.

| ID | Риск | Приоритет | Источник | Куда адресуется |
|---|---|---|---|---|
| R-01 | WB-день вечно в `raw_loaded/processing` после потери dispatch (G1, G2) | P0 | Marketplace | **Закрыт Stage 1.1** (reclaim, порог 6 ч); системное закрытие окна commit→dispatch — Stage 2/4 |
| R-02 | Нет outbox: окно commit→dispatch во всех потоках | P0 | сквозной | Stage 2 |
| R-03 | Redis как единственное хранилище очереди; `failed` без алерта/автоповтора | P0 | инфраструктура | Stage 1 (алерт), Stage 3 |
| R-04 | Нет проверки «pipeline дня завершён» перед закрытием месяца; 04:45 может закрыть неполный месяц; поздние строки закрытого месяца остаются с `document_id NULL` | P0 | Marketplace | Stage 1 (гейт), Stage 4 |
| R-05 | Регистр P&L: delete+insert без lock, документ и регистр в разных коммитах, нет reconcile (F1–F3) | P0 | Finance | Stage 5, 9 |
| R-06 | Reopen/rebuild не транзакционны (G9–G11) | P1 | Marketplace | Stage 4 |
| R-07 | Cash: остатки не пересчитываются в импортах, `add()` не транзакционен, нет cron-а (C3–C5) | P1 | Cash | Stage 1, 6 |
| R-08 | Нет сквозного reconcile между Cash, P&L, Balance; Balance без источников | P1 | сквозной | Stage 8, 9 |
| R-09 | `succeeded_steps` — read-modify-write без optimistic lock; безопасно только при одном воркере | P1 | Marketplace | Stage 4 |
| R-10 | Redis-lock с TTL: потеря lock'а → параллельная обработка; «lock не взят» = молчаливый пропуск | P1 | Marketplace/Ingestion | Stage 3 |
| R-11 | Float и двойная знаковая конвенция в P&L; `abs()` после неттинга | P1 | Finance | Stage 5 |
| R-12 | Unique-индексы returns/costs/raw только в миграциях, не в ORM; `marketplace_sales.uniq_marketplace_srid` без `company_id` | P1 | Marketplace | Stage 1 |
| R-13 | Нет идемпотентности создания Document (Finance, Loan, Cash→P&L) | P1 | Finance | Stage 5 |
| R-14 | `financeLockBefore` не защищает Finance/Loan/импорты Cash | P1 | сквозной | Stage 5 (ADR-007) |
| R-15 | Тихие пропуски в `PLRegisterUpdater` (нет проекта/категории) | P1 | Finance | Stage 5 |
| R-16 | Нет понятий Invoice/Bill/AR/AP; платёж не связан с обязательством | — (функциональный пробел) | домен | Stage 7 (ADR-005) |
| R-17 | Единая модель признания отсутствует | — | домен | Stage 4–5 (ADR-006) |
| R-18 | Debug-маркеры `DBG:` в `errorMessage` пользователя и flush на каждой стадии в `CashFileImportHandler` | P2 | Cash | Stage 1 |
| R-19 | Telegram `occurredAt` = серверное «сейчас», а не время сообщения | P2 | Cash | Stage 6 |
| R-20 | `CashTransactionToDocumentService` создаёт `Document` в обход Facade | P2 | границы модулей | Stage 5 |
| R-21 | Одна общая Redis-инстанция (очереди+локи+кэш+сессии); нет maxmemory/eviction-политики | P1 | инфраструктура | Stage 3 |
| R-22 | `ingest_*`-транспорты не изолированы от `async_*` (общий DSN) | P2 | инфраструктура | Stage 3 |
| R-23 | Дрейф `ARCHITECTURE.md` «Cron-задачи» относительно `app.cron` | P2 | документация | Stage 1 |
| R-24 | Нет `CompanyFilterMiddleware`-защиты для остальных сообщений | P2 | tenancy | Stage 3 |

Не проверено (честно): `redeliver_timeout`/`claim_interval` Redis-транспорта
(приняты дефолты); `WbFinancialReportReconciliationService`, `WbGeneratedRowsSafeReplaceService`
и `MarketplaceListingLinkingFacade` не читались; Redis `maxmemory`.
