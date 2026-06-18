# Ingestion Migration — пакет ТЗ для автономной реализации

Это набор технических заданий для миграции загрузки данных в новый модуль `App\Ingestion` платформы VashFinDir. Каждый файл — самодостаточное ТЗ для отдельного блока, исполняемого Claude Code в автономном режиме.

## Карта блоков

```
БЛОК 1 ─── ✅ ВЫПОЛНЕН (каркас + изоляция через CompanyFilter)
БЛОК 2 ─── ✅ ВЫПОЛНЕН (credentials через SecretCodec, плейнтекст)
БЛОК 3 ─── ✅ ВЫПОЛНЕН (единый raw-слой на общем Storage в App\Shared)
       │
       ├─── БЛОК 4: Cursor + SyncJob (оркестрация)       → TASK-04-cursor-syncjob.md
       │           │
       │           └─── БЛОК 5: Контракт + канон + нормализация → TASK-05-connector-canon.md
       │                       │
       │                       └─── БЛОК 6: OzonSellerReportConnector → TASK-06-ozon-connector.md
       │                                   │
       │                                   └─── БЛОК 7: Проекция в P&L  → TASK-07-pnl-projection.md
       │                                               │
       │                                               └─── БЛОК 8: UI пилота + сверка → TASK-08-ui-verification.md
       │                                                           │
       │                                                           └─── БЛОК 9: Shadow + admin → TASK-09-shadow-admin.md
```

## Файлы пакета

| Файл | Блок | Статус | Что делает |
|---|---|---|---|
| `INDEX.md` (этот) | — | — | Обзор пакета и контракты блоков 1-3 |
| `TASK-04-cursor-syncjob.md` | 4 | DONE | Единый Cursor + SyncJob, state machine, чанкинг, rate limit |
| `TASK-05-connector-canon.md` | 5 | DONE | SourceConnectorInterface, канон FinancialTransaction, движок нормализации, NormalizationCompletedEvent |
| `TASK-06-ozon-connector.md` | 6 | DONE | OzonSellerReportConnector + 2 mapper'а (daily report + realization) поверх legacy Ozon API |
| `TASK-07-pnl-projection.md` | 7 | DONE | PLDirtyPeriod, mark dirty → rebuildPeriod, защита закрытых периодов, Redis Lock |
| `SUMMARY-blocks-4-7.md` | 4-7 | DONE | Итоговая сводка по выполненным блокам 4-7 |
| `FOLLOWUP-finance-source-linking.md` | post-deploy | DECISION | Где хранить source/origin-ссылки Finance после приемки и деплоя Ingestion |


## Принципы пакета

- **Strangler Fig.** Новый модуль `App\Ingestion` живёт параллельно legacy, ничего не ломая. Legacy продолжает работать всё время пилота. Гашение legacy — отдельная задача после успеха.
- **Зависимости только вниз по дереву.** Блок N не может стартовать, пока N-1 не в DoD. Внутри одного блока подзадачи имеют свои зависимости (см. таблицу подзадач каждого ТЗ).
- **Изоляция by default.** `CompanyFilter` (блок 1) автоматически фильтрует все Entity модуля через интерфейс-маркер `TenantOwnedInterface`. Каждый Repository-метод дополнительно принимает `string $companyId` — двойная защита.
- **Канон — источник истины, P&L — идемпотентная проекция.** Один `rebuildPeriod` обслуживает и реактивный поток (событие из Ingestion), и императивный (пользователь нажал «пересчитать»).
- **Доверие через сверку.** Пользователь видит не только цифры, но и доказательство их корректности (блок 8). Saapport видит расхождения новой загрузки и старой до того, как клиент переключится (блок 9).
- **Отказ безопасен.** Feature flag — мгновенный откат пилота без миграций (блок 9).

## Контракты блоков 1-3 (уже выполнено)

Используется блоками 4-9. Полные namespace, сигнатуры, поля Entity.

### Изоляция (блок 1)

```
App\Ingestion\Domain\TenantOwnedInterface
    public function getCompanyId(): string

App\Ingestion\Message\CompanyAwareMessage
    public function getCompanyId(): string

App\Ingestion\Infrastructure\Doctrine\CompanyFilter         (Doctrine SQL filter)
App\Ingestion\Infrastructure\Messenger\CompanyFilterMiddleware
App\Ingestion\Infrastructure\Http\CompanyFilterRequestSubscriber
```

**Правила:**
- Любая новая Entity модуля Ingestion реализует `TenantOwnedInterface` → автоматически фильтруется по company.
- Любое новое Message с tenant-нагрузкой реализует `CompanyAwareMessage` → middleware включает фильтр в Messenger-handler.
- Системные запросы (cron по всем тенантам) явно делают `$em->getFilters()->disable('company')` — это видимый маркер «осознанно по всем компаниям».
- Двойная защита: фильтр на ORM + явный `companyId` в каждом Repository-методе.

### Credentials (блок 2)

```
App\Ingestion\Domain\Contract\SecretCodec
    encode(array): string
    decode(string, int $keyVersion): array

App\Ingestion\Infrastructure\Security\PlaintextSecretCodec   (keyVersion = 0, плейнтекст)
App\Ingestion\Infrastructure\Doctrine\EncryptedJsonType      (Doctrine type, использует SecretCodec)
App\Ingestion\Infrastructure\Security\SecretPayloadMasker    (для логов и API responses)
App\Ingestion\Infrastructure\Credential\LegacyMarketplaceCredentialReader

App\Ingestion\Entity\IngestionCredential
    id, companyId, connectionRef, type='api_credentials',
    payload (зашифровано через EncryptedJsonType), keyVersion, expiresAt,
    createdAt, updatedAt
    методы: replacePayload(array, int $keyVersion, ?DateTimeImmutable $expiresAt = null)

App\Ingestion\Facade\CredentialFacade
    store(string $companyId, string $connectionRef, array $payload): void
    read(string $companyId, string $connectionRef): array     (throws CredentialNotFoundException)
    readMasked(string $companyId, string $connectionRef): array
```

