# TASK — БЛОК 4: Ingestion · Cursor + SyncJob (оркестрация)

## 0. Сводка

- **Бизнес-цель.** Ввести единый слой курсоров и заданий синхронизации для нового модуля `Ingestion`, чтобы оркестрация бэкфилла/инкрементов перестала дублироваться по источникам (сейчас 5 разных cursor-сущностей в legacy) и стала единообразной для всех будущих коннекторов. Без бизнес-логики конкретного источника.
- **Модуль.** `App\Ingestion` (новый, продолжение блоков 1-3).
- **Тип.** feature.
- **Ветка.** `feature/ingestion-04-cursor-syncjob`.
- **Подзадачи.** B1 Entity+миграция · B2 Repository · B3 Доменная политика state machine · B4 Action'ы (планирование/чанкинг/завершение) · B5 Facade · B6 Messenger inbound (только контракт сообщения, без handler'ов источника) · B7 Тесты.
- **Затрагивает другие модули.** Нет.
- **Требует миграции БД.** Да (две таблицы).
- **Меняет публичный API.** Нет (HTTP API не вводим, только Facade для будущих коннекторов).

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- Курсоры разрозненны: `BankImportCursor`, `MarketplaceFinancialReportSyncStatus.nextRrdId`, `AdScheduledBatch`, `InventorySnapshotSession`, legacy `ozon_sync_cursor`.
- Чанкинг бэкфилла реализован по-разному в каждом источнике.
- В Ingestion готов нижний слой (raw + credentials + изоляция), но нет оркестрации.
- Существует `CompanyFilter` (блок 1), `RawStorageFacade::store` (блок 3) — новый модуль их использует.

### 1.2 Желаемое состояние

- Один тип Entity `IngestCursor` хранит «где я остановился» для пары (connection, resource, shop).
- Один тип Entity `SyncJob` описывает выполняемое задание sync — бэкфилл/инкремент/manual; родительский job делится на дочерние чанки.
- Курсор обновляется ТОЛЬКО после успешной записи raw (не после fetch).
- State machine `SyncJob` явная, переходы валидируются доменной политикой.
- Появляется Facade для будущих коннекторов: `startBackfill`, `runIncremental`, `progress`.
- Rate limit между запросами одного источника — через Redis Lock (`LockFactory` + RedisStore).

### 1.3 In scope

- `IngestCursor`, `SyncJob` (Entity + миграция + индексы).
- Repository обеих сущностей с обязательным `companyId`.
- Enum `SyncJobKind`, `SyncJobStatus`.
- Доменная политика `SyncJobTransitionPolicy` для state machine.
- Action: `StartBackfillAction`, `SplitJobIntoChunksAction`, `MarkJobRunningAction`, `MarkJobCompletedAction`, `MarkJobFailedAction`, `UpdateCursorAction`.
- Facade `SyncFacade` как единая точка для будущих коннекторов (блок 6).
- Сообщения Messenger: `RunSyncChunkMessage` (контракт; **handler в блоке 5/6**, здесь только класс Message и routing в `messenger.yaml`).
- Транспорт `ingest_fetch` (как алиас к `async_sync`) и `ingest_normalize` (как алиас к `async_pipeline`) — заводим оба, используем в блоках 5-6.
- Service rate limit `IngestRateLimitGuard` поверх Redis Lock.

### 1.4 Out of scope

- Реальные HTTP-вызовы к источникам (блок 5/6).
- Handler `RunSyncChunkMessage` (блок 5).
- Канон `FinancialTransaction` и нормализация (блок 5).
- Конкретный `OzonSellerReportConnector` (блок 6).
- HTTP API для управления sync (блок 8).
- Admin-интерфейс для управления sync-job'ами (блок 9).

### 1.5 Допущения и открытые вопросы

- Допущение: `connectionRef` — строковый идентификатор подключения (UUID или код), формат прозрачен для блока 4, конкретные значения определяет коннектор в блоке 6.
- Допущение: rate-limit лимиты на источник конфигурируются через параметры сервиса в `services.yaml` (в блоке 6 коннектор задаст реальные значения; здесь — дефолт 60 запросов/мин на ключ).

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

#### `App\Ingestion\Entity\IngestCursor`

Файл: `src/Ingestion/Entity/IngestCursor.php`.
Таблица: `#[ORM\Table(name: 'ingest_cursors')]`.
Реализует `TenantOwnedInterface` (для CompanyFilter из блока 1).

