# Технический план рефакторинга Cash без изменения импорта 1С

> Исполнителю: применять `executing-plans` либо `subagent-driven-development`
> с приоритетом workflow AGENTS.md. Work item не является Stage: без отдельного
> внешнего review, PR update или owner gate после каждого пункта.

**Goal:** удалить доказанный dead code, разложить код по контекстам и разделить
файловый импорт/автоправила с сохранением поведения и полной защитой пути 1С.

**Architecture:** сохранить существующие слои Cash и Facade boundary. Контексты
добавляются внутри Application и Application/Service; файловый I/O располагается
в Infrastructure. Транзакции остаются у существующих Action/Handler/Command.

**Tech Stack:** PHP/Symfony, Doctrine/PostgreSQL, Messenger, OpenSpout, PHPUnit;
текущие версии проекта, без новых зависимостей.

**Spec:** [TASK.md](TASK.md). **Acceptance:** [acceptance.md](acceptance.md).
**Статус:** четыре Stage выполнены. Ниже сохранён исходный план; фактические результаты и исключения — в [acceptance-results.md](acceptance-results.md), [gate-corrections.md](gate-corrections.md) и [handoff.md](handoff.md).

## Общие ограничения

- Все требования FR-1…FR-5 и NFR действуют на каждый Work item.
- Импорт 1С, защищённые общие зависимости и их тесты/fixtures неизменны.
- Механические переносы не совмещать с выделением обязанностей в одном коммите.
- Изменение CSV-диалекта и удаление методов балансов исключены из этой реализации.
- DTO, сообщения, данные jobs/plans, Facade API и финансовые правила сохраняются.
- Не создавать новую абстракцию, если нужный компонент уже существует.

## Фокус ревью

| Риск | Доказательство и владеющий Work item |
|---|---|
| Перенос общей зависимости косвенно меняет 1С | Protected diff, граф DI и неизменённые тесты 1С: 0.2, 2.1, каждый Stage |
| Повтор/падение на границе batch меняет число операций | 199/200/201 строк, resume и перестановка дублей: 3.1, 3.3 |
| Выделенный reader меняет approved description plan | Golden row IDs/input/context, CSV legacy modes и lifecycle generator: 3.1, 3.2 |
| Новый matcher меняет ручные назначения или разрешает чужую компанию | Field/operator matrix, company scope, provenance и пары ЦФО: 4.1, 4.2 |
| Preview расходится с применением или делает N+1 | Сравнение планов/изменений и число repository/Facade вызовов: 4.1, 4.3 |

## Phase 0 перед реализацией

- [ ] 0.1. Создать отдельную implementation branch от актуального master с этими
  документами. Не продолжать docs-only PR кодом. Зафиксировать полный
  `task_base_commit` в новом `checkpoint.md`; рабочие изменения владельца сохранить.
- [ ] 0.2. Сверить manifest с текущими импортами 1С и потребителями переносимых
  классов. Дополнить защиту при необходимости. При пересечении убрать перенос из
  scope и записать исключение; не редактировать защищённый caller и не вводить alias.
- [ ] 0.3. Повторить поиск кандидатов FR-2: exact class/method names, tracked
  source/config/templates/tests/scripts, interfaces/tags/attributes. Записать
  результат в baseline, включая единственные test-only вызовы wrapper.
- [ ] 0.4. Прочитать Cash README, разделы Cash в ARCHITECTURE, PATTERNS §3/§7/§14/§22;
  сопоставить с существующими `CashFileRowNormalizer`, `CashFileDescriptionMatcher`,
  `CashTransactionAutoRuleTargetValidator` как образцами выделенных обязанностей.
- [ ] 0.5. Подготовить локальное test-окружение по Makefile без production credentials.
  Проверить, что контейнер использует именно checkout реализации. Выполнить baseline
  наборов B0–B3 ниже; записать команды, counts и pre-existing failures.
