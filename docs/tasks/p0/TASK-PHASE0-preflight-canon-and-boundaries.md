# TASK-PHASE0 — Pre-flight pack для COGS-обогащения (TASK-FIX-06)

## 0. Сводка

- **Бизнес-цель.** Подготовить ядро Ingestion к запуску блока COGS-обогащения (`TASK-FIX-06`). Сделать канонический слой `FinancialTransaction` детерминированным (одни и те же данные источника → один и тот же ID, без дублей при повторной загрузке), укрепить границы модулей (Facade не утекает Entity), ввести точку входа `PnlFacade` для будущего пересчёта P&L, починить ретраи синхронизации. Без правок этой основы обогащение унаследует существующие дефекты с кратным усилением (одна транзакция = одна COGS-запись = один пересчёт периода).
- **Модуль.** Основной — `App\Ingestion`. Создаётся скелет `App\Finance` (только `PnlFacade`, физического переноса данных нет).
- **Тип.** refactor + integration prep.
- **Ветка.** `feature/ingestion-preflight-cogs`.
- **Подзадачи.** B1 детерминизм fallback `accrualId` · B2 канонизация порядка строк raw payload · B3 sourceHash в `UpsertFinancialTransactionAction` · B4 NormalizationCompletedEvent не публикуется впустую · B5 `IngestionFacade::getTransactions` отдаёт DTO · B6 модуль `App\Finance` + `PnlFacade` · B7 idempotency через ловлю unique violation · B8 ретраи `RunSyncChunkHandler`.
- **Затрагивает другие модули.** `App\Finance` (создаётся), `App\Marketplace`/legacy-потребители `IngestionFacade::getTransactions` (контракт меняется — текущий потребитель только Finance/rebuildPeriod).
- **Требует миграции БД.** Нет. Все изменения — code-only.
- **Меняет публичный HTTP API.** Нет.

---

## 1. Контекст и границы

### 1.1 Текущее состояние

**P0.1 — fallback `accrualId` зависит от порядка строк.**
`App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper::accrualId` (`:389-399`) для строк без `accrual_id` строит ID как `'fallback-{rowIndex}-{sha256(row)[0:16]}'`. `rowIndex` — порядок в ответе Ozon (произвольный). На повторных fetch'ах одна и та же логическая строка получает разный fallback → разный `operationGroupId` (UUIDv5 от него) → разный `sourceKey` → разный `externalId` → дубли в `ingest_financial_transactions`.

**P0.2 — `externalUpdatedAt = $rawRecord->getFetchedAt()`.**
`App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper::map` (`:48`) и `App\Ingestion\Application\Source\Wildberries\WbFinanceSalesReportDetailedMapper::map` (`:48`) подставляют `fetchedAt` как версию данных. Любой повторный fetch (включая нормальную работу `hot rewind` при первичном запуске) → `externalUpdatedAt` выше предыдущего → `FinancialTransaction::replaceFromNewerVersion` срабатывает → запись в БД переписывается → `NormalizationCompletedEvent` с `AffectedPeriod` → каскад пересчётов.

**P0.3 — hash raw payload неустойчив к перестановке строк.**
`App\Ingestion\Infrastructure\Storage\RawNdjsonCodec::encodeRows` (`:14-30`) делает `ksort` для ключей внутри row, но top-level список строк сохраняется в порядке поступления. Ozon/WB возвращают данные в недетерминированном порядке. Результат: новый hash → новый `IngestRawRecord` → лишний прогон нормализации + перерасход storage.

**P0.4 — `IngestionFacade` отдаёт Entity наружу.**
`App\Ingestion\Facade\IngestionFacade::getTransactions` (`:44-51`) возвращает `iterable<App\Ingestion\Entity\FinancialTransaction>`. Внешние модули получают managed Doctrine-сущность с mutating-методами (`setListing`, `replaceFromNewerVersion`). `TASK-FIX-06` (раздел 4.6) планирует добавить `getEnrichments(): iterable<EnrichmentTransaction>` по этому же шаблону.

**P0.5 — отсутствует `PnlFacade`.**
`App\Ingestion\Entity\PLDirtyPeriod` (таблица `pnl_dirty_periods`) и его репозиторий лежат в `App\Ingestion`. `TASK-FIX-06` (строки 208, 234, 351) предполагает, что `PnlFacade::markPeriodDirty(...)` существует и вызывается из `App\Ingestion\Application\Action\EnrichCogsAction` + `App\Marketplace`. Фасада сейчас нет; модуль `App\Finance` как точка входа не создан.

**P0.6 — отсутствует `IdempotentHandlerTrait`.**
Все упоминания в `INGESTION_ARCHITECTURE.md` (правило 24) и `TASK-FIX-06` (строка 290). В коде: `grep -r IdempotentHandlerTrait` пусто. `NormalizeRawRecordHandler` достигает идемпотентности через ранний выход на `DONE`; `RecordNormalizationIssueAction` дубли на ретраях не защищает (см. п.18 ревью). `EnrichCogsHandler` в TASK-FIX-06 потребует надёжной защиты от дублей: подписчик на event + cron-страховка диспатчат для одной транзакции дважды.

**P0.7 — retry-механизм для sync-чанков фактически не работает.**
`App\Ingestion\MessageHandler\RunSyncChunkHandler::__invoke` (`:155-159`) — catch-all → `markJobFailed` → `throw`. На любую транзиентную ошибку (deadlock, разрыв сокета, OOM в обработке) джоб помечается `FAILED`. На ретрае `if ($job->getStatus()->isTerminal()) return;` (`:56-64`) делает ретрай no-op'ом. Ретраи Messenger'а семантически выключены.

### 1.2 Желаемое состояние

**P0.1.** При повторном fetch одних и тех же данных Ozon (в любом порядке строк) `operationGroupId`, `sourceKey`, `externalId` совпадают с предыдущим. Записи без `accrual_id` не порождают дубли в каноне.