| Поле | Тип PHP | Колонка БД | Nullable | Default | Инвариант / правило |
|---|---|---|---|---|---|
| `id` | `string` (UUID v7) | `id` GUID | нет | — | PK; `Uuid::uuid7()->toString()` в конструкторе |
| `companyId` | `string` UUID | `company_id` GUID | нет | — | `Assert::uuid`; неизменяем (нет setter) |
| `connectionRef` | `string` | `connection_ref` VARCHAR(255) | нет | — | `Assert::notEmpty`; неизменяем |
| `resourceType` | `string` | `resource_type` VARCHAR(100) | нет | — | `Assert::notEmpty`; неизменяем |
| `shopRef` | `string` | `shop_ref` VARCHAR(255) | нет | `''` | пустая строка — «нет shop»; неизменяем |
| `cursorValue` | `string` | `cursor_value` VARCHAR(1024) | нет | `''` | opaque; формат — забота коннектора |
| `lastFetchedAt` | `?DateTimeImmutable` | `last_fetched_at` TIMESTAMP(6) | да | `null` | время последнего успешного fetch |
| `lastSyncJobId` | `?string` | `last_sync_job_id` GUID | да | `null` | ссылка на последний SyncJob, обновивший курсор |
| `createdAt` | `DateTimeImmutable` | `created_at` TIMESTAMP(6) | нет | — | в конструкторе |
| `updatedAt` | `DateTimeImmutable` | `updated_at` TIMESTAMP(6) | нет | — | обновлять при `advance()` |

Конструктор: `__construct(string $companyId, string $connectionRef, string $resourceType, string $shopRef = '')` — выставляет id, companyId, connectionRef, resourceType, shopRef, createdAt=now, updatedAt=now; `cursorValue=''`, `lastFetchedAt=null`, `lastSyncJobId=null`.

Поведенческий метод (без тела):
- `advance(string $newCursorValue, string $syncJobId, ?DateTimeImmutable $fetchedAt = null): void` — устанавливает `cursorValue`, `lastSyncJobId`, `lastFetchedAt = $fetchedAt ?? now`, `updatedAt = now`. Инвариант: `$newCursorValue` не пустой.

Геттеры: всех полей.

#### `App\Ingestion\Entity\SyncJob`

Файл: `src/Ingestion/Entity/SyncJob.php`.
Таблица: `#[ORM\Table(name: 'ingest_sync_jobs')]`.
Реализует `TenantOwnedInterface`.

| Поле | Тип PHP | Колонка БД | Nullable | Default | Инвариант / правило |
|---|---|---|---|---|---|
| `id` | `string` UUID v7 | `id` GUID | нет | — | PK |
| `companyId` | `string` UUID | `company_id` GUID | нет | — | `Assert::uuid`; неизменяем |
| `connectionRef` | `string` | `connection_ref` VARCHAR(255) | нет | — | неизменяем |
| `source` | `IngestSource` enum | `source` VARCHAR(64) | нет | — | enumType=IngestSource (из блока 3); неизменяем |
| `resourceType` | `string` | `resource_type` VARCHAR(100) | нет | — | неизменяем |
| `shopRef` | `string` | `shop_ref` VARCHAR(255) | нет | `''` | неизменяем |
| `kind` | `SyncJobKind` enum | `kind` VARCHAR(32) | нет | — | BACKFILL/INCREMENTAL/MANUAL; неизменяем |
| `status` | `SyncJobStatus` enum | `status` VARCHAR(32) | нет | `OPEN` | меняется только через transition-методы |
| `windowFrom` | `?DateTimeImmutable` | `window_from` DATE | да | `null` | для backfill обязателен; неизменяем после конструктора |
| `windowTo` | `?DateTimeImmutable` | `window_to` DATE | да | `null` | для backfill обязателен; `windowFrom <= windowTo` |
| `parentJobId` | `?string` UUID | `parent_job_id` GUID | да | `null` | null для родительского job'а; неизменяем |
| `progressTotal` | `int` | `progress_total` INTEGER | нет | `0` | количество ожидаемых чанков (для родителя) |
| `progressDone` | `int` | `progress_done` INTEGER | нет | `0` | количество завершённых чанков |
| `cursorSnapshot` | `?string` | `cursor_snapshot` VARCHAR(1024) | да | `null` | курсор на момент старта (для отката бэкфилла) |
| `attempts` | `int` | `attempts` INTEGER | нет | `0` | сколько раз входили в `markRunning` |
| `lastError` | `?string` | `last_error` TEXT | да | `null` | человекочитаемое сообщение последней ошибки |
| `startedAt` | `?DateTimeImmutable` | `started_at` TIMESTAMP(6) | да | `null` | время первого `markRunning` |
| `finishedAt` | `?DateTimeImmutable` | `finished_at` TIMESTAMP(6) | да | `null` | время `markCompleted`/`markFailed` |
| `createdAt` | `DateTimeImmutable` | `created_at` TIMESTAMP(6) | нет | — | |
| `updatedAt` | `DateTimeImmutable` | `updated_at` TIMESTAMP(6) | нет | — | обновляется на каждом transition |