- [ ] 0.6. Создать `checkpoint.md` со статусом planned, baseline, next action Stage 1;
  записать `stage_base_commit` и DoD перед первым Work item каждого Stage.

Красный baseline сам по себе не блокирует работу: точечные проверки и объяснение
старых ошибок обязательны. Ни один пункт плана не считается выполненным без вывода.

## Stage 1 — удалить подтверждённый неиспользуемый код

**Risk:** LOW. **DoD:** FR-2 выполнен, protected diff пустой, набор B1 и unit
автоправил проходят; проверки поведения не удалены. **stage_base_commit:** записать при старте.

- [ ] 1.1. Удалить `site/src/Cash/Service/Import/Identity/CashTransactionExternalIdGenerator.php`.
  После удаления повторить exact-name поиск вне исторической документации.
- [ ] 1.2. В `site/src/Cash/Service/Transaction/CashTransactionToDocumentService.php`
  удалить `createOperationFromTransaction()` и только неиспользуемые после этого imports.
  Проверить `CashTransactionToDocumentServiceTest`; helpers живого `createDocument()` оставить.
- [ ] 1.3. В `site/tests/Unit/Cash/Service/Transaction/CashTransactionAutoRuleServiceTest.php`
  заменить две проверки wrapper на тот же результат `match($transaction)->rule`.
  Выполнить на старом сервисе; удалить wrapper из сервиса и выполнить повторно.
- [ ] 1.4. Проверить полный Stage diff, отсутствие новых caller gaps, protected paths.
  Создать Stage Report, commit, push и один Draft implementation PR на master.

**Checks:** B1 + unit `CashTransactionAutoRuleServiceTest`; focused PHP lint/static
analysis; protected paths. **Reviewer focus:** не спутать test-only wrapper с
динамически вызываемыми сервисами; не удалить балансовые методы или helpers ОПиУ.

## Stage 2 — механическая раскладка по контекстам

**Risk:** MEDIUM. **DoD:** разрешённые переносы из карты выполнены, контейнер собирается,
публичные/защищённые FQCN неизменны; module tests проходят. **stage_base_commit:** при старте.

### Карта Action

Источник каждого имени ниже: `site/src/Cash/Application/<Name>.php`.
Назначение: `site/src/Cash/Application/<Context>/<Name>.php`; имена приведены без `.php`.

| Context | Классы |
|---|---|
| Transaction | AddCashTransactionAction, UpdateCashTransactionAction, DeleteCashTransactionAction, RestoreCashTransactionAction, BulkDeleteCashTransactionsAction, SaveCashTransactionSplitsAction, RecalculateAllocatedAmountsAction |
| Transfer | CreateCashTransferAction, CashTransferLifecycleAction |
| Accounts | SaveMoneyAccountAction, EnsureDefaultMoneyAccountsAction |
| Funds | SaveMoneyFundAction, CreateMoneyFundMovementAction |
| Category | SaveCashflowCategoryAction, DeleteCashflowCategoryAction, EnsureCashflowSystemCategoriesAction, ApplyCashflowCoreTemplateAction, CashflowCoreTemplateReader |
| Document | CreateDocumentFromTransactionAction, CreatePnlDocumentFromTransactionAction |
| AutoRule | ApplyAutoRuleToTransactionAction, AssignGeneralCfoToCashAutoRulesAction, ChangeCashTransactionAutoRuleActivityAction, DeleteCashTransactionAutoRuleAction, EnqueueCashAutoRulesAction, SaveCashTransactionAutoRuleAction |
| AiCategorization | AcceptCashCategorySuggestionAction, AutoApplyCashCategorySuggestionsAction, DismissCashCategorySuggestionAction, RequestCashAiCategorizationAction |
| PaymentPlan | ChangePaymentPlanStatusAction, CreatePaymentPlanAction, MaterializeRecurringPaymentPlansAction, PostponePaymentPlanAction, UpdatePaymentPlanAction |
| Import/File | ApplyCashFileDescriptionsAction, ConfirmCashFileImportAction, CreateCashFileImportProfileAction, DeleteCashFileImportProfileAction, DispatchCashFileImportJobAction, PrepareCashFileImportAction, StartCashFileImportJobAction, UpdateCashFileImportProfileAction, UploadCashFileImportAction |
| Import/BankStatement | ImportBankStatementDayAction |
| Import/Recovery | CloseLostCashFileImportJobsAction, CloseOpenImportLogsAction |