**P0.2.** Если `sourceData` не изменился, `replaceFromNewerVersion` не вызывается, `NormalizationCompletedEvent` не публикуется, `updated_at`/`externalUpdatedAt` в БД не движется.

**P0.3.** Один и тот же набор строк, поступивший в разном порядке, даёт один и тот же hash. Повторный fetch с тем же контентом не создаёт нового `IngestRawRecord`.

**P0.4.** `IngestionFacade::getTransactions(): iterable<FinancialTransactionView>`. Никакой потребитель из других модулей не имеет на этапе компиляции доступа к классу `App\Ingestion\Entity\FinancialTransaction`. `EnrichmentTransactionView` готов как паттерн к моменту начала TASK-FIX-06 (но не создаётся в Phase 0).

**P0.5.** `App\Finance\Facade\PnlFacade::markPeriodDirty(...)` существует, является единственной точкой записи в `pnl_dirty_periods` извне `App\Ingestion`. `EnrichCogsAction` из TASK-FIX-06 вызывает именно его. `App\Marketplace` тоже.

**P0.6.** Параллельная или повторная обработка одного и того же `NormalizeRawRecordMessage` или будущего `EnrichCogsMessage` не создаёт дубликатов в `ingest_financial_transactions` и `ingest_normalization_issues`. Защита — на уровне ловли `UniqueConstraintViolationException` в Action'ах (дешёвый вариант; полноценный трейт с таблицей `processed_messages` — отдельная будущая задача).

**P0.7.** Транзиентная ошибка в `RunSyncChunkHandler` → Messenger ретраит по retry-strategy. Только `ConnectorAuthException` приводит к `markJobFailed` немедленно (auth не лечится ретраем). После исчерпания ретраев `SyncJobFailureSubscriber` корректно завершает джоб в `FAILED`.

### 1.3 In scope

- Правка маппера: детерминированный fallback для `accrual_id` (B1).
- Правка коннекторов: канонизация порядка строк перед формированием `RawBatch` (B2).
- Правка `UpsertFinancialTransactionAction`: pre-check по hash `sourceData`, ловля unique violation (B3, B7).
- Правка `NormalizeRawRecordAction`: не публиковать `NormalizationCompletedEvent` если ни одна транзакция не изменилась; ловля unique violation на сохранении issues (B4, B7).
- Новый DTO `FinancialTransactionView` + правка `IngestionFacade::getTransactions` (B5).
- Создание скелета модуля `App\Finance` с `PnlFacade` (тонкий wrapper над существующим `App\Ingestion\Repository\PLDirtyPeriodRepository`) (B6).
- Переключение существующего потребителя (подписчика `NormalizationCompletedEvent`, который пишет `PLDirtyPeriod`) на `PnlFacade::markPeriodDirty` (B6).
- Правка `RunSyncChunkHandler`: убрать `markJobFailed` из catch-all (B8).
- Обновление `ARCHITECTURE.md`: новый Facade, новый DTO, новые контракты.
- Архитектурные тесты: запрет импорта `App\Ingestion\Entity\*` из других модулей (если ещё не настроено).

### 1.4 Out of scope

Намеренно НЕ делаем:

- **Шифрование credentials** — Phase 2 отдельной задачей. Никаких изменений в `EncryptedJsonType`, `SecretCodec`, `IngestionCredential` в этой задаче.
- **Физический перенос `PLDirtyPeriod` Entity в `App\Finance`** — остаётся в `App\Ingestion` физически, только Facade в Finance. Перенос — отдельная задача после стабилизации.
- **Полноценный `IdempotentHandlerTrait` с таблицей `processed_messages`** — Phase 0 ловит unique violation; инфраструктурный трейт — отдельная задача при росте нагрузки.
- **TASK-FIX-06 целиком** — никаких `EnrichmentTransaction`, `EnrichmentKind`, `enrichmentStatus`, `EnrichCogsAction`, `EnrichCogsMessage`, `EnrichCogsSubscriber`, `EnrichPendingCogsCommand` в этой задаче.
- **Hot-rewind на каждом инкременте** — Phase 1.
- **Race финализации parent backfill-job** (`MaybeFinalizeParentAction`) — Phase 1.
- **Outbox / `DispatchAfterCurrentBusStamp` в `SplitJobIntoChunksAction`** — Phase 1.
- **`CompanyFilter` fail-closed при отсутствии активной компании** — Phase 1.
- **Размер чанка через `SourceConnectorInterface`** (сейчас зашит в `SyncFacade::startBackfill`) — Phase 1.
- **Partial unique index против двойного backfill** — Phase 5.
- **Косметика**: удаление dead-code `oldOccurredAt`, дубли `findOneByIdAndCompany`/`findByIdAndCompany`, перенос `ReadRawRecordAction`/`StoreRawBatchAction` в `Application/Action/`, INFO-логи на каждый API-запрос — Phase 4.
- **Изменение схемы БД** любой степени — нет миграций в Phase 0.

### 1.5 Допущения и открытые вопросы