**Шифрование отложено** (`SodiumFieldEncryptionService` не работает). Текущий codec — плейнтекст. Шов готов: позже добавится `SodiumSecretCodec` с `keyVersion >= 1`, без правок бизнес-кода.

### Raw-слой (блок 3)

Storage вынесен в `App\Shared` для переиспользования всем проектом:

```
App\Shared\Service\Storage\ObjectStorageInterface
App\Shared\Service\Storage\LocalObjectStorage          (делегирует в существующий StorageService)
App\Shared\Service\Storage\FlysystemS3ObjectStorage
App\Shared\Service\Storage\ObjectStorageFactory
App\Shared\Service\Storage\StoredObject
App\Shared\Service\Storage\ObjectStorageException
```

Default driver = `local` через env `APP_OBJECT_STORAGE_DRIVER=local`. S3 включается явно. Legacy продолжает использовать `StorageService` напрямую.

```
App\Ingestion\Enum\IngestSource: OZON, WILDBERRIES, OZON_PERFORMANCE
App\Ingestion\Enum\RawNormalizationStatus: PENDING, DONE, FAILED

App\Ingestion\Entity\IngestRawRecord
    id, companyId, connectionRef, shopRef, source: IngestSource,
    resourceType, externalId, storagePath, hash, byteSize,
    fetchedAt, lastSeenAt, syncJobId,
    normalizationStatus: RawNormalizationStatus = PENDING,
    createdAt, updatedAt
    методы: markSeen(?DateTimeImmutable)
    natural key: (companyId, source, externalId, hash)

App\Ingestion\DTO\RawBatch (final readonly)
    companyId, connectionRef, shopRef, source: IngestSource,
    resourceType, externalId, syncJobId,
    fetchedAt: DateTimeImmutable,
    rows: iterable<array>

App\Ingestion\Facade\RawStorageFacade
    store(RawBatch $batch): list<IngestRawRecord>
    read(string $rawRecordId, string $companyId): iterable<array>
```

**Путь в S3:** `{companyId}/{source}/{shopRef}/{resourceType}/{yyyy}/{mm}/{dd}/{syncJobId}.ndjson.gz`.
**Дедуп:** перед записью сверка hash; повтор → новый файл НЕ пишется, `lastSeenAt` обновляется.

## Дополнения к контрактам, которые делают блоки 4-9

Эти небольшие правки уже учтены в соответствующих ТЗ, но фиксирую для прозрачности:

| Где | Что | Откуда |
|---|---|---|
| `IngestRawRecord` | Добавить методы `markNormalizationDone()`, `markNormalizationFailed()` | блок 5 (NormalizeRawRecordAction) |
| `IngestRawRecordRepository` | Добавить методы `findByIdAndCompany`, `findByCompanyAndPeriod` | блоки 5, 8 |
| `IngestionCredential` | Без изменений | — |
| `CredentialFacade` | Без изменений | — |
| `RawStorageFacade` | Без изменений | — |

Изменения в Entity локальные, без миграций (только PHP-методы).

## Как использовать пакет

1. **Сохранить пакет** в `docs/tasks/ingestion-migration/` репозитория (или равнозначное место).
2. **Для каждого блока:**
   - Открыть `TASK-0N-...md`.
   - Передать содержимое Claude Code (или разработчику) как полное самостоятельное задание.
   - Выполнить, прогнать DoD (раздел §10 каждого ТЗ).
   - Закрыть PR, обновить `ARCHITECTURE.md` если требуется.
3. **Параллельность:** блоки 2, 3 уже шли параллельно. Блоки 4-9 строго последовательны.
4. **Перед каждым блоком:** убедиться, что предыдущий в DoD (включая tenant-leak тесты и обновлённую `ARCHITECTURE.md`).

## Что НЕ входит в этот пакет (отдельные задачи после Ozon-пилота)

- Гашение старого Ozon-пайплайна (после shadow-сверки и переключения).
- Миграция WB финансов в Ingestion.
- Миграция Inventory (остатки) в Ingestion.
- Миграция Ads (схлопывание двух параллельных пайплайнов).
- Банковские интеграции в Ingestion.
- МойСклад в Ingestion.
- Полноценное RBAC поверх admin.
- Уведомления о расхождениях (email/Telegram).
- Включение реального шифрования credentials (`SodiumSecretCodec`).
- Реальное переключение продакшена на S3 (`APP_OBJECT_STORAGE_DRIVER=s3`).

Каждая из этих задач — отдельная итерация после успеха Ozon-пилота. Они переиспользуют те же контракты блока 5 (`SourceConnectorInterface`, канон, normalize-pipeline) — это и есть выигрыш от инвестиции в фундамент.

## Связанная документация

- `ARCHITECTURE.md` — общая архитектура проекта (обновляется по мере закрытия блоков).
- `PATTERNS.md` — паттерны кодирования (применяются в каждом ТЗ).
- `CLAUDE.md` — конвенции backend (`src/Ingestion`).
- `CLAUDE_frontend.md` — конвенции frontend (для блоков 8, 9).
- `docs/ingestion/ozon-mapping.md` — создаётся в блоке 6.
- `docs/ingestion/verification-ui.md` — создаётся в блоке 8.
- `docs/ingestion/runbook.md` — создаётся в блоке 9 (инструкция саппорту).