`ImportBank1CStatementAction` остаётся в корне Application. Если обновление ссылки
на любой из остальных Action потребует изменения protected file, такой Action
также оставить на месте и указать причину в карте фактических переносов.

### Карта сервисов

| Источник относительно `site/src/Cash/` | Назначение |
|---|---|
| Application/Service/CashCategorization{PayloadMapper,ResultConsumer}.php; CashCategorySuggestionApplier.php | Application/Service/AiCategorization/ |
| Application/Service/AutoRuleDispatchGuard.php; CashTransactionAutoRule{Prefiller,ProvenanceResolver,TargetValidator}.php | Application/Service/AutoRule/ |
| Application/Service/CashflowCategoryTreeBuilder.php | Application/Service/Category/ |
| Application/Service/DailyBalanceRecalculator.php | Application/Service/Accounts/ |
| Application/Service/DebouncedRangeEnqueuer.php | Application/Service/AutoRule/ |
| Service/PaymentPlan/*.php | Application/Service/PaymentPlan/ |
| Service/Category/*.php | Application/Service/Category/ |
| Service/Transaction/CashTransaction{Service,SplitSynchronizer}.php | Application/Service/Transaction/ |
| Service/Transaction/CashTransactionToDocumentService.php | Application/Service/Document/ |
| Service/Transfer/EffectiveExchangeRateCalculator.php | Application/Service/Transfer/ |
| Service/Vat/*.php | Application/Service/Transaction/Vat/ |
| Service/Accounts/{AccountBalanceProvider,FundBalanceService,MoneyTotalsWidgetProvider}.php | Application/Service/Accounts/ для счетов; Application/Service/Funds/ для FundBalanceService |

Protected classes не переносятся. `CashTransactionAutoRuleService` и весь
`Service/Import/File/` оставляются до Stage 3/4. Таблица не разрешает создавать
параллельные реализации при обнаруженном пересечении с FR-1.

- [ ] 2.1. Для каждой строки карты получить references, исключить protected callers,
  записать точные source → destination в checkpoint/Stage Report; выполнить Action moves.
- [ ] 2.2. Выполнить service moves, исправить только namespace/import/type/config references.
  Сохранить теги consumer и autowiring. Проверить ссылки в PHPStan baseline: менять
  путь/namespace допустимо только без роста числа подавленных ошибок и ослабления правил.
  В смешанных тестах Cash/Settlement допустима правка import перенесённых Action;
  существующие сценарии и assertions пути 1С не менять. Pure 1С tests защищены manifest.
- [ ] 2.3. Обновить Cash README и раздел размещения ARCHITECTURE; описать сохранённые
  legacy исключения 1С. Тестовые файлы не переносить ради симметрии, обновить их imports.
- [ ] 2.4. Выполнить container lint, module набор B0, protected check, complete-diff review;
  Stage Report, commit, push, Draft PR update; автоматически перейти к Stage 3.

**Reviewer focus:** относительные `Service\\...` обращения внутри Application,
PHPDoc type imports, static references, DI tags, чужие модули и serialized FQCN.

## Stage 3 — разделить файловый импорт с сохранением поведения

**Risk:** HIGH-LOCAL. **DoD:** критерии C1–C11 выполнены; coordinator сохраняет запись
пачек, а file access, rows и quota имеют отдельные обязанности. **stage_base_commit:** при старте.

### Файлы и интерфейсы

Перенести существующие `Service/Import/File/{CashFileImportService,CashFileRowNormalizer,
CashFileDescriptionMatcher,CashFileDescriptionPlan,HeaderAutoMapper}.php`
в `Application/Service/Import/File/`, а `FileTabularReader.php` — в `Infrastructure/Import/File/`.
Сначала выполнить перенос отдельным коммитом, затем извлечение компонентов.
`Service/Import/File/CashFileMappingValidator.php` и `Http/CashFileImportPage.php`
не изменять: через Page валидатор входит в граф зависимостей контроллера 1С.
Новые файловые компоненты продолжают использовать валидатор по прежнему FQCN.

Новые компоненты, без интерфейсов-обёрток:

| Файл относительно `site/src/Cash/` | Контракт и ответственность |
|---|---|
| Infrastructure/Import/File/CashFileStorage.php | `storageKey(string $fileHash, string $extension = ''): string` (static), `withReadablePath(string $path, Closure $operation): mixed`, `withJobFile(CashFileImportJob $job, Closure $operation, bool $readable = true): mixed`; извлечь нынешний storage fallback, TemporaryLocalFile и XLS conversion без смены алгоритма |
| Application/Service/Import/File/CashFileNormalizedRows.php | `iterate(string $path, array $mapping, string $currency, ?array $allowedRows = null): Generator`; отдаёт прежние `{rowNumber,row,normalized}`; использует FileTabularReader и существующий CashFileRowNormalizer |
| Application/Service/Import/File/CashFileDuplicatePlanner.php | `plan(string $path, CashFileImportJob $job): array` с прежним shape `array<string,array<string,int>>`; `hash(string $companyId, string $moneyAccountId, DateTimeImmutable $occurredAtUtc, int $amountMinor, string $purposeRaw): string`; перенос существующих plan/allocate/hash без изменения результата |

У `FileTabularReader` добавить `readImportRows(string $filePath): Generator`, отдающий
`{rowNumber:int,row:array<string,?string>}` по прежнему import reader. Путь уже должен
быть читаемым; не возвращать lazy generator за пределы callback, удаляющего временный
файл. `readHeader()`/`readSampleRows()` и их legacy CSV-алгоритм остаются прежними;
import reader сохраняет отдельный алгоритм. Общие normalizeValue/OpenSpout lifecycle
переиспользовать без изменения результатов. Не вводить configurable parser strategy.

`CashFileImportService` остаётся coordinator: `import(job, commitBatch, allowedRows)`,
`normalizedRows(path,mapping,currency)`, `planInput(normalized,rowNumber)` и
`recalculateCommittedRows(job)` сохраняют сигнатуры. Состояние allowedRows передаётся
явно в row component; quota/cache cleanup и ownership UnitOfWork остаются прежними.
Storage calls переключить на CashFileStorage; удалить старые storage wrappers,
когда их действующие потребители переведены, включая static/PHPDoc ссылки.

- [ ] 3.1. Добавить characterization cases C1–C11 в существующие File unit/integration
  tests до изменений; fixtures синтетические, без production payload. Зафиксировать
  golden arrays для чтения/плана и DB итог для повторов/пачек; выполнить на старом коде.
- [ ] 3.2. Перенести файлы и выделить FileStorage/reader/NormalizedRows. Добавить
  `tests/Unit/Cash/Infrastructure/Import/File/FileTabularReaderTest.php` для режимов
  чтения и cleanup. Перевести файловые controllers/actions/plans на новые зависимости.
- [ ] 3.3. Выделить DuplicatePlanner; новый
  `tests/Unit/Cash/Application/Service/Import/File/CashFileDuplicatePlannerTest.php`
  проверяет прежние hash и распределение квот через публичный результат `plan()`.
  Сохранить commit callback и mutation orchestration в текущем coordinator/handler.
- [ ] 3.4. Прогнать B2 и неизменённый B3, protected check; сравнить golden outputs и
  DB результат. Проверить отсутствие роста запросов на строку относительно baseline.
- [ ] 3.5. Complete-diff internal review, fix cycle, Stage Report, commit/push/PR update.
  Если Stage diff >500 строк — один external review по AGENTS.md §7.2, после чего Stage 4.

**Reviewer focus:** lifetime generator/temp file; old jobs/plans; неизменность dedupe
bytes; порядок блокировок/commit; атомарность description/audit/progress; 1С без diff.

## Stage 4 — разделить автоправила и выполнить handoff

**Risk:** HIGH-LOCAL. **DoD:** D1–D7 и все общие критерии приёмки выполнены;
нет открытых BLOCKER/IMPORTANT. **stage_base_commit:** при старте.

Перенести `Service/Transaction/CashTransactionAutoRuleService.php` в
`Application/Service/AutoRule/` отдельным коммитом. Новые компоненты там же:

| Файл | Контракт и ответственность |
|---|---|
| CashAutoRuleMatcher.php | `match(CashTransaction $transaction, array $rules): CashTransactionAutoRuleMatchResult`, `matches(CashTransactionAutoRule $rule, CashTransaction $transaction): bool`, `getSkipReason(CashTransaction $transaction): ?CashTransactionAutoRuleSkipReason`; существующие условия, scope, specificity и resolveMatch, без DB/flush |
| CashAutoRuleApplicationPlanner.php | `create(CashTransactionAutoRuleMatchResult $match, CashTransaction $transaction, ?array $activePairs = null, CashTransactionAutoRuleApplyMode $mode = SAFE, ?CashTransactionAutoRuleProvenance $provenance = null): CashTransactionAutoRuleApplicationPlan`; прежняя логика createApplicationPlan/planPair; `requiresPairSnapshot(array $rules): bool` для общего batch решения, без мутации сущностей |
| CashAutoRulePreviewBuilder.php | `forTransaction(CashTransaction $transaction): array`, `forRule(CashTransactionAutoRule $rule, iterable $transactions, int $limit): CashTransactionAutoRulePreviewResult`; прежние preview, labels и breakdowns, используя matcher/planner и один snapshot правил/пар |

В таблице краткие имена существующих Cash DTO/Enum/Entity; значение `SAFE` —
`CashTransactionAutoRuleApplyMode::SAFE`. Array shapes сохранить из текущих PHPDoc,
не вводить новый публичный формат. Coordinator сохраняет `match`, `applyRule`,
`applyMatch`, `createApplicationPlan`, `previewTransaction`, `previewRule`,
`getSkipReason` с текущими сигнатурами; делегирует вычисления и применяет готовый план.
PreviewBuilder не зависит от coordinator, чтобы не создать цикл DI.

- [ ] 4.1. До выделения закрепить D1–D6 в `CashTransactionAutoRuleServiceTest`,
  `CashTransactionAutoRuleTargetValidatorTest` и handler/controller tests. Проверить
  equality preview/apply plan и фиксированное число batch repository/Facade calls.
- [ ] 4.2. Перенести coordinator и выделить Matcher/Planner, используя существующие
  DTO и provenance resolver. Компонентные проверки разместить в
  `tests/Unit/Cash/Application/Service/AutoRule/{CashAutoRuleMatcherTest,CashAutoRuleApplicationPlannerTest}.php`.
- [ ] 4.3. Выделить PreviewBuilder; `CashAutoRulePreviewBuilderTest.php` в той же
  папке проверяет D4/D6. Прогнать профильные AutoRule тесты и B3; protected check.
- [ ] 4.4. Обновить фактическую карту размещения, checkpoint и Stage Report.
  Провести Stage complete-diff review и требуемый external review (>500 строк);
  если это одновременно handoff того же diff, не дублировать один и тот же review point.
- [ ] 4.5. Выполнить H1 full gates один раз, финальный internal review в свежей
  сессии по task spec + full diff + checklist. Исправить findings и повторить
  только необходимые проверки. Провести обязательный handoff external review.
- [ ] 4.6. Заполнить `acceptance-results.md` и `handoff.md`, включая сохранённый
  legacy 1С, deferred balance methods/CSV convergence, результаты и review metrics.
  Commit/push, PR Ready, единственный запрос «merge and deploy» по AGENTS.md §3.2.

## Команды проверок

Команды ниже для реализации, не для проверки этого docs-only пакета. Использовать
подготовленный локальный Compose project; `site-php-fpm` должен быть запущен и
монтировать checkout реализации. Для повторных запусков использовать `exec`, а не
новый контейнер на каждый тест. Подготовку БД выполнять по Makefile один раз.

### B0 — baseline и module set

```bash
docker compose exec -T -w /app -e APP_ENV=test -e XDEBUG_MODE=off site-php-fpm php bin/phpunit -c phpunit.xml --filter 'App\\Tests\\(Unit|Integration|Functional)\\Cash\\'
```

Baseline включает также B1, B2 и B3: часть их проверок находится вне Cash namespace.

### B1 — создание документов и dead-code cleanup

```bash
docker compose exec -T -w /app -e APP_ENV=test -e XDEBUG_MODE=off site-php-fpm php bin/phpunit -c phpunit.xml --filter 'CashTransactionToDocumentServiceTest|CreatePnlDocumentFromTransaction|CashTransactionAutoRuleServiceTest'
```

### B2 — файловый импорт и shared isolation

```bash
docker compose exec -T -w /app -e APP_ENV=test -e XDEBUG_MODE=off site-php-fpm php bin/phpunit -c phpunit.xml --filter 'CashFile|FileTabularReader|AccountStrictMatchTest|CashCompanyIsolationTest'
```

### B3 — защищённая регрессия 1С

```bash
docker compose exec -T -w /app -e APP_ENV=test -e XDEBUG_MODE=off site-php-fpm php bin/phpunit -c phpunit.xml tests/Integration/Cash/Service/Import
docker compose exec -T -w /app -e APP_ENV=test -e XDEBUG_MODE=off site-php-fpm php bin/phpunit -c phpunit.xml --filter 'AccountStrictMatchTest|CashCompanyIsolationTest|SettlementSourceMutationGuardTest'
```

Каталог включает ParserTest, IdempotencyTest, SignAndDateTest, InternalTransferTest,
AccountLockTest, CashTransactionRepositoryTest и ClientBank1C*; фильтр только по
строке `ClientBank1C` не покрывает все эти тесты. Вложенные File tests допустимо
прогнать одним этим набором вместо отдельного повторного B2, если покрытие совпадает.

### Container и focused checks

```bash
docker compose exec -T -w /app -e APP_ENV=test site-php-fpm php bin/console lint:container
docker compose exec -T -w /app -e APP_ENV=test site-php-fpm php bin/console debug:container --tag=app.service_gateway.ai_task_result_consumer
```

Во втором выводе должен присутствовать перенесённый `CashCategorizationResultConsumer`.

На Work item: конкретный PHPUnit test/filter + `php -l` изменённых PHP-файлов
в том же контейнере + PHPStan на изменённых файлах с проектной конфигурацией.
На Stage: соответствующие наборы выше, complete-diff review и protected-path check.
Не использовать `--filter`, вернувший zero tests, как успешную проверку.

### H1 — единственный полный handoff

```bash
make site-stan
make site-cs-check
make site-cs-strict-types
make site-test-unit
make site-test
```

Перед external review прочитать `docs/workflow/external-review.md`, вызвать
`site/bin/external-review.sh <review_base_commit> --effort medium` с контекстом
исключения 1С и уже закрытых findings; соблюдать маркер/бюджет/handling из AGENTS.md.
Документы подготовки не требуют external review; будущая Large-реализация требует.