| # | Тип | Содержание |
|---|---|---|
| A1 | Допущение | `PnlFacade` реализуется по **Варианту B** (тонкий wrapper, физическая Entity остаётся в Ingestion). Зафиксировано как осознанный временный компромисс в `ARCHITECTURE.md`. |
| A2 | Допущение | Idempotency на этом этапе — через ловлю `Doctrine\DBAL\Exception\UniqueConstraintViolationException` в Action'ах. Полный `IdempotentHandlerTrait` с persistent dedup-таблицей — не в этой задаче. |
| A3 | Допущение | P0.2 решается **без новой колонки** в `ingest_financial_transactions`. Hash вычисляется on-the-fly в `UpsertFinancialTransactionAction` из `$mapped->sourceData` и сравнивается с `sha256(canonicalJson($existing->getSourceData()))`. Расход: ~один SHA256 на upsert; на MVP-масштабе пренебрежимо. |
| A4 | Допущение | `App\Finance` модуль — НОВЫЙ. Если существующий код уже содержит подобную папку — расширяем; если нет — создаём с минимальной конфигурацией. |
| A5 | Допущение | Существует подписчик `NormalizationCompletedEvent`, который сейчас пишет `PLDirtyPeriod` напрямую через репозиторий. В B6 переключаем его на `PnlFacade::markPeriodDirty`. Если подписчика нет — создаём (но он должен существовать, т.к. TASK-FIX-06 в строке 23 утверждает «`PLDirtyPeriod` + `rebuildPeriod` — уже работает»). |
| O1 | Открытый вопрос | Существуют ли в проде raw_records с fallback ID, созданные старой формулой (`fallback-N-...`)? Если да — будут «осиротевшие» FinancialTransaction'ы после внедрения B1. План на этот случай: одноразовый бэкфилл/сверка после деплоя. **Подтвердить запросом к проду перед мерджем B1.** |
| O2 | Открытый вопрос | Точный путь и имя класса существующего подписчика на `NormalizationCompletedEvent`. Найти при старте B6. |
| O3 | Открытый вопрос | Существуют ли потребители `IngestionFacade::getTransactions` помимо `App\Finance\…\RebuildPnlPeriodAction`? Обнаружить через grep и согласовать координированный merge B5. |
| O4 | Открытый вопрос | `App\Finance` — есть/нет существующая регистрация в `config/services.yaml`, `config/routes.yaml`, `config/packages/doctrine.yaml`. Выяснить до старта B6. |

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

**Новых Entity нет.** Изменения в существующей `App\Ingestion\Entity\FinancialTransaction`:

| Поле | Тип PHP | Колонка БД | Nullable | Default | Инвариант / правило |
|---|---|---|---|---|---|
| Без новых полей | — | — | — | — | Никаких ADD COLUMN. Никаких DROP COLUMN. |

Изменения методов:

| Метод | Изменение |
|---|---|
| `replaceFromNewerVersion(...)` | Без изменения сигнатуры. Внутренняя проверка `$externalUpdatedAt <= $this->externalUpdatedAt` → `StaleTransactionUpdateException` сохраняется как страховка. |
| (новый, private) `sourceContentMatches(array $sourceData): bool` | Сравнивает canonical sha256 от текущего `$this->sourceData` с переданным. Возвращает `true` если совпадает. Используется только `UpsertFinancialTransactionAction`. |
| (удалить) `$oldOccurredAt` поле и `oldOccurredAt()` метод | Dead-code: значение нигде не читается. **Этот пункт переносится в Phase 4**, не в Phase 0, чтобы не раздувать scope. |

Опционально (если так чище): метод сравнения hash вынести в Application/Service класс `SourceContentHasher` — тогда Entity не зависит от деталей нормализации. Решение разработчика на этапе B3.

### 2.2 Связи между сущностями

Без изменений.

### 2.3 Enum

Без новых enum'ов. Существующие не меняются.

`App\Ingestion\Enum\PLDirtyPeriodReason` — без изменений; используется через `PnlFacade::markPeriodDirty` в B6.

### 2.4 Матрица переходов

Без изменений. State machine `SyncJob`, `PLDirtyPeriod`, `FinancialTransaction` не модифицируются.

---

## 3. Слой доступа к данным

### 3.1 Repository

**Новых Repository нет.** Изменения:

| Repository | Метод | Изменение |
|---|---|---|
| `App\Ingestion\Repository\FinancialTransactionRepository` | `iterateByPeriod(...)` | Без изменений. Возвращает Entity для внутреннего пользования. **Через границу модуля не утекает** — проекция в DTO происходит в `IngestionFacade`. |
| `App\Ingestion\Repository\PLDirtyPeriodRepository` | существующие методы | Без изменений на этом этапе. Доступ из других модулей теперь только через `PnlFacade`. |

Все методы продолжают принимать `string $companyId` (где это смысл имеет; cross-tenant методы остаются помеченными).

### 3.2 Query

Без изменений.

### 3.3 Индексы

Без новых индексов. Никаких миграций.

---

## 4. Слой приложения

### 4.1 Action — изменения существующих

#### `App\Ingestion\Application\Action\UpsertFinancialTransactionAction` (правка, B3 + B7)

Файл: `src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php`.
Вход: `App\Ingestion\Application\Command\UpsertFinancialTransactionCommand` (без изменений).
Возврат: `?App\Ingestion\Application\DTO\UpsertResult` (без изменений сигнатуры; добавляется новый исход — см. ниже).

Поведение — изменения:

1. Найти существующую транзакцию через `findByNaturalKey` (как сейчас).
2. **Новая ветка**: если транзакция найдена и `sha256(canonicalJson($mapped->sourceData)) === sha256(canonicalJson($existing->getSourceData()))` → возврат `null` (нет изменений). `replaceFromNewerVersion` не вызывается. `oldOccurredAt`/`newOccurredAt` не вычисляются. `UpdateAt`, `externalUpdatedAt` в БД не движутся.
3. Если контент изменился — вызвать `replaceFromNewerVersion` как раньше.
4. **Изменение в ветке `null === $transaction`**: после `persist + flush` ловить `Doctrine\DBAL\Exception\UniqueConstraintViolationException`. При перехвате — re-fetch по natural key и пройти ветку `update` (как в `StoreRawBatchAction::recoverConcurrentDuplicate`). Сценарий возникновения: параллельная нормализация того же `raw_record_id` через cron-страховку.

Транзакционность: одна транзакция БД (как сейчас, через `EntityManagerInterface`). Ловля unique violation не выходит за пределы Action.

Исключения:
- `StaleTransactionUpdateException` — без изменений.
- `UniqueConstraintViolationException` — ловится внутри, не пробрасывается; после recovery возвращается `UpsertResult` или `null` (зависит от того, изменилось ли что-то).

#### `App\Ingestion\Application\Action\NormalizeRawRecordAction` (правка, B4 + B7)

Файл: `src/Ingestion/Application/Action/NormalizeRawRecordAction.php`.