Конструктор: `__construct(string $companyId, string $connectionRef, IngestSource $source, string $resourceType, SyncJobKind $kind, ?DateTimeImmutable $windowFrom = null, ?DateTimeImmutable $windowTo = null, string $shopRef = '', ?string $parentJobId = null)`. Инварианты: для `kind=BACKFILL` обязательны `windowFrom` и `windowTo`, `windowFrom <= windowTo`; для остальных — допустимо null. Status стартует с `OPEN`. createdAt/updatedAt=now.

Поведенческие методы (без тел):
- `markRunning(): void` — переход OPEN→RUNNING (см. §2.4); инкрементирует `attempts`, выставляет `startedAt` если null, `updatedAt=now`. Бросает `SyncJobTransitionException` при недопустимом исходном статусе.
- `markCompleted(?DateTimeImmutable $finishedAt = null): void` — переход RUNNING→COMPLETED; `finishedAt = $finishedAt ?? now`, `updatedAt=now`.
- `markFailed(string $reason, ?DateTimeImmutable $finishedAt = null): void` — переход RUNNING→FAILED; сохраняет `lastError = $reason`, `finishedAt = $finishedAt ?? now`.
- `markCancelled(string $reason): void` — переход OPEN→CANCELLED или RUNNING→CANCELLED; сохраняет `lastError = $reason`.
- `incrementProgress(int $delta = 1): void` — `progressDone += $delta`; `updatedAt=now`. Инвариант: `progressDone <= progressTotal` (если `progressTotal > 0`).
- `setProgressTotal(int $total): void` — выставляет `progressTotal`; используется при сплите на чанки. Инвариант: вызывается один раз для родителя.
- `setCursorSnapshot(string $value): void` — сохраняет `cursorSnapshot` (только один раз, до перехода в RUNNING).

Геттеры: всех полей.

### 2.2 Связи

- Внутри модуля: `SyncJob.parentJobId` — ссылка строкой (не ManyToOne, чтобы не создавать каскадные удаления). Иерархия родитель→дочерние ограничена одной нестингом (job либо родитель с детьми, либо дочерний/самостоятельный).
- Между модулями: нет связей.

### 2.3 Enum

#### `App\Ingestion\Enum\SyncJobKind`

Файл: `src/Ingestion/Enum/SyncJobKind.php`. Backed string.

| Case | value | Когда устанавливается | Метка | Терминальный |
|---|---|---|---|---|
| `BACKFILL` | `backfill` | Загрузка исторических данных за период | «Бэкфилл» | нет |
| `INCREMENTAL` | `incremental` | Регулярный инкремент с курсора | «Инкремент» | нет |
| `MANUAL` | `manual` | Ручной запуск пользователем/админом | «Ручной» | нет |

Методы:
- `label(): string` — человекочитаемая метка (русская строка из таблицы выше).

#### `App\Ingestion\Enum\SyncJobStatus`

Файл: `src/Ingestion/Enum/SyncJobStatus.php`. Backed string.

| Case | value | Когда устанавливается | Метка | Терминальный |
|---|---|---|---|---|
| `OPEN` | `open` | После создания, ещё не запущен | «Создан» | нет |
| `RUNNING` | `running` | `markRunning()` | «Выполняется» | нет |
| `COMPLETED` | `completed` | `markCompleted()` | «Завершён» | да |
| `FAILED` | `failed` | `markFailed()` | «Ошибка» | да |
| `CANCELLED` | `cancelled` | `markCancelled()` | «Отменён» | да |

Методы:
- `label(): string`.
- `isTerminal(): bool` — true для COMPLETED, FAILED, CANCELLED.
- `canTransitionTo(SyncJobStatus $next): bool` — реализует матрицу §2.4.

