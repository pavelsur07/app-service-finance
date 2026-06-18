# TASK — БЛОК 5: Ingestion · Контракт коннектора + канон + нормализация

## 0. Сводка

- **Бизнес-цель.** Ввести единый контракт `SourceConnectorInterface` и канонический финансовый домен (`FinancialTransaction`), к которому подключаются все будущие источники. Это лекарство от дублирования processors/clients/mappers по источникам в legacy. В блоке 5 — на заглушечном `FakeConnector`, реальный Ozon — в блоке 6.
- **Модуль.** `App\Ingestion`.
- **Тип.** feature (центральный блок).
- **Ветка.** `feature/ingestion-05-connector-canon-normalize`.
- **Подзадачи.** B1 Domain contracts · B2 Money + канон · B3 Реестр коннекторов · B4 Маппер-контракт · B5 NormalizeRawRecordAction · B6 RunSyncChunkHandler · B7 FakeConnector + FakeMapper · B8 Доменное событие + сверка · B9 Тесты.
- **Затрагивает другие модули.** Нет.
- **Требует миграции БД.** Да (3 новые таблицы).
- **Меняет публичный API.** Нет (HTTP API в блоке 8).

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- Блок 3 готов: `IngestRawRecord` + `RawStorageFacade`.
- Блок 4 готов: `SyncJob`, `IngestCursor`, `SyncFacade`, `RunSyncChunkMessage` (без handler'а).
- В Legacy: отдельные processors/actions для Ozon/WB × sales/returns/costs (`Marketplace/Application/Processor/*`), отдельные мапперы на каждом источнике.

### 1.2 Желаемое состояние

- Один `SourceConnectorInterface`: `source/capabilities/discoverShops/pull/push`.
- Реестр коннекторов через тег `app.ingestion.connector`.
- Канон `FinancialTransaction` с типизированным enum `TransactionType`; декомпозиция строки отчёта на несколько канонических транзакций с общим `operationGroupId`.
- `Money` ValueObject (`amountMinor: int` + `currency: string`), запрет смешения валют.
- `NormalizeRawRecordAction` читает RawRecord, зовёт маппер коннектора (чистая функция), upsert в канон по natural key.
- `RunSyncChunkHandler` — handler для `RunSyncChunkMessage` (из блока 4): получает коннектор → fetch → store raw → dispatch `NormalizeRawRecordMessage`.
- Доменное событие `NormalizationCompletedEvent` публикуется после успешной нормализации (для блока 7).
- После нормализации — сверка суммы канона по `operationGroupId` против контрольной суммы из raw-строки. При расхождении — запись в `NormalizationIssue` и лог.
- `FakeConnector` + `FakeMapper` для тестирования контракта без реального источника.

### 1.3 In scope

- Domain: `SourceConnectorInterface`, `Capability`, `PullRequest`, `PullResult`, `PushRequest`, `PushResult`, `ShopDescriptor`, `SourceMapperInterface`, `MappedTransaction` DTO.
- `ConnectorRegistry` (поверх tagged services).
- `Money` ValueObject + `MoneyMismatchException`.
- Канон Entity: `FinancialTransaction`, `Counterparty` (минимальный — заглушка), `NormalizationIssue` (для сверки сумм).
- Enum: `TransactionType`, `TransactionDirection`, `NormalizationIssueKind`.
- Action: `NormalizeRawRecordAction`, `UpsertFinancialTransactionAction`, `RecordNormalizationIssueAction`.
- Handler: `RunSyncChunkHandler` (для блока 4 Message), `NormalizeRawRecordHandler`.
- Message: `NormalizeRawRecordMessage`.
- Доменное событие: `NormalizationCompletedEvent` + публикация через `EventDispatcher`.
- Fake: `FakeConnector`, `FakeMapper`, регистрация через тег под `dev`/`test` (но не `prod`).
- Расширение Facade: `RawStorageFacade` уже есть. Добавляем `IngestionFacade::getTransactions` (используется блоком 7).

### 1.4 Out of scope

- Реальный Ozon — блок 6.
- Подписка на `NormalizationCompletedEvent` со стороны P&L — блок 7.
- HTTP API — блок 8.
- Admin / UI — блок 8-9.
- `Push`-операции (отчёт комиссионера в МойСклад) — после пилота.

### 1.5 Допущения и открытые вопросы

- Допущение: `Counterparty` в этом блоке — минимальная заглушка (id, companyId, name, externalKey). Полная модель — позже, отдельным блоком при необходимости.
- Допущение: события публикуются через стандартный `Symfony\Contracts\EventDispatcher\EventDispatcherInterface`; в блоке 5 — только публикация, подписчики нет.

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

#### `App\Ingestion\Entity\FinancialTransaction`

Файл: `src/Ingestion/Entity/FinancialTransaction.php`.
Таблица: `#[ORM\Table(name: 'ingest_financial_transactions')]`.
Реализует `TenantOwnedInterface`.

| Поле | Тип PHP | Колонка БД | Nullable | Default | Инвариант |
|---|---|---|---|---|---|
| `id` | string UUID v7 | `id` GUID | нет | — | PK |
| `companyId` | string UUID | `company_id` GUID | нет | — | `Assert::uuid`; неизменяем |
| `connectionRef` | string | `connection_ref` VARCHAR(255) | нет | — | неизменяем |
| `shopRef` | string | `shop_ref` VARCHAR(255) | нет | `''` | неизменяем |
| `source` | IngestSource | `source` VARCHAR(64) | нет | — | enumType=IngestSource; неизменяем |
| `externalId` | string | `external_id` VARCHAR(255) | нет | — | id операции в источнике; неизменяем |
| `externalUpdatedAt` | DateTimeImmutable | `external_updated_at` TIMESTAMP(6) | нет | — | для версионирования upsert |
| `operationGroupId` | string UUID | `operation_group_id` GUID | нет | — | группирует декомпозированные транзакции одной строки отчёта |
| `type` | TransactionType | `type` VARCHAR(64) | нет | — | enumType=TransactionType |
| `direction` | TransactionDirection | `direction` VARCHAR(8) | нет | — | enumType=TransactionDirection (`IN`/`OUT`) |
| `amountMinor` | int | `amount_minor` BIGINT | нет | — | минорные единицы; signed value допускается; `direction` остаётся нормализованной классификацией потока |
| `currency` | string | `currency` CHAR(3) | нет | — | ISO 4217 (RUB/USD/KZT/...) |
| `occurredAt` | DateTimeImmutable | `occurred_at` TIMESTAMP(6) | нет | — | UTC; **используется для определения периода в P&L** |
| `sourceTz` | string | `source_tz` VARCHAR(64) | нет | `'UTC'` | TZ источника для отображения |
| `orderRef` | ?string | `order_ref` VARCHAR(255) | да | null | id заказа в источнике, если применимо |
| `payoutRef` | ?string | `payout_ref` VARCHAR(255) | да | null | id выплаты, если транзакция в неё вошла |
| `counterpartyId` | ?string UUID | `counterparty_id` GUID | да | null | ссылка на Counterparty в каноне |
| `description` | ?string | `description` TEXT | да | null | человекочитаемое описание |
| `sourceData` | array | `source_data` JSONB | нет | `[]` | поля источника для UI/аудита |
| `rawRecordId` | string UUID | `raw_record_id` GUID | нет | — | ссылка на IngestRawRecord |
| `version` | int | `version` INTEGER | нет | 0 | оптимистичная блокировка (`#[ORM\Version]`) |
| `createdAt` | DateTimeImmutable | `created_at` TIMESTAMP(6) | нет | — | |
| `updatedAt` | DateTimeImmutable | `updated_at` TIMESTAMP(6) | нет | — | |

Natural key для upsert: `(company_id, source, external_id, type)`.

Конструктор: `__construct(string $companyId, string $connectionRef, string $shopRef, IngestSource $source, string $externalId, DateTimeImmutable $externalUpdatedAt, string $operationGroupId, TransactionType $type, TransactionDirection $direction, Money $money, DateTimeImmutable $occurredAt, string $rawRecordId, ?string $orderRef = null, ?string $payoutRef = null, ?string $counterpartyId = null, ?string $description = null, array $sourceData = [], string $sourceTz = 'UTC')`.

Инварианты:
- `Assert::uuid($companyId)`, `Assert::uuid($operationGroupId)`, `Assert::uuid($rawRecordId)`.
- `$money->amountMinor()` хранится в minor units и может быть положительным, отрицательным или нулевым.
- `$money->currency()` — 3 буквы, uppercase.
- `Assert::length($money->currency(), 3)`.

Поведенческие методы:
- `replaceFromNewerVersion(Money $money, TransactionType $type, TransactionDirection $direction, DateTimeImmutable $occurredAt, DateTimeImmutable $externalUpdatedAt, ?string $orderRef, ?string $payoutRef, ?string $counterpartyId, ?string $description, array $sourceData): void` — обновляет поля, если `$externalUpdatedAt > $this->externalUpdatedAt`; иначе бросает `StaleTransactionUpdateException`.
- `oldOccurredAt(): DateTimeImmutable` — возвращает `occurredAt` до replace (для детектора смены периода в блоке 7).

#### `App\Ingestion\Entity\Counterparty`

Файл: `src/Ingestion/Entity/Counterparty.php`.
Таблица: `#[ORM\Table(name: 'ingest_counterparties')]`.
Реализует `TenantOwnedInterface`.

Минимальная заглушка (полная модель — отдельным блоком):

| Поле | Тип | Колонка | Nullable | Инвариант |
|---|---|---|---|---|
| `id` | string UUID v7 | `id` GUID | нет | PK |
| `companyId` | string UUID | `company_id` GUID | нет | `Assert::uuid`; неизменяем |
| `source` | IngestSource | `source` VARCHAR(64) | нет | enumType |
| `externalKey` | string | `external_key` VARCHAR(255) | нет | id контрагента в источнике |
| `name` | string | `name` VARCHAR(500) | нет | not empty |
| `createdAt` | DateTimeImmutable | `created_at` TIMESTAMP(6) | нет | |
| `updatedAt` | DateTimeImmutable | `updated_at` TIMESTAMP(6) | нет | |

Natural key: `(company_id, source, external_key)`.

#### `App\Ingestion\Entity\NormalizationIssue`

Файл: `src/Ingestion/Entity/NormalizationIssue.php`.
Таблица: `#[ORM\Table(name: 'ingest_normalization_issues')]`.
Реализует `TenantOwnedInterface`.
Append-only (никаких setter'ов кроме `markResolved`).

| Поле | Тип | Колонка | Nullable | Инвариант |
|---|---|---|---|---|
| `id` | string UUID v7 | `id` GUID | нет | PK |
| `companyId` | string UUID | `company_id` GUID | нет | `Assert::uuid` |
| `rawRecordId` | string UUID | `raw_record_id` GUID | нет | |
| `operationGroupId` | ?string UUID | `operation_group_id` GUID | да | nullable если ошибка не связана с группой |
| `kind` | NormalizationIssueKind | `kind` VARCHAR(64) | нет | enumType |
| `details` | array | `details` JSONB | нет | контекст ошибки (что именно не сошлось) |
| `resolvedAt` | ?DateTimeImmutable | `resolved_at` TIMESTAMP(6) | да | null = открыто |
| `createdAt` | DateTimeImmutable | `created_at` TIMESTAMP(6) | нет | |

Методы:
- `markResolved(?DateTimeImmutable $at = null): void` — выставляет `resolvedAt`.

### 2.2 Связи

- `FinancialTransaction.rawRecordId` → `IngestRawRecord.id` (string, без ManyToOne; PATTERNS §11).
- `FinancialTransaction.counterpartyId` → `Counterparty.id` (string).
- `NormalizationIssue.rawRecordId` → `IngestRawRecord.id` (string).

### 2.3 Enum

#### `App\Ingestion\Enum\TransactionType`

Backed string. Файл: `src/Ingestion/Enum/TransactionType.php`.

| Case | value | Когда устанавливается | Метка | Терминальный |
|---|---|---|---|---|
| `SALE` | `sale` | Продажа товара | «Продажа» | нет |
| `REFUND` | `refund` | Возврат покупателю | «Возврат» | нет |
| `COMMISSION` | `commission` | Комиссия маркетплейса | «Комиссия» | нет |
| `LOGISTICS` | `logistics` | Логистика, FBO, доставка | «Логистика» | нет |
| `STORAGE` | `storage` | Хранение | «Хранение» | нет |
| `LAST_MILE` | `last_mile` | Последняя миля | «Последняя миля» | нет |
| `ACCEPTANCE` | `acceptance` | Приёмка | «Приёмка» | нет |
| `ADVERTISING` | `advertising` | Реклама (списание МП) | «Реклама» | нет |
| `PENALTY` | `penalty` | Штраф МП | «Штраф» | нет |
| `BONUS` | `bonus` | Бонус/компенсация | «Бонус» | нет |
| `ACQUIRING` | `acquiring` | Эквайринг | «Эквайринг» | нет |
| `ADJUSTMENT` | `adjustment` | Корректировка | «Корректировка» | нет |
| `PAYOUT` | `payout` | Выплата от МП | «Выплата» | нет |
| `DEPOSIT` | `deposit` | Поступление на банковский счёт | «Поступление» | нет |
| `TRANSFER` | `transfer` | Перевод между счетами | «Перевод» | нет |
| `TAX` | `tax` | Налог | «Налог» | нет |
| `FEE` | `fee` | Общий сбор (если не подошёл специфичный тип) | «Сбор» | нет |
| `OTHER` | `other` | Не классифицировано | «Прочее» | нет |

Метод: `label(): string`.

#### `App\Ingestion\Enum\TransactionDirection`

Backed string.

| Case | value | Когда устанавливается | Метка |
|---|---|---|---|
| `IN` | `in` | Деньги пришли тенанту | «Поступление» |
| `OUT` | `out` | Деньги ушли от тенанта | «Списание» |

#### `App\Ingestion\Enum\NormalizationIssueKind`

Backed string.

| Case | value | Когда | Метка |
|---|---|---|---|
| `SUM_MISMATCH` | `sum_mismatch` | Сумма канона ≠ контрольной из raw | «Сумма не сошлась» |
| `MAPPER_FAILURE` | `mapper_failure` | Маппер бросил исключение | «Ошибка маппинга» |
| `UNKNOWN_FIELD` | `unknown_field` | В raw появилось неизвестное маппер-полю значение | «Неизвестное поле» |
| `CURRENCY_MISMATCH` | `currency_mismatch` | В одной строке смешаны валюты | «Несовпадение валют» |

#### `App\Ingestion\Enum\Capability`

Backed string. Файл: `src/Ingestion/Enum/Capability.php`.

| Case | value | Смысл |
|---|---|---|
| `CAN_DISCOVER_SHOPS` | `can_discover_shops` | Коннектор умеет перечислять shop'ы аккаунта |
| `CAN_PULL` | `can_pull` | Коннектор умеет тянуть данные |
| `CAN_PUSH` | `can_push` | Коннектор умеет отправлять документы наружу |

Метод: `label(): string`.

### 2.4 Матрица переходов статусов

`FinancialTransaction.normalizationStatus` — отсутствует (нормализация — атрибут RawRecord, не транзакции).

`NormalizationIssue`: переход `open → resolved` через `markResolved()`. Обратного перехода нет.

---

## 3. Слой доступа к данным

### 3.1 Repository

#### `App\Ingestion\Repository\FinancialTransactionRepository`

`final class`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findByNaturalKey(string $companyId, IngestSource $source, string $externalId, TransactionType $type): ?FinancialTransaction` | Поиск по natural key для upsert | да | `?FinancialTransaction` |
| `findByOperationGroup(string $companyId, string $operationGroupId): list<FinancialTransaction>` | Все транзакции группы (для сверки сумм) | да | `list<FinancialTransaction>` |
| `iterateByPeriod(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $shopRef = null): iterable<FinancialTransaction>` | Чтение канона за период — для блока 7 (rebuildPeriod) | да | `iterable<FinancialTransaction>` |
| `findByRawRecordId(string $companyId, string $rawRecordId): list<FinancialTransaction>` | Транзакции, рождённые из конкретного RawRecord | да | `list<FinancialTransaction>` |

`iterateByPeriod` использует QueryBuilder с `iterate()` или `toIterable()` для больших периодов.

#### `App\Ingestion\Repository\CounterpartyRepository`

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findByNaturalKey(string $companyId, IngestSource $source, string $externalKey): ?Counterparty` | Upsert lookup | да | `?Counterparty` |
| `getOrCreate(string $companyId, IngestSource $source, string $externalKey, string $name): Counterparty` | persist без flush | да | `Counterparty` |

#### `App\Ingestion\Repository\NormalizationIssueRepository`

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findOpenByRawRecord(string $companyId, string $rawRecordId): list<NormalizationIssue>` | Открытые issue по конкретному raw | да | `list<NormalizationIssue>` |
| `countOpenForCompany(string $companyId): int` | Счётчик для admin (блок 9) | да | `int` |

### 3.2 Query

N/A в этом блоке (read-модели для отчётов — блок 8).

### 3.3 Индексы

`ingest_financial_transactions`:
- UNIQUE `(company_id, source, external_id, type)` → `uniq_ftx_natural_key`.
- INDEX `(company_id, occurred_at)` → `idx_ftx_company_occurred`.
- INDEX `(company_id, shop_ref, occurred_at)` → `idx_ftx_company_shop_occurred`.
- INDEX `(company_id, operation_group_id)` → `idx_ftx_company_group`.
- INDEX `(company_id, type, occurred_at)` → `idx_ftx_company_type_occurred`.
- INDEX `(company_id, raw_record_id)` → `idx_ftx_company_raw`.

`ingest_counterparties`:
- UNIQUE `(company_id, source, external_key)` → `uniq_counterparty_natural`.

`ingest_normalization_issues`:
- INDEX `(company_id, kind, resolved_at)` → `idx_norm_issue_company_kind_resolved`.
- INDEX `(company_id, raw_record_id)` → `idx_norm_issue_company_raw`.

---

## 4. Слой приложения

### 4.1 Domain Contracts

#### `App\Ingestion\Domain\Contract\SourceConnectorInterface`

Файл: `src/Ingestion/Domain/Contract/SourceConnectorInterface.php`.

Методы:
- `source(): IngestSource` — возвращает источник, который обслуживает коннектор.
- `capabilities(): list<Capability>` — список флагов capability.
- `discoverShops(string $companyId, string $connectionRef): list<ShopDescriptor>` — перечислить shop'ы. Может бросить `ConnectorAuthException`, `ConnectorTransientException`.
- `pull(PullRequest $request): PullResult` — один чанк pull'а. RawBatch внутри PullResult.
- `push(PushRequest $request): PushResult` — отправка документа наружу. Если `Capability::CAN_PUSH` не заявлена — бросать `UnsupportedCapabilityException`.

#### `App\Ingestion\Domain\Contract\SourceMapperInterface`

Файл: `src/Ingestion/Domain/Contract/SourceMapperInterface.php`.

Чистая функция (без БД/HTTP).

Методы:
- `source(): IngestSource`.
- `resourceTypes(): list<string>` — список поддерживаемых resourceType.
- `map(IngestRawRecord $rawRecord, iterable $rows): list<MappedTransaction>` — для каждой строки raw возвращает список mapped-транзакций (декомпозиция: одна строка отчёта = N канонических транзакций с общим `operationGroupId`).
- `controlSum(iterable $rows): list<MappedControlSum>` — контрольные суммы по строкам для сверки.

#### DTO

`App\Ingestion\Application\DTO\ShopDescriptor` (final readonly):
- `externalId: string`, `name: string`, `currency: string`, `metadata: array`.

`App\Ingestion\Application\DTO\PullRequest` (final readonly):
- `companyId: string`, `connectionRef: string`, `shopRef: string`, `resourceType: string`, `cursorValue: ?string`, `windowFrom: ?DateTimeImmutable`, `windowTo: ?DateTimeImmutable`, `syncJobId: string`.

`App\Ingestion\Application\DTO\PullResult` (final readonly):
- `rawBatch: RawBatch` (из блока 3), `nextCursorValue: ?string` (null = конец), `hasMore: bool`.

`App\Ingestion\Application\DTO\PushRequest`, `PushResult` — каркас на будущее, минимальные поля (`companyId`, `connectionRef`, `documentType`, `payload`, `idempotencyKey`). В блоке 5 используется только сигнатурой.

`App\Ingestion\Application\DTO\MappedTransaction` (final readonly):
- `externalId: string`, `externalUpdatedAt: DateTimeImmutable`, `operationGroupId: string`, `type: TransactionType`, `direction: TransactionDirection`, `money: Money`, `occurredAt: DateTimeImmutable`, `sourceTz: string`, `orderRef: ?string`, `payoutRef: ?string`, `counterpartyExternalKey: ?string`, `counterpartyName: ?string`, `description: ?string`, `sourceData: array`.

`App\Ingestion\Application\DTO\MappedControlSum` (final readonly):
- `operationGroupId: string`, `currency: string`, `amountMinor: int`.

### 4.2 Money Value Object

#### `App\Shared\Domain\ValueObject\Money`

Файл: `src/Shared/Domain/ValueObject/Money.php`. `final readonly class`.

Поля: `amountMinor: int`, `currency: string` (3 буквы uppercase).

Методы:
- `static fromMinor(int $amountMinor, string $currency): self`.
- `add(self $other): self` — `MoneyMismatchException` при разных currency.
- `subtract(self $other): self` — `MoneyMismatchException` при разных currency; результат может быть отрицательным.
- `negate(): self` — возвращает значение с противоположным знаком.
- `compareTo(self $other): int` — сравнение только в одной currency, иначе `MoneyMismatchException`.
- `isZero(): bool`.
- `amountMinor(): int`, `currency(): string`.

Инвариант: `$amountMinor` — int signed minor units, `$currency` — `Assert::length(3)`, `Assert::regex('/^[A-Z]{3}$/')`. Если конкретный бизнес-сценарий требует неотрицательную сумму, проверка должна жить в этом сценарии, а не в универсальном `Money`.

### 4.3 ConnectorRegistry

#### `App\Ingestion\Domain\Service\ConnectorRegistry`

`final class`. Конструктор принимает `iterable<SourceConnectorInterface>` через `!tagged_iterator app.ingestion.connector`.

Методы:
- `get(IngestSource $source): SourceConnectorInterface` — `ConnectorNotFoundException` если нет.
- `has(IngestSource $source): bool`.

#### `App\Ingestion\Domain\Service\MapperRegistry`

`final class`. Конструктор: `iterable<SourceMapperInterface>` через `!tagged_iterator app.ingestion.mapper`.

Метод:
- `get(IngestSource $source, string $resourceType): SourceMapperInterface` — `MapperNotFoundException`.

### 4.4 Action

#### `App\Ingestion\Application\Action\NormalizeRawRecordAction`

Файл: `src/Ingestion/Application/Action/NormalizeRawRecordAction.php`. `final class`.

Вход: `NormalizeRawRecordCommand` (`string $rawRecordId, string $companyId`).
Шаги:
1. Загрузить `IngestRawRecord` через `IngestRawRecordRepository::findByIdAndCompany`. Нет → `RawRecordNotFoundException` (уже есть из блока 3).
2. Если `normalizationStatus = DONE` — return (идемпотентность).
3. Получить mapper через `MapperRegistry::get($rawRecord->getSource(), $rawRecord->getResourceType())`.
4. Прочитать строки из S3 через `RawStorageFacade::read($rawRecordId, $companyId)`.
5. Вызвать `$mapper->map($rawRecord, $rows)` → `list<MappedTransaction>`. Если бросило — поймать, записать `NormalizationIssue` с `kind=MAPPER_FAILURE`, пометить raw как `FAILED`, return.
6. Для каждой `MappedTransaction`:
   - Получить/создать `Counterparty` через `CounterpartyRepository::getOrCreate` если есть `counterpartyExternalKey`.
   - Вызвать `UpsertFinancialTransactionAction` с переданными полями и `rawRecordId`.
   - Запомнить старый `occurredAt` и новый — для события (см. шаг 9).
7. Перечитать строки и вычислить `$mapper->controlSum($rows)`. Для каждой `MappedControlSum`:
   - Сумма канона по `operationGroupId` (из репо) vs `controlSum.amountMinor` той же валюты.
   - Если расхождение > 0 минорных единиц → `RecordNormalizationIssueAction` с `kind=SUM_MISMATCH`, details (expected/actual/operationGroupId).
8. Пометить raw `markNormalizationDone()` (новый метод на `IngestRawRecord` — см. §2.1 ниже).
9. Собрать `list<AffectedPeriod>` (даты `occurredAt`, старая и новая для каждой обновлённой транзакции) и опубликовать `NormalizationCompletedEvent`.
10. flush.

Транзакционность: одна транзакция БД. Dispatch события — в `kernel.terminate` или после flush.

**Дополнение к Entity `IngestRawRecord` (блок 3):** добавить методы `markNormalizationDone(): void` (status=DONE, updatedAt=now), `markNormalizationFailed(): void` (status=FAILED). Это правка одной строки в Entity — допустимая в рамках блока 5, поскольку блок 3 ещё не использует эти переходы.

#### `App\Ingestion\Application\Action\UpsertFinancialTransactionAction`

Вход: `UpsertFinancialTransactionCommand` (`string $companyId`, `string $connectionRef`, `string $shopRef`, `IngestSource $source`, `MappedTransaction $mapped`, `string $rawRecordId`, `?string $counterpartyId`).

Шаги:
1. `findByNaturalKey($companyId, $source, $mapped->externalId, $mapped->type)`.
2. Если null — создать `FinancialTransaction` через конструктор, persist. Запомнить `null` как `oldOccurredAt`.
3. Если найден — вызвать `replaceFromNewerVersion(...)`. Запомнить `oldOccurredAt` до replace. Если `replaceFromNewerVersion` бросил `StaleTransactionUpdateException` — skip (старая версия пришла после новой), return null.
4. Вернуть DTO `UpsertResult { transactionId: string, oldOccurredAt: ?DateTimeImmutable, newOccurredAt: DateTimeImmutable, periodChanged: bool }`.

**Без flush** (NormalizeRawRecordAction делает flush).

#### `App\Ingestion\Application\Action\RecordNormalizationIssueAction`

Вход: `RecordNormalizationIssueCommand` (`string $companyId, string $rawRecordId, ?string $operationGroupId, NormalizationIssueKind $kind, array $details`).

Шаги:
1. Создать `NormalizationIssue` через конструктор.
2. persist.
3. Лог уровня `WARNING` через `LoggerInterface`.
4. Без flush.

### 4.5 Handler'ы Messenger

#### `App\Ingestion\MessageHandler\RunSyncChunkHandler`

Handler для `RunSyncChunkMessage` (блок 4). `final class` с `#[AsMessageHandler]`. Использует `IdempotentHandlerTrait` (PATTERNS §22) с natural key `jobId`.

Шаги:
1. `findByIdAndCompany($jobId, $companyId)`. Если status терминальный — return.
2. `SyncFacade.markJobRunning(...)`.
3. Достать коннектор: `connectorRegistry->get($job->getSource())`.
4. Acquire rate-limit guard (`IngestRateLimitGuard::acquire($job->getSource()->value . ':' . $job->getConnectionRef())`).
5. Собрать `PullRequest` (из полей job'а + текущего курсора через `IngestCursorRepository::findOne`).
6. `$result = $connector->pull($request)`.
7. `RawStorageFacade::store($result->rawBatch)` → `list<IngestRawRecord>`.
8. Для каждого созданного RawRecord — dispatch `NormalizeRawRecordMessage(rawRecordId, companyId)` в `ingest_normalize`.
9. `SyncFacade.updateCursor(...)` с `$result->nextCursorValue` если не null.
10. `SyncFacade.markJobCompleted(...)`.
11. Release lock.

При исключении:
- `ConnectorAuthException` → `markJobFailed("auth")`, `UnrecoverableMessageHandlingException` (Messenger не ретраит).
- `ConnectorTransientException` → НЕ `markJobFailed`, бросить наружу (Messenger ретраит).
- любое другое → `markJobFailed($e->getMessage())`, бросить наружу.

#### `App\Ingestion\Message\NormalizeRawRecordMessage`

`final readonly class`, реализует `CompanyAwareMessage`.
Поля: `rawRecordId: string` UUID, `companyId: string` UUID. Метод `getCompanyId()`.

#### `App\Ingestion\MessageHandler\NormalizeRawRecordHandler`

`final class`, `#[AsMessageHandler]`, IdempotentHandlerTrait с key `rawRecordId`.

Вызывает `NormalizeRawRecordAction`. Routing: `ingest_normalize`.

### 4.6 Доменное событие

#### `App\Ingestion\Domain\Event\NormalizationCompletedEvent`

`final readonly class`.

Поля: `companyId: string`, `rawRecordId: string`, `affectedPeriods: list<AffectedPeriod>`.

`App\Ingestion\Domain\Event\AffectedPeriod` (final readonly): `shopRef: string`, `oldOccurredAt: ?DateTimeImmutable`, `newOccurredAt: DateTimeImmutable`. Используется блоком 7 для пометки `PLDirtyPeriod`.

Публикация: `EventDispatcherInterface::dispatch($event)` в конце `NormalizeRawRecordAction` после flush.

### 4.7 Facade

#### `App\Ingestion\Facade\IngestionFacade`

`final readonly class`. Используется блоком 7 (P&L).

Методы:
- `getTransactions(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $shopRef = null): iterable<FinancialTransaction>` — делегирует в Repository::iterateByPeriod.
- `countOpenIssues(string $companyId): int` — для admin.

### 4.8 Fake Connector + Mapper (для тестов)

#### `App\Ingestion\Application\Source\FakeConnector`

`final class`, реализует `SourceConnectorInterface`. Регистрация: тег `app.ingestion.connector` + `autowire: false`, видимый только в `dev`/`test` (`when@dev`, `when@test` в `services.yaml`).

Возвращает захардкоженный `PullResult` с одной строкой raw.

#### `App\Ingestion\Application\Source\FakeMapper`

Реализует `SourceMapperInterface`. Один rows = одна `MappedTransaction` типа `SALE`.

Используется в integration-тестах. Не попадает в prod-сборку (`when@prod` исключает).

---

## 5. Асинхронность (Messenger)

### 5.1 Сообщения

`NormalizeRawRecordMessage` — см. §4.5.

### 5.2 Handler'ы

`RunSyncChunkHandler` (transport `ingest_fetch`), `NormalizeRawRecordHandler` (transport `ingest_normalize`).

### 5.3 Routing

`config/packages/messenger.yaml` — добавить:
```yaml
App\Ingestion\Message\NormalizeRawRecordMessage: ingest_normalize
```

### 5.4 Идемпотентность

Оба handler'а используют `IdempotentHandlerTrait` (§22).

---

## 6. Обработка ошибок

| Класс | Когда | HTTP-статус | error.code | message |
|---|---|---|---|---|
| `RawRecordNotFoundException` (из блока 3) | — | 404 | `raw_record_not_found` | «Сырая запись не найдена» |
| `ConnectorNotFoundException` | `ConnectorRegistry::get` не нашёл | 500 | `connector_not_found` | «Коннектор не найден» |
| `MapperNotFoundException` | `MapperRegistry::get` не нашёл | 500 | `mapper_not_found` | «Маппер не найден» |
| `UnsupportedCapabilityException` | push для коннектора без CAN_PUSH | 422 | `connector_capability_unsupported` | «Коннектор не поддерживает эту операцию» |
| `ConnectorAuthException` | 401/403 от источника | 401 | `connector_auth_failed` | «Ошибка авторизации источника» |
| `ConnectorTransientException` | 5xx/timeout/429 | 503 | `connector_transient_error` | «Источник временно недоступен» |
| `MoneyMismatchException` (`App\Shared\Domain\Exception`) | Операция Money с разными currency | 422 | `money_currency_mismatch` | «Несовпадение валют» |
| `StaleTransactionUpdateException` | externalUpdatedAt старее текущей | 409 | `transaction_stale_update` | «Версия данных устарела» |

Все Ingestion-исключения — namespace `App\Ingestion\Exception`, `final class`. Исключение `MoneyMismatchException` живёт в `App\Shared\Domain\Exception`, потому что `Money` — общий value object.

---

## 7. HTTP API (Controller)

N/A — HTTP API в блоке 8.

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | Money + ValueObject | — | 🟢 | unit на все операции, MoneyMismatchException |
| B2 | Enum (TransactionType/Direction/IssueKind/Capability) + Entity (FinancialTransaction/Counterparty/NormalizationIssue) + миграция | блок 3 | 🔴 | unit инвариантов |
| B3 | Repository (3 шт.) | B2 | 🟡 | tenant-leak на каждый read-метод |
| B4 | Domain Contracts + DTO (ShopDescriptor/PullRequest/PullResult/MappedTransaction/MappedControlSum) | блок 3 | 🟢 | — |
| B5 | ConnectorRegistry + MapperRegistry + теги | B4 | 🟡 | unit на get/has |
| B6 | NormalizeRawRecordAction + UpsertFinancialTransactionAction + RecordNormalizationIssueAction | B2, B3, B4 | 🟡 | integration на happy/mismatch/mapper-failure |
| B7 | NormalizationCompletedEvent + AffectedPeriod + публикация | B6 | 🟢 | проверить dispatch после flush |
| B8 | RunSyncChunkMessage handler (использует SyncFacade из блока 4) | блок 4, B6 | 🟡 | integration на coupled flow |
| B9 | NormalizeRawRecordMessage + Handler + routing | B6 | 🟢 | end-to-end через in-memory transport |
| B10 | FakeConnector + FakeMapper (dev/test only) | B4, B5 | 🟢 | integration: full pipeline на Fake |
| B11 | IngestionFacade::getTransactions + countOpenIssues | B3 | 🟢 | integration |
| B12 | ARCHITECTURE.md обновить | все | 🟢 | — |

---

## 9. Ограничения и запреты

- Не ломать: блоки 1-4 (изменения в `IngestRawRecord` минимальны — только добавление двух методов `markNormalizationDone/Failed`; legacy не трогаем).
- Не трогать: legacy-зону `src/Entity`, `src/Marketplace`, `src/Inventory`, `src/MarketplaceAds` — новый канон живёт отдельно.
- Совместимость API: HTTP не вводим.
- Миграции: zero-downtime, только CREATE TABLE.
- Performance: `iterateByPeriod` использует `toIterable()` для больших периодов, чтобы не грузить весь канон в память. N+1 защищается batch-fetch'ем counterparty в `NormalizeRawRecordAction` (preload по `externalKey` set'у перед циклом).
- Безопасность: companyId обязателен в каждом методе Repository; `CompanyFilter` из блока 1 покрывает все новые Entity (они реализуют `TenantOwnedInterface`).

---

## 10. Критерии приёмки

Функциональные:
- [ ] `Money` допускает положительные, отрицательные и нулевые minor units.
- [ ] `Money` запрещает арифметику разных валют (`MoneyMismatchException`).
- [ ] `FakeConnector.pull` → `PullResult` → `RawStorageFacade.store` → `IngestRawRecord` создан.
- [ ] `NormalizeRawRecordAction` upsert'ит `FinancialTransaction` по natural key (идемпотентность: повтор не плодит дубли).
- [ ] При повторной нормализации с тем же `externalUpdatedAt` — данные не меняются.
- [ ] При нормализации с свежим `externalUpdatedAt` — данные обновляются.
- [ ] При старом `externalUpdatedAt` — обновление пропускается без ошибки.
- [ ] Сверка сумм: при искусственно расходящейся controlSum создаётся `NormalizationIssue(kind=SUM_MISMATCH)`.
- [ ] `NormalizationCompletedEvent` публикуется ровно один раз на успешную нормализацию RawRecord.
- [ ] `AffectedPeriod` содержит и старую, и новую `occurredAt` для каждой обновлённой транзакции.
- [ ] `RunSyncChunkHandler` отрабатывает Fake-цепочку end-to-end: dispatch RunSyncChunkMessage → пишется raw → диспатчатся NormalizeRawRecordMessage → создаётся канон.

Технические:
- [ ] `make site-cs-check` + PHPStan зелёные.
- [ ] `make site-test-unit` + `make site-test-integration` зелёные.
- [ ] Миграции применяются/откатываются.
- [ ] Tenant-leak тест на canon (FinancialTransaction компании B не виден из контекста A).
- [ ] FakeConnector НЕ собирается в `prod`-окружении (тест: `bin/console debug:container --env=prod | grep FakeConnector` ничего не находит).
- [ ] `ARCHITECTURE.md` обновлён.
- [ ] OpenAPI — без изменений.

---

## 11. План отката

- DROP трёх новых таблиц + удалить routing `NormalizeRawRecordMessage`.
- `RunSyncChunkHandler` не зарегистрирован → блок 4 продолжает работать (просто Message в очереди не обрабатывается, можно почистить транспорт).
- Откат изменений `IngestRawRecord` (два метода `markNormalizationDone/Failed`) — несложный revert.
- Зависимости вниз: блок 6 (Ozon) использует `SourceConnectorInterface` и `SourceMapperInterface`. До старта блока 6 откат блока 5 безопасен.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь для каждого класса.
- [x] Полная таблица полей трёх новых Entity с типами/nullable/инвариантами.
- [x] Каждый enum case описан.
- [x] Сигнатура каждого метода Repository/Action/Facade/Contract.
- [x] Каждый Repository-метод принимает `string $companyId`.
- [x] HTTP — N/A явно.
- [x] Все исключения замаплены на код+статус.
- [x] Индексы перечислены с именами.
- [x] Транспорт `ingest_normalize` указан.
- [x] Формат данных: ISO 8601, UUID, минорные единицы для денег, ISO 4217 для валют.
- [x] Out of scope зафиксирован.
- [x] Контракт «push» зарезервирован, но реализация в out-of-scope.