Изменения:

1. Накапливать `$affectedPeriods` только когда `UpsertResult !== null` (как сейчас, `:127-133` — оставить).
2. **Новая ветка**: после цикла, если `$affectedPeriods === []` → событие `NormalizationCompletedEvent` **не публикуется**. Если все upsert'ы вернули `null` (контент не изменился) — слушателям нечего обрабатывать.
3. **Идемпотентность issues**: при вызове `RecordNormalizationIssueAction` ловить `UniqueConstraintViolationException` (после добавления уникального индекса в отдельной будущей задаче, либо через предварительный `findByNaturalKey` issues). **В Phase 0 ограничиваемся изменением порядка** (см. ниже); полная защита issues от дублей — отдельная задача.
4. **Перестановка**: `markNormalizationDone` вызывать **до** `recordControlSumIssues`. Если control-sum упадёт по таймауту — raw уже DONE, ретрай не будет тратиться на повторную нормализацию. Issues пишутся в отдельной транзакции best-effort.

Транзакционность: тот же `beginTransaction/commit/rollBack` block, что и сейчас.

#### `App\Ingestion\MessageHandler\RunSyncChunkHandler` (правка, B8)

Файл: `src/Ingestion/MessageHandler/RunSyncChunkHandler.php`.

Изменения в блоке catch'ей (`:131-161`):

1. `catch (ConnectorAuthException $exception)` — без изменений. `markJobFailed('auth')` + `UnrecoverableMessageHandlingException`.
2. `catch (ConnectorRateLimitedException $exception)` — без изменений в Phase 0 (баг с потерянным локальным `$cursorValue` — Phase 1).
3. `catch (ConnectorTransientException $exception)` — без изменений. Throw наружу → Messenger ретрай.
4. **`catch (\Throwable $exception)` — РАДИКАЛЬНО МЕНЯЕТСЯ**:
   - НЕ вызывать `markJobFailed`.
   - Логировать WARNING с `companyId`, `jobId`, `exceptionClass`, `errorMessage`.
   - Throw наружу → Messenger ретрай.

`App\Ingestion\Infrastructure\Messenger\SyncJobFailureSubscriber::onMessageFailed` уже корректно срабатывает на `WorkerMessageFailedEvent` при `willRetry() === false`. Он закроет job в `FAILED` после исчерпания retry-strategy.

Метод `markJobFailed` (private, `:181-193`) в `RunSyncChunkHandler` можно оставить — он по-прежнему вызывается из `ConnectorAuthException` ветки.

### 4.2 Action — новые

**Новых Action нет.** `PnlFacade::markPeriodDirty` в B6 делегирует существующему `PLDirtyPeriodRepository` (или существующему Action, если он уже есть). См. §4.5.

### 4.3 Domain Service / Policy

Без новых Domain Services.

Опционально для чистоты: вынести canonical-JSON-hash в Domain Service.

`App\Ingestion\Domain\Service\SourceDataHasher` (final readonly class):

| Метод | Описание |
|---|---|
| `hash(array $sourceData): string` | Рекурсивно `ksort` все ассоциативные массивы, json_encode с `JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`, sha256 от результата, возвращает hex-string длины 64. |

Назначение: единая точка вычисления hash для UpsertAction и для тестов. Дубль-логика с `RawNdjsonCodec::normalizeValue` — допустима, нормализация под немного разный target (одна row vs многомерная NDJSON). При желании отрефакторить — после Phase 0.

### 4.4 DTO

#### Command DTO — без изменений.

#### View DTO — новый

**`App\Ingestion\Application\DTO\FinancialTransactionView`** (B5).
Файл: `src/Ingestion/Application/DTO/FinancialTransactionView.php`.
`final readonly class`.

| Поле | Тип | Обязательно | Источник |
|---|---|---|---|
| `id` | string (UUID) | да | `FinancialTransaction::getId()` |
| `companyId` | string (UUID) | да | `FinancialTransaction::getCompanyId()` |
| `shopRef` | string | да | `FinancialTransaction::getShopRef()` |
| `source` | string (enum value `IngestSource::value`) | да | `FinancialTransaction::getSource()->value` |
| `externalId` | string | да | `FinancialTransaction::getExternalId()` |
| `operationGroupId` | string (UUID) | да | `FinancialTransaction::getOperationGroupId()` |
| `type` | string (enum value `TransactionType::value`) | да | `FinancialTransaction::getType()->value` |
| `direction` | string (enum value `TransactionDirection::value`) | да | `FinancialTransaction::getDirection()->value` |
| `amountMinor` | int | да | `FinancialTransaction::getAmountMinor()` |
| `currency` | string (ISO 4217) | да | `FinancialTransaction::getCurrency()` |
| `occurredAt` | DateTimeImmutable | да | `FinancialTransaction::getOccurredAt()` |
| `sourceTz` | string | да | `FinancialTransaction::getSourceTz()` |
| `orderRef` | ?string | нет | `FinancialTransaction::getOrderRef()` |
| `payoutRef` | ?string | нет | `FinancialTransaction::getPayoutRef()` |
| `counterpartyId` | ?string (UUID) | нет | `FinancialTransaction::getCounterpartyId()` |
| `listingId` | ?string (UUID) | нет | `FinancialTransaction::getListingId()` |
| `listingSku` | ?string | нет | `FinancialTransaction::getListingSku()` |
| `description` | ?string | нет | `FinancialTransaction::getDescription()` |
| `rawRecordId` | string (UUID) | да | `FinancialTransaction::getRawRecordId()` |

Формат сериализации наружу (если будет JSON): `snake_case` для ключей, ISO 8601 для дат, `value` для enum'ов. Сам DTO остаётся в `camelCase` для PHP-кода.

Поле `enrichmentStatus` — **НЕ добавляется в Phase 0** (вводится в TASK-FIX-06 B2). После TASK-FIX-06 в DTO появится дополнительное поле через separate PR.