### 2.4 Матрица переходов статусов

| из / в | OPEN | RUNNING | COMPLETED | FAILED | CANCELLED |
|---|---|---|---|---|---|
| OPEN | ❌ | ✅ | ❌ | ❌ | ✅ |
| RUNNING | ❌ | ✅ (retry, инкрементирует attempts) | ✅ | ✅ | ✅ |
| COMPLETED | ❌ | ❌ | ❌ | ❌ | ❌ |
| FAILED | ❌ | ❌ | ❌ | ❌ | ❌ |
| CANCELLED | ❌ | ❌ | ❌ | ❌ | ❌ |

Запрещённый переход → `SyncJobTransitionException` (§6).

---

## 3. Слой доступа к данным

### 3.1 Repository

#### `App\Ingestion\Repository\IngestCursorRepository`

Файл: `src/Ingestion/Repository/IngestCursorRepository.php`. `final class`, extends `ServiceEntityRepository<IngestCursor>`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findOne(string $companyId, string $connectionRef, string $resourceType, string $shopRef = ''): ?IngestCursor` | Поиск курсора по ключу. Уникальный, может быть null. | да | `?IngestCursor` |
| `getOrCreate(string $companyId, string $connectionRef, string $resourceType, string $shopRef = ''): IngestCursor` | Возвращает существующий или создаёт новый (persist, **без flush**). | да | `IngestCursor` |

flush() запрещён внутри методов Repository. find() без companyId запрещён.

#### `App\Ingestion\Repository\SyncJobRepository`

Файл: `src/Ingestion/Repository/SyncJobRepository.php`. `final class`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findByIdAndCompany(string $id, string $companyId): ?SyncJob` | Поиск по ID с IDOR-проверкой | да | `?SyncJob` |
| `findOpenChildrenOf(string $parentJobId, string $companyId): list<SyncJob>` | Все non-terminal дочерние чанки родителя | да | `list<SyncJob>` |
| `countChildrenByStatus(string $parentJobId, string $companyId, SyncJobStatus $status): int` | Подсчёт детей в статусе (для финализации родителя) | да | `int` |
| `findLatestForResource(string $companyId, string $connectionRef, string $resourceType, string $shopRef = ''): ?SyncJob` | Последний non-terminal job по ресурсу (для защиты от двойного запуска) | да | `?SyncJob` |

### 3.2 Query

N/A — на этом этапе read-моделей через DBAL не вводим, list-эндпоинтов нет.

### 3.3 Индексы

`ingest_cursors`:
- UNIQUE `(company_id, connection_ref, resource_type, shop_ref)` → `uniq_ingest_cursor_key`.
- INDEX `(company_id, connection_ref)` → `idx_ingest_cursor_company_connection`.

`ingest_sync_jobs`:
- INDEX `(company_id, status)` → `idx_ingest_sync_job_company_status`.
- INDEX `(company_id, connection_ref, resource_type, status)` → `idx_ingest_sync_job_resource_status`.
- INDEX `(parent_job_id)` → `idx_ingest_sync_job_parent`.
- INDEX `(company_id, kind, status)` → `idx_ingest_sync_job_kind_status`.

---

## 4. Слой приложения

### 4.1 Action

Все Action — `final class`, единственный `__invoke`. Вход — Command DTO (§4.3). flush() — здесь.

#### `App\Ingestion\Application\Action\StartBackfillAction`

Вход: `StartBackfillCommand`.
Шаги:
1. Получить `SyncJob` родительского запуска для (companyId, connectionRef, resourceType, shopRef) через `SyncJobRepository::findLatestForResource`. Если non-terminal — бросить `ActiveBackfillExistsException` (§6).
2. Создать новый `SyncJob` родителя с `kind=BACKFILL`, заданным окном, `status=OPEN`, `parentJobId=null`.
3. persist + flush.
4. Вернуть id созданного job'а (`string`).

Транзакционность: одна транзакция.

#### `App\Ingestion\Application\Action\SplitJobIntoChunksAction`