Фабрика проекции — приватный метод `IngestionFacade::projectTransactionToView(FinancialTransaction): FinancialTransactionView` (B5).

### 4.5 Facade

#### `App\Ingestion\Facade\IngestionFacade` (правка, B5)

Изменения сигнатур:

| Метод | Было | Стало |
|---|---|---|
| `getTransactions(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $shopRef = null): iterable` | `iterable<FinancialTransaction>` | `iterable<FinancialTransactionView>` |

Внутри метод оборачивает `FinancialTransactionRepository::iterateByPeriod(...)` в generator-проектор:

```
foreach ($this->repo->iterateByPeriod(...) as $entity) {
    yield $this->projectTransactionToView($entity);
    // Опционально: detach entity для освобождения UoW при больших периодах.
}
```

Остальные методы Facade — без изменений в Phase 0.

#### `App\Finance\Facade\PnlFacade` (новый, B6)

Файл: `src/Finance/Facade/PnlFacade.php`.
`final readonly class`.

| Метод | Описание | companyId |
|---|---|---|
| `markPeriodDirty(string $companyId, int $year, int $month, string $shopRef, PLDirtyPeriodReason $reason): void` | Создать или обновить `PLDirtyPeriod` для указанного периода. Если запись существует и в терминальном `DONE` — перевести в `PENDING` через `reopen()`. Если `BLOCKED_BY_CLOSE` — не трогать, логировать INFO. | да |

Зависимости (constructor injection):
- `App\Ingestion\Repository\PLDirtyPeriodRepository` (временное исключение, см. §9 и `ARCHITECTURE.md` запись о Variant B).
- `Doctrine\ORM\EntityManagerInterface`.
- `Psr\Log\LoggerInterface`.

**ВРЕМЕННОЕ ИСКЛЮЧЕНИЕ:** `App\Finance` импортирует `App\Ingestion\Repository\PLDirtyPeriodRepository` — это нарушение правила «другие модули обращаются только через Facade своего соседа». Зафиксировано в `ARCHITECTURE.md` как осознанный компромисс на время MVP. План миграции: физический перенос `PLDirtyPeriod` Entity + Repository в `App\Finance` после стабилизации (отдельная задача, не Phase 0).

Использование `PnlFacade`:
- из подписчика `NormalizationCompletedEvent` (B6) — переключение существующего кода с прямого репозитория на Facade.
- из будущего `App\Ingestion\Application\Action\EnrichCogsAction` (TASK-FIX-06 B4) — не наша задача, но контракт готов.
- из будущего `App\Marketplace\…\UpdateCostPriceAction` (TASK-FIX-06 B9) — не наша задача.

### 4.6 Mapper / Connector — правки

#### `App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper::accrualId` (B1)

Файл: `src/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapper.php`.

Изменение метода `accrualId(array $row, int $rowIndex): string`:

| Было | Стало |
|---|---|
| Если есть `row['accrual_id']` → возврат. Иначе `'fallback-' + rowIndex + '-' + sha256(json(row))[0:16]`. | Если есть `row['accrual_id']` → возврат. Иначе `'fallback-' + sha256(canonicalJson(row))[0:16]`. `rowIndex` **удаляется** из аргументов и из формулы. |

`canonicalJson(row)` — `json_encode` после рекурсивного `ksort` (использовать существующий `RawNdjsonCodec::normalizeValue` через вынесение в публичный helper, или продублировать логику локально — решение в B1).

Сигнатура метода меняется: `accrualId(array $row): string`. Вызывающий код в `preview(...)` правится соответственно (убирается `$rowIndex` из вызова).

#### `App\Ingestion\Application\Source\Ozon\OzonSellerReportConnector` (B2)

Файл: `src/Ingestion/Application/Source/Ozon/OzonSellerReportConnector.php`.

Изменение метода `pullAccrualByDay(PullRequest $request): PullResult`:

Перед формированием `RawBatch` (строка `~102`) добавить сортировку `$rows` по детерминированному ключу:

```
$rows = $this->sortRowsCanonically($rows);
```

Новый private-метод `sortRowsCanonically(array $rows): array`:
- ключ сортировки = `($row['date'] ?? '') . '|' . ($row['accrual_id'] ?? '') . '|' . canonicalJson($row)`,
- `usort` по этому ключу.

Стабильность: при совпадающих `date + accrual_id` финальный tie-breaker по полному содержимому row.

#### `App\Ingestion\Application\Source\Wildberries\WbFinanceReportConnector` (B2)

Файл: `src/Ingestion/Application/Source/Wildberries/WbFinanceReportConnector.php`.

Аналогично: метод `pull(...)` перед формированием `RawBatch` сортирует `$rows`. Ключ — `($row['rrd_id'] ?? '') . '|' . canonicalJson($row)`.

`RawNdjsonCodec` — **не трогаем**. Канонизация — ответственность connector'а (он знает доменный ключ row).

---

## 5. Асинхронность (Messenger)

### 5.1 Новые Message / Handler

Нет.

### 5.2 Изменения существующих

| Handler | Изменение |
|---|---|
| `App\Ingestion\MessageHandler\RunSyncChunkHandler` | См. §4.1 — изменение catch-all (B8). Routing не меняется. Retry-strategy не меняется. |
| `App\Ingestion\MessageHandler\NormalizeRawRecordHandler` | Без структурных изменений. Идемпотентность улучшается косвенно через изменения в `UpsertFinancialTransactionAction` (B3). |

### 5.3 `config/packages/messenger.yaml`

**НЕ ИЗМЕНЯЕТСЯ.** Никаких новых routings, transports, retry-стратегий. Это критическая зона (`🔴 HIGH`).

---

## 6. Обработка ошибок

### 6.1 Новых исключений нет.

### 6.2 Изменения в обработке существующих