Вход: `SplitJobCommand` (`string $parentJobId, string $companyId, int $chunkSizeInDays = 7`).
Шаги:
1. Найти родителя через `findByIdAndCompany`. Если нет — `SyncJobNotFoundException`. Если `kind != BACKFILL` или нет окна — `InvalidJobForSplitException`.
2. Если `progressTotal > 0` — бросить `JobAlreadySplitException` (повторный сплит запрещён).
3. Разбить окно `[windowFrom, windowTo]` на чанки по `$chunkSizeInDays` дней (последний может быть короче).
4. Для каждого чанка создать дочерний `SyncJob` (`kind=BACKFILL`, `parentJobId=родитель`, окно чанка, `status=OPEN`). persist.
5. Вызвать `parent->setProgressTotal($numberOfChunks)`.
6. flush.
7. Для каждого дочернего job'а dispatch `RunSyncChunkMessage` (§5) в транспорт `ingest_fetch`.
8. Вернуть `list<string>` id дочерних job'ов.

Транзакционность: одна транзакция БД; dispatch сообщений — после flush.

#### `App\Ingestion\Application\Action\MarkJobRunningAction`

Вход: `MarkJobRunningCommand` (`string $jobId, string $companyId, ?string $cursorSnapshot = null`).
Шаги:
1. `findByIdAndCompany` → `SyncJobNotFoundException`.
2. Если status не позволяет переход в RUNNING — `SyncJobTransitionException`.
3. Если передан `$cursorSnapshot` и текущий null — установить через `setCursorSnapshot`.
4. `job->markRunning()`.
5. flush.

#### `App\Ingestion\Application\Action\MarkJobCompletedAction`

Вход: `MarkJobCompletedCommand` (`string $jobId, string $companyId`).
Шаги:
1. Загрузить + IDOR.
2. `job->markCompleted()`.
3. Если у job'а есть `parentJobId` — вызвать `MaybeFinalizeParentAction` (см. ниже).
4. flush.

#### `App\Ingestion\Application\Action\MarkJobFailedAction`

Вход: `MarkJobFailedCommand` (`string $jobId, string $companyId, string $reason`).
Шаги:
1. Загрузить + IDOR.
2. `job->markFailed($reason)`.
3. Если есть `parentJobId` — также `MaybeFinalizeParentAction` (родитель завершится, когда все дети терминальны).
4. flush.

#### `App\Ingestion\Application\Action\MaybeFinalizeParentAction` (internal)

Вход: `string $parentJobId, string $companyId`.
Шаги:
1. Загрузить родителя через `findByIdAndCompany`.
2. Подсчитать через `countChildrenByStatus`: COMPLETED, FAILED, CANCELLED.
3. Если сумма терминальных = `progressTotal`:
   - если все COMPLETED → `parent->markCompleted()`;
   - иначе → `parent->markFailed("partial failure: X failed, Y completed")`.
4. Иначе ничего не делать.
5. Без flush (вызывающий Action делает flush).

#### `App\Ingestion\Application\Action\UpdateCursorAction`

Вход: `UpdateCursorCommand` (`string $companyId, string $connectionRef, string $resourceType, string $shopRef, string $newCursorValue, string $syncJobId, ?DateTimeImmutable $fetchedAt = null`).
Шаги:
1. Получить курсор через `IngestCursorRepository::getOrCreate`.
2. `cursor->advance($newCursorValue, $syncJobId, $fetchedAt)`.
3. flush.

### 4.2 Domain Service / Policy

#### `App\Ingestion\Domain\Service\SyncJobTransitionPolicy`

Файл: `src/Ingestion/Domain/Service/SyncJobTransitionPolicy.php`. `final class`, без зависимостей (чистая логика).

Методы (без тел):
- `assertCanTransition(SyncJobStatus $from, SyncJobStatus $to): void` — бросает `SyncJobTransitionException` если переход не разрешён матрицей §2.4. Используется методами Entity внутри `markRunning/Completed/Failed/Cancelled`.

### 4.3 DTO

Command (final readonly class):

#### `App\Ingestion\Application\Command\StartBackfillCommand`

| Поле | Тип | Обязательно | Валидация |
|---|---|---|---|
| `companyId` | string | да | UUID |
| `connectionRef` | string | да | not empty |
| `source` | IngestSource | да | enum |
| `resourceType` | string | да | not empty |
| `shopRef` | string | да | может быть '' |
| `windowFrom` | DateTimeImmutable | да | дата (Y-m-d) |
| `windowTo` | DateTimeImmutable | да | windowFrom <= windowTo |

#### `App\Ingestion\Application\Command\SplitJobCommand`

Поля: `parentJobId: string` UUID, `companyId: string` UUID, `chunkSizeInDays: int` (default 7, range 1..90).

#### `App\Ingestion\Application\Command\MarkJobRunningCommand`

Поля: `jobId: string` UUID, `companyId: string` UUID, `cursorSnapshot: ?string`.

#### `App\Ingestion\Application\Command\MarkJobCompletedCommand`

Поля: `jobId: string` UUID, `companyId: string` UUID.

#### `App\Ingestion\Application\Command\MarkJobFailedCommand`

Поля: `jobId: string` UUID, `companyId: string` UUID, `reason: string` (not empty, max 2000).

#### `App\Ingestion\Application\Command\UpdateCursorCommand`

Поля: `companyId: string` UUID, `connectionRef: string`, `resourceType: string`, `shopRef: string` (можно ''), `newCursorValue: string` not empty, `syncJobId: string` UUID, `fetchedAt: ?DateTimeImmutable`.

### 4.4 Facade

#### `App\Ingestion\Facade\SyncFacade`

Файл: `src/Ingestion/Facade/SyncFacade.php`. `final readonly class`.

Методы (сигнатура + смысл):
- `startBackfill(StartBackfillCommand $command): string` — оборачивает `StartBackfillAction` + сразу зовёт `SplitJobIntoChunksAction` для свежесозданного родителя. Возвращает `parentJobId`.
- `markJobRunning(MarkJobRunningCommand $command): void` — оборачивает `MarkJobRunningAction`.
- `markJobCompleted(MarkJobCompletedCommand $command): void`.
- `markJobFailed(MarkJobFailedCommand $command): void`.
- `updateCursor(UpdateCursorCommand $command): void`.
- `getProgress(string $jobId, string $companyId): SyncJobProgressView` — DTO с полями `jobId, status, progressDone, progressTotal, attempts, lastError`. Read-only для будущего HTTP API (блок 8).

`SyncJobProgressView` — `App\Ingestion\Application\DTO\SyncJobProgressView`, `final readonly class`.

### 4.5 Rate Limit Service

#### `App\Ingestion\Application\Service\IngestRateLimitGuard`

Файл: `src/Ingestion/Application/Service/IngestRateLimitGuard.php`. `final class`. Использует существующий Redis Lock проекта (`LockFactory` + RedisStore).

Метод:
- `acquire(string $sourceKey, int $maxLockMs = 60000): \Symfony\Component\Lock\LockInterface` — берёт Lock с ключом `ingest_rate:{$sourceKey}`. Возвращает `LockInterface`, caller обязан вызвать `release()` после окончания работы.

В этом блоке только инфраструктура (класс + интеграция в DI). Использование — в блоках 5-6.

---

## 5. Асинхронность (Messenger)

### 5.1 Сообщения

#### `App\Ingestion\Message\RunSyncChunkMessage`

Файл: `src/Ingestion/Message/RunSyncChunkMessage.php`. `final readonly class`, реализует `CompanyAwareMessage` (из блока 1).

Поля: `companyId: string` UUID, `jobId: string` UUID. Метод `getCompanyId(): string`.

Только scalar ID. Никаких Entity внутри Message.

### 5.2 Handler

В этом блоке handler **не создаём** — Message только определяется и регистрируется в routing. Handler `RunSyncChunkHandler` появится в блоке 5 (он использует контракт `SourceConnectorInterface`, которого здесь ещё нет).

### 5.3 Routing

`config/packages/messenger.yaml` — добавить:

```yaml
framework:
  messenger:
    transports:
      ingest_fetch:
        dsn: '%env(MESSENGER_TRANSPORT_DSN_SYNC)%'   # алиас на async_sync DSN
        retry_strategy:
          max_retries: 3
          delay: 10000
          multiplier: 2
      ingest_normalize:
        dsn: '%env(MESSENGER_TRANSPORT_DSN_PIPELINE)%'   # алиас на async_pipeline DSN
        retry_strategy:
          max_retries: 3
          delay: 5000
          multiplier: 2
    routing:
      App\Ingestion\Message\RunSyncChunkMessage: ingest_fetch
```

`messenger.yaml` в `test` окружении уже маппит async-транспорты в in-memory — `ingest_fetch`/`ingest_normalize` тоже должны попасть в in-memory под test (добавить override).

### 5.4 Идемпотентность

Идемпотентность handler'а — забота блока 5/6 (он использует `IdempotentHandlerTrait` из PATTERNS §22 с natural key `RunSyncChunkMessage` = jobId).