| Класс | Где обрабатывается | Изменение |
|---|---|---|
| `Doctrine\DBAL\Exception\UniqueConstraintViolationException` | `UpsertFinancialTransactionAction` | НОВОЕ: ловится, ведёт к re-fetch + retry внутри Action. Сейчас пробрасывается → откат транзакции NormalizeRawRecordAction. |
| `\Throwable` | `RunSyncChunkHandler::__invoke` catch-all | НОВОЕ: больше не вызывает `markJobFailed`. Логируется WARNING, пробрасывается → Messenger retry. |

### 6.3 Формат ошибок наружу

Без изменений — Phase 0 не трогает HTTP API.

---

## 7. HTTP API (Controller)

**N/A.** Phase 0 не добавляет, не изменяет, не удаляет публичные HTTP-эндпоинты. Существующие контроллеры (`App\Ingestion\Controller\Api\Verification\*`) не трогаются.

---

## 8. Разбивка на подзадачи

### 8.1 Сводная таблица

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| **B1** | Mapper P0.1: детерминизм fallback `accrualId` без `rowIndex` | — | 🟡 MEDIUM | unit |
| **B2** | Connector P0.3: канонизация порядка `rows` в `RawBatch` (Ozon + WB) | — | 🟡 MEDIUM | unit |
| **B3** | UpsertAction P0.2: pre-check по `sourceHash`, новая ветка NO_CHANGE | — | 🟡 MEDIUM | integration |
| **B4** | NormalizeAction P0.2: не публиковать пустой `NormalizationCompletedEvent`; markDone до controlSum | B3 | 🟢 LOW | integration |
| **B5** | IngestionFacade P0.4: `getTransactions` → `iterable<FinancialTransactionView>` + новый DTO | — | 🔴 HIGH (контракт) | integration + archtest |
| **B6** | App\Finance + `PnlFacade` P0.5: новый модуль; переключение подписчика | — | 🔴 HIGH (новый модуль) | integration |
| **B7** | Idempotency P0.6 (cheap): catch `UniqueConstraintViolationException` в Upsert + (опц.) issues | B3 | 🟡 MEDIUM | concurrency test |
| **B8** | RunSyncChunkHandler P0.7: убрать `markJobFailed` из catch-all | — | 🟡 MEDIUM | integration |

Все этапы — **code-only**. Миграций БД нет.

### 8.2 Детализация этапов

#### B1 — Mapper детерминизм

- **Цель.** Один и тот же физический ряд Ozon, поступивший в разном порядке, даёт один и тот же `operationGroupId`/`sourceKey`/`externalId`.
- **Создаёт файлы.** Опционально `src/Ingestion/Domain/Service/SourceDataHasher.php` если решено вынести canonical-hash.
- **Меняет файлы.**
  - `src/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapper.php` — метод `accrualId`, метод `preview` (убрать `$rowIndex` из вызова).
- **DoD.**
  - Юнит-тест: два экземпляра одних и тех же rows в разном порядке → одинаковый `operationGroupId` для каждой пары.
  - Юнит-тест: row без `accrual_id` с минимальными отличиями → разные fallback ID.
  - `make stan && make cs && make test` зелёные на изменённом коде.
- **Зависимости.** Нет.

#### B2 — Канонизация порядка строк

- **Цель.** Hash `IngestRawRecord` стабилен относительно порядка строк в ответе источника.
- **Меняет файлы.**
  - `src/Ingestion/Application/Source/Ozon/OzonSellerReportConnector.php` — добавить `sortRowsCanonically` + вызов в `pullAccrualByDay`.
  - `src/Ingestion/Application/Source/Wildberries/WbFinanceReportConnector.php` — аналогично в `pull`.
- **DoD.**
  - Юнит-тест connector'а: одни и те же rows в разном порядке → одинаковый hash после `RawNdjsonCodec::encodeRows`.
  - Тест регрессии для существующих сохранённых raw_records: hash не должен изменяться для уже отсортированных входов.
- **Зависимости.** Нет.

#### B3 — sourceHash pre-check в UpsertAction

- **Цель.** При повторной нормализации того же контента — нет лишних `UPDATE` в БД, нет `NormalizationCompletedEvent` для неизменившихся периодов.
- **Создаёт файлы.** Опц. `src/Ingestion/Domain/Service/SourceDataHasher.php`.
- **Меняет файлы.**
  - `src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php` — добавить pre-check sourceHash.
- **DoD.**
  - Integration-тест: повторная нормализация того же raw_record (имитация cron-страховки) → `FinancialTransaction.updatedAt` не движется; `UpsertResult` = `null`.
  - Integration-тест: фактическое изменение содержимого (новое поле в sourceData) → `replaceFromNewerVersion` вызывается, `UpsertResult` не `null`.
  - Existing-тесты (если есть) на `replaceFromNewerVersion` — зелёные.
- **Зависимости.** Нет (но B7 строится поверх).

#### B4 — Event не публикуется впустую

- **Цель.** `NormalizationCompletedEvent` срабатывает только когда **действительно** что-то изменилось.
- **Меняет файлы.**
  - `src/Ingestion/Application/Action/NormalizeRawRecordAction.php` — условное dispatch; перестановка `markNormalizationDone` относительно `recordControlSumIssues`.
- **DoD.**
  - Integration-тест: повторная нормализация без изменений → подписчики не дёргаются.
  - Integration-тест: изменилась одна транзакция из десяти → событие публикуется с одним `AffectedPeriod`.
- **Зависимости.** B3.

#### B5 — IngestionFacade DTO

- **Цель.** Закрыть утечку Entity через Facade до того, как `getEnrichments` в TASK-FIX-06 размножит проблему.
- **Создаёт файлы.**
  - `src/Ingestion/Application/DTO/FinancialTransactionView.php`.
- **Меняет файлы.**
  - `src/Ingestion/Facade/IngestionFacade.php` — изменение возвращаемого типа `getTransactions`, private-метод `projectTransactionToView`.
  - Все потребители `IngestionFacade::getTransactions` (предположительно `App\Finance\…\RebuildPnlPeriodAction` — уточнить через grep) → правка под новый DTO.
- **DoD.**
  - Integration-тест: вызов `getTransactions` отдаёт DTO; нет утечки Entity.
  - Архитектурный тест (`deptrac`/`phparkitect` или ad-hoc PHPUnit): класс `App\Ingestion\Entity\FinancialTransaction` не импортируется ни из одного namespace кроме `App\Ingestion\*`.
  - Все существующие тесты consumer-кода — зелёные.
- **Зависимости.** Нет (но координация с потребителями).
- **STOP-точка перед merge.** Согласовать с Владельцем (риск 🔴 HIGH).

#### B6 — App\Finance + PnlFacade

- **Цель.** Создать единую точку входа для записи в `pnl_dirty_periods` для соседних модулей.
- **Создаёт файлы.**
  - `src/Finance/Facade/PnlFacade.php`.
  - `config/services.yaml` или модульный конфиг: регистрация namespace `App\Finance\*`.
  - `config/routes.yaml`: ничего (без контроллеров).
- **Меняет файлы.**
  - Существующий подписчик `NormalizationCompletedEvent` (найти при старте, O2): заменить прямое обращение к `PLDirtyPeriodRepository` на `PnlFacade::markPeriodDirty`.
  - `ARCHITECTURE.md`: добавить раздел про `App\Finance` модуль, `PnlFacade`, временное исключение по импорту `PLDirtyPeriodRepository`.
- **DoD.**
  - Integration-тест: `PnlFacade::markPeriodDirty(...)` создаёт новый `PLDirtyPeriod`, повторный вызов с тем же ключом — `reopen` или no-op в зависимости от текущего статуса.
  - Integration-тест: запись с статусом `BLOCKED_BY_CLOSE` не пере-открывается; логируется INFO.
  - Существующий подписчик event'а проходит свои тесты (теперь через Facade).
  - `lint:container --env=test` зелёный.
- **Зависимости.** Нет.
- **STOP-точка перед merge.** Согласовать с Владельцем (новый модуль, риск 🔴 HIGH).

#### B7 — Idempotency через unique-violation

- **Цель.** Параллельная нормализация одного raw_record не падает с unhandled exception.
- **Меняет файлы.**
  - `src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php` — try/catch вокруг persist+flush. На UniqueConstraintViolationException → re-fetch по natural key, выполнить ветку update.
  - (опц.) `src/Ingestion/Application/Action/RecordNormalizationIssueAction.php` — pre-check `findByNaturalKey` issues, или try/catch на persist (только если в БД есть уникальный индекс; в Phase 0 индекс НЕ добавляется — пункт частично переносится).
- **DoD.**
  - Integration-тест (concurrency): два параллельных `NormalizeRawRecordHandler` для одного `raw_record_id` → итоговое состояние БД корректное, нет orphan-entity, нет необработанных исключений.
- **Зависимости.** B3.

#### B8 — RunSyncChunkHandler retry semantics

- **Цель.** Транзиентная ошибка → Messenger ретраит, а не «мгновенно failed».
- **Меняет файлы.**
  - `src/Ingestion/MessageHandler/RunSyncChunkHandler.php` — изменить catch-all.
- **DoD.**
  - Integration-тест: handler бросает `RuntimeException` на первой попытке, успех на второй → джоб `COMPLETED`, не `FAILED`.
  - Integration-тест: после исчерпания retry-strategy (3 попытки) `SyncJobFailureSubscriber` корректно помечает `FAILED`.
  - Existing-тесты `RunSyncChunkHandler` — зелёные.
- **Зависимости.** Нет.

---

## 9. Ограничения и запреты

### 9.1 Не ломать

- `app:ingestion:start-backfill`, `app:ingestion:run-incremental`, `app:ingestion:normalize-pending` — поведение CLI не меняется.
- Cron'ы: `*/10 * * * *` (normalize-pending), `0 3 * * *` (run-incremental) — без изменений.
- Существующие подписки/раутинги Messenger.
- Эндпоинты `/api/ingestion/verification/*` — без изменений.

### 9.2 Не трогать

- `config/packages/messenger.yaml` — 🔴 HIGH запрет.
- `config/packages/doctrine.yaml` — без изменений (только если добавление `App\Finance` mapping требует записи — отдельный STOP).
- Schema БД — никаких миграций. `ingest_*` таблицы — ровно как сейчас.
- `App\Catalog` — не трогаем.
- Legacy зону: `src/Entity/`, `src/Service/`, `src/Repository/`, `src/Controller/` — не трогаем.
- `EncryptedJsonType`, `SecretCodec`, `PlaintextSecretCodec`, `IngestionCredential` — не трогаем (Phase 2).
- `MaybeFinalizeParentAction` — не трогаем (Phase 1).
- `SplitJobIntoChunksAction` — не трогаем (Phase 1).
- `CompanyFilter`, `CompanyFilterMiddleware`, `CompanyFilterRequestSubscriber` — не трогаем (Phase 1).
- TASK-FIX-06 stuff (`EnrichmentTransaction`, `EnrichmentKind`, `enrichmentStatus`, `EnrichCogs*`) — не трогаем.

### 9.3 Совместимость API

- Публичный HTTP API не меняется.
- `IngestionFacade` — внутренний контракт, меняется (B5). Координируется с единственным потребителем.

### 9.4 Миграции

- **Нет миграций.** Если в ходе работы выяснится, что миграция нужна — это сигнал к STOP перед мержем, согласовать с Владельцем.

### 9.5 Performance

- `IngestionFacade::getTransactions` сохраняет `iterable` (генератор) — нет загрузки всех транзакций в память.
- В `projectTransactionToView` опционально вызывать `$entityManager->detach($entity)` для освобождения UoW при больших периодах. Решение в B5.
- `SourceDataHasher::hash` — `sha256` по строке порядка нескольких kB на одну транзакцию. Допустимо.
- Запретов на N+1 не вводим новых; существующее в `recordControlSumIssues` (N+1 по `findByOperationGroup`) — отдельная задача (Phase 4 или 1, по решению).

### 9.6 Безопасность