В блоке 4 достаточно гарантии, что родительский job не может быть сплитнут повторно (см. `JobAlreadySplitException` в `SplitJobIntoChunksAction`).

---

## 6. Обработка ошибок

Все исключения — `final class`, extends `\DomainException` (или подкласс), namespace `App\Ingestion\Exception`.

| Класс | Когда | HTTP-статус | error.code | error.message |
|---|---|---|---|---|
| `SyncJobNotFoundException` | `findByIdAndCompany` вернул null | 404 | `sync_job_not_found` | «Задание синхронизации не найдено» |
| `SyncJobTransitionException` | Запрещённый переход статуса | 409 | `sync_job_invalid_transition` | «Недопустимый переход статуса задания» |
| `ActiveBackfillExistsException` | Попытка запустить второй backfill при non-terminal первом | 409 | `sync_backfill_already_running` | «Бэкфилл по этому ресурсу уже выполняется» |
| `InvalidJobForSplitException` | Сплит вызван для не-backfill job'а или без окна | 422 | `sync_job_not_splittable` | «Это задание нельзя разбить на чанки» |
| `JobAlreadySplitException` | Повторный вызов `SplitJobIntoChunksAction` | 409 | `sync_job_already_split` | «Задание уже разбито на чанки» |
| `CursorNotFoundException` | (зарезервировано на будущее, в блоке 4 не используется) | 404 | `sync_cursor_not_found` | «Курсор не найден» |

HTTP-статусы носят информационный характер на этом этапе (ExceptionListener для Ingestion появится в блоке 8). Здесь — фиксируем коды в исключении (поле/метод `getErrorCode(): string`).

---

## 7. HTTP API (Controller)

N/A — в блоке 4 HTTP-эндпоинты не вводятся. HTTP-управление sync появится в блоке 8.

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | Enum + Entity `IngestCursor`, `SyncJob` + миграция | — | 🔴 | unit на инварианты конструктора |
| B2 | Repository обеих сущностей | B1 | 🟡 | integration на каждый метод + tenant-leak |
| B3 | `SyncJobTransitionPolicy` + transition-методы Entity | B1 | 🟢 | unit на каждый разрешённый/запрещённый переход |
| B4 | Action'ы (Start/Split/MarkRunning/MarkCompleted/MarkFailed/MaybeFinalize/UpdateCursor) | B2, B3 | 🟡 | integration на каждый Action |
| B5 | `SyncFacade` + DTO `SyncJobProgressView` | B4 | 🟢 | integration на каждый метод |
| B6 | Message `RunSyncChunkMessage` + routing | B1 | 🟡 | unit на CompanyAwareMessage |
| B7 | `IngestRateLimitGuard` + DI | — | 🟢 | integration с in-memory Lock |
| B8 | Регрессионные тесты + `ARCHITECTURE.md` | все | 🟢 | tenant-leak end-to-end |

Детализация:

**B1**
- Цель: ввести Entity и enum для cursor/sync job.
- Создаёт: `src/Ingestion/Entity/{IngestCursor.php,SyncJob.php}`, `src/Ingestion/Enum/{SyncJobKind.php,SyncJobStatus.php}`, `src/Ingestion/Exception/{SyncJobTransitionException.php,SyncJobNotFoundException.php,ActiveBackfillExistsException.php,InvalidJobForSplitException.php,JobAlreadySplitException.php,CursorNotFoundException.php}`, миграция `site/migrations/Version20260616120000.php`.
- Меняет: ничего legacy.
- DoD: миграция применяется, schema-validate зелёный, конструкторы валидируют инварианты unit-тестами.
- Зависимости: блок 1 (`TenantOwnedInterface`), блок 3 (`IngestSource`).

**B2**
- Цель: Repository с обязательным companyId.
- Создаёт: `src/Ingestion/Repository/{IngestCursorRepository.php,SyncJobRepository.php}`.
- DoD: tenant-leak тест на каждый read-метод (компания A не видит B).

**B3**
- Цель: явная state machine в Entity + Policy.
- Создаёт: `src/Ingestion/Domain/Service/SyncJobTransitionPolicy.php`.
- Меняет: `SyncJob` (методы markRunning/Completed/Failed/Cancelled зовут Policy).
- DoD: unit-таблица переходов: 5×5 кейсов из §2.4.

**B4-B5-B6-B7** — реализация по §4-§5.

**B8** — добавление раздела «Cursor + SyncJob» в `ARCHITECTURE.md` (новые Entity/Enum/Facade-методы).

---

## 9. Ограничения и запреты

- Не ломать: существующие cron-команды legacy-загрузки, `MarketplaceRawDocument`, `BankImportCursor`, `AdScheduledBatch` — они продолжают работать параллельно.
- Не трогать файлы вне `src/Ingestion/`, `config/packages/messenger.yaml` (только добавление transport+routing), `site/migrations/` (новая миграция).
- Совместимость API: HTTP API нет.
- Миграции: zero-downtime, только CREATE TABLE + индексы. Никаких ALTER на существующих таблицах.
- Performance: пагинации на этом этапе нет (read-методы Repository возвращают одиночные сущности или ограниченный список детей). N+1 защищается тем, что `findOpenChildrenOf` сразу возвращает list, без lazy.
- Безопасность: companyId обязателен на каждом Repository-методе; `CompanyFilter` из блока 1 покрывает обе Entity автоматически (через `TenantOwnedInterface`).

---

## 10. Критерии приёмки

Функциональные:
- [ ] `IngestCursor.advance` обновляет cursorValue, lastSyncJobId, lastFetchedAt, updatedAt.
- [ ] `SyncJob` запрещает переходы, не входящие в матрицу §2.4 (по тестам).
- [ ] `StartBackfillAction` отказывает повторно при наличии non-terminal job'а на ресурсе.
- [ ] `SplitJobIntoChunksAction` корректно разбивает 30-дневное окно на 5 семидневных чанков (последний — 2 дня), отказывает при повторном вызове.
- [ ] При завершении последнего дочернего чанка `MaybeFinalizeParentAction` финализирует родителя (COMPLETED если все ок, FAILED если есть упавшие).
- [ ] `UpdateCursorAction` создаёт курсор при первом вызове и обновляет при последующих.
- [ ] `SyncFacade.startBackfill` создаёт родителя и сразу диспатчит N дочерних сообщений в `ingest_fetch`.
- [ ] `IngestRateLimitGuard.acquire` возвращает удерживаемый Lock; повторный acquire с тем же ключом в пределах TTL — null/throws (в зависимости от реализации Lock).

Технические:
- [ ] `make site-cs-check` зелёный.
- [ ] PHPStan уровень проекта зелёный.
- [ ] `make site-test-unit` + `make site-test-integration` — зелёные.
- [ ] Миграция применяется и откатывается чисто.
- [ ] Tenant-leak тест: компания A не видит SyncJob/IngestCursor компании B через ORM (фильтр включён) и через Repository (даже если фильтр выключен — явный companyId в запросе).
- [ ] Messenger в test окружении использует in-memory transport для `ingest_fetch`/`ingest_normalize`.
- [ ] `ARCHITECTURE.md` обновлён: добавлены новые Entity, Enum, методы Facade.
- [ ] OpenAPI не меняется (HTTP-эндпоинтов нет).

---

## 11. План отката

- Откат миграции `Version20260616120000` — DROP двух новых таблиц. Никаких внешних зависимостей не образовано (`RunSyncChunkMessage` handler ещё не существует).
- Если необходимо отключить feature до удаления кода — выкинуть routing `App\Ingestion\Message\RunSyncChunkMessage` из `messenger.yaml`; Facade останется неиспользованным.
- Зависимости вниз: блок 5 будет использовать `SyncFacade` и `RunSyncChunkMessage`. До старта блока 5 откат блока 4 безопасен.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь файла для каждого нового класса.
- [x] Полная таблица полей обеих Entity с типами, nullable, default, инвариантами.
- [x] Каждый enum case описан: value, когда ставится, метка, терминальность.
- [x] Матрица переходов 5×5 для `SyncJobStatus`.
- [x] Сигнатура каждого метода Repository/Action/Facade.
- [x] Каждый Repository/read-метод принимает `string $companyId`.
- [x] HTTP-контракт — N/A явно отмечен.
- [x] Каждое исключение замаплено на HTTP-статус и `error.code`.
- [x] Индексы перечислены явно с именами.
- [x] Транспорты Messenger (`ingest_fetch`/`ingest_normalize`) указаны.
- [x] Формат данных: ISO 8601 для дат, UUID для id, snake_case для enum value.
- [x] Out of scope зафиксирован (раздел 1.4).