- `companyId` сохраняется в сигнатурах всех изменённых методов.
- В `FinancialTransactionView` сохраняется поле `companyId` (нужно для tenant-aware последующих операций).
- В подписчиках, использующих `PnlFacade::markPeriodDirty`, `companyId` передаётся явно.
- IDOR-чеклист проходится по каждому Repository-методу — без изменений.

---

## 10. Критерии приёмки

### Функциональные

- [ ] **B1.** Повторный fetch Ozon `accrual_by_day` с переставленными строками без `accrual_id` → канон НЕ получает новых записей; `operationGroupId`/`externalId` совпадают с предыдущей загрузкой.
- [ ] **B2.** То же на уровне `RawNdjsonCodec`: hash `IngestRawRecord` стабилен относительно порядка строк в ответе.
- [ ] **B3.** Повторная нормализация одного `raw_record_id` с неизменённым контентом → `FinancialTransaction.updatedAt` не движется; `UpsertResult` для каждой транзакции = `null`.
- [ ] **B4.** В сценарии B3 — `NormalizationCompletedEvent` НЕ публикуется; подписчики `PLDirtyPeriod` не дёргаются.
- [ ] **B5.** `IngestionFacade::getTransactions` возвращает `iterable<FinancialTransactionView>`. Архитектурный тест: класс `App\Ingestion\Entity\FinancialTransaction` не импортирован вне `App\Ingestion\*`.
- [ ] **B6.** `App\Finance\Facade\PnlFacade::markPeriodDirty(companyId, year, month, shopRef, reason)` существует и работает. Существующий подписчик `NormalizationCompletedEvent` использует Facade, а не Repository напрямую.
- [ ] **B7.** Concurrency-тест: 2 параллельных `NormalizeRawRecordHandler` для одного `raw_record_id` → нет необработанных `UniqueConstraintViolationException`; итоговое состояние БД консистентное.
- [ ] **B8.** Integration-тест: `RunSyncChunkHandler` ловит `RuntimeException` на 1-й попытке, проходит на 2-й → джоб `COMPLETED`, attempts=2 (не FAILED).

### Технические

- [ ] `doctrine:schema:validate --skip-sync --env=test` — зелёный (схема не меняется, валидация остаётся).
- [ ] `lint:container --env=test` — зелёный (после добавления `App\Finance` сервисов).
- [ ] `make stan` — зелёный на изменённых файлах.
- [ ] `make cs` — зелёный.
- [ ] `make test` — все unit + integration тесты зелёные.
- [ ] Tenant-leak тесты на `FinancialTransactionRepository::iterateByPeriod` через Facade — зелёные.
- [ ] Архитектурный тест: импорт `App\Ingestion\Entity\*` запрещён из `App\Marketplace`, `App\Finance`, `App\Catalog`.
- [ ] `ARCHITECTURE.md` обновлён:
  - `App\Finance\Facade\PnlFacade` (новый),
  - `App\Ingestion\Application\DTO\FinancialTransactionView` (новый),
  - изменение сигнатуры `IngestionFacade::getTransactions`,
  - запись о временном исключении: `App\Finance` импортирует `App\Ingestion\Repository\PLDirtyPeriodRepository` (Variant B).
- [ ] `docs/tasks/<id>/handoff.md` оформлен по шаблону.
- [ ] Все 8 STOP-точек (B5 + B6) согласованы с Владельцем перед мержем.

---

## 11. План отката

Каждый этап разворачивается **независимо** (rollback per-stage).

| Этап | Способ отката | Потеря данных |
|---|---|---|
| B1 | Revert PR. Существующие fallback-ID в каноне остаются; новые fetch'и снова начнут генерировать row-index-зависимые ID — вернётся прежний баг. | Нет. |
| B2 | Revert PR. Дедупликация raw снова станет нестабильной. | Нет. |
| B3 | Revert PR. Повторные нормализации снова будут обновлять `FinancialTransaction.updatedAt` без необходимости. | Нет. |
| B4 | Revert PR (зависит от B3, откатывать вместе). `NormalizationCompletedEvent` снова публикуется на пустом изменении. | Нет. |
| B5 | Revert PR. Восстановить старую сигнатуру `getTransactions`. Потребитель (`App\Finance`) тоже откатить. | Нет. |
| B6 | Revert PR. Удалить `App\Finance\Facade\PnlFacade.php`. Восстановить прямое обращение подписчика к `PLDirtyPeriodRepository`. **Не удалять записи из `pnl_dirty_periods`** — данные не теряются. | Нет. |
| B7 | Revert PR. Параллельные normalize снова будут падать с `UniqueConstraintViolationException`. | Нет. |
| B8 | Revert PR. `RunSyncChunkHandler` снова будет мгновенно ставить `FAILED` на любую ошибку. | Нет. |

Никаких миграций откатывать не требуется. Никаких удалений данных rollback не делает.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь для каждого нового/изменяемого класса.
- [x] Таблица полей `FinancialTransactionView` с типами и источником.
- [x] Все enum (используемые) перечислены явно либо помечены «без изменений».
- [x] Repository-методы помечены по companyId (или явно cross-tenant).
- [x] Каждый Action — шаги словами, без тел методов.
- [x] Идемпотентность handlers явно описана (через unique violation на этом этапе).
- [x] Сигнатура каждого нового метода Facade приведена (`PnlFacade::markPeriodDirty`, `FinancialTransactionView` поля).
- [x] HTTP — явно помечено `N/A`.
- [x] Out of scope — перечислено, включая TASK-FIX-06 stuff, шифрование, Phase 1 пункты.
- [x] Плана миграций нет → пункт «нет миграций» явно зафиксирован.
- [x] Плана отката по каждому этапу — есть.
- [x] Риски проставлены (B5, B6 = 🔴 HIGH с STOP-точками; B1/B2/B3/B7/B8 = 🟡; B4 = 🟢).
- [x] Открытые вопросы (O1–O4) явно вынесены, разработчик не угадывает.
