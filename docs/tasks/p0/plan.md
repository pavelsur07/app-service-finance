# План выполнения — TASK-PHASE0: Pre-flight pack для COGS-обогащения

## Context

Задача `docs/tasks/p0/TASK-PHASE0-preflight-canon-and-boundaries.md` готовит ядро `App\Ingestion`
к будущему блоку COGS-обогащения (`TASK-FIX-06`). Без этой основы обогащение унаследует
существующие дефекты с кратным усилением (1 транзакция = 1 COGS-запись = 1 пересчёт периода).
Восемь подзадач (B1–B8) делают канон `FinancialTransaction` детерминированным, укрепляют
границы модулей и чинят ретраи синхронизации. **Все изменения code-only — миграций БД нет,
публичный HTTP API не меняется.** Тип: refactor + integration prep. Ветка: `feature/ingestion-preflight-cogs`.

### Реальное состояние кодовой базы (по итогам разведки — расходится со спекой)

- **Корень проекта — `site/`.** Все пути в спеке нужно читать с префиксом `site/` (напр.
  `site/src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php`).
- **`App\Finance` уже существует** как полный модуль (зарегистрирован в `services.yaml`,
  `routes.yaml`, `doctrine.yaml`). Допущение A4 спеки («модуль новый») — неверно.
- **`PnlFacade` уже существует** (`site/src/Finance/Facade/PnlFacade.php`) с методами
  `markPeriodDirty(MarkPnlPeriodDirtyCommand)`, `rebuildPeriod`, `getDirtyPeriods`, `getProgress`.
- **`NormalizationCompletedSubscriber` НЕ пишет `PLDirtyPeriod` напрямую** (допущение A5 неверно).
  Он диспатчит `MarkPnlPeriodDirtyMessage` → `MarkPnlPeriodDirtyHandler` → `MarkPnlPeriodDirtyAction`
  (единственный писатель в `pnl_dirty_periods`). Путь уже декомпозирован и чист.
- **Единственный потребитель `getTransactions`** — `App\Finance\Application\Action\RebuildPnlPeriodAction:113`
  (резолвит O3).
- **Нет фреймворка арх-тестов** (deptrac/phparkitect не установлены) — границу проверяем
  ad-hoc PHPUnit-тестом (решение Владельца).

### Решения Владельца (Phase 0)

- **B6 — только verify + document.** PnlFacade уже пригоден для TASK-FIX-06; пишем запись о
  Variant-B исключении в `ARCHITECTURE.md`, никаких правок write-path.
- **B5 boundary — ad-hoc PHPUnit-тест.** Без новой composer-зависимости.

---

## Порядок этапов и зависимости

```
B1 (mapper)            🟡  независим
B2 (connector sort)    🟡  независим
B3 (upsert hash)       🟡  независим      → B4, B7 строятся поверх
B4 (event gate)        🟢  зависит от B3
B5 (facade DTO)        🔴  независим      → 🛑 STOP перед merge
B6 (PnlFacade docs)    🟢  независим (сведён к verify+docs)  → 🛑 STOP перед merge
B7 (idempotency)       🟡  зависит от B3
B8 (retry semantics)   🟡  независим
```

Рекомендуемая последовательность исполнения: **B1 → B2 → B3 → B4 → B7 → B8 → B5 → B6**.
Сначала «зелёно-жёлтые» детерминизм/идемпотентность, потом два HIGH-risk этапа с обязательным STOP.

Опциональный общий помощник, создаётся в B1 и переиспользуется B2/B3:
`site/src/Ingestion/Domain/Service/SourceDataHasher.php` (`final readonly class`) —
рекурсивный `ksort` + `json_encode(JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)` + `sha256`.
Логика канонизации зеркалит существующий `RawNdjsonCodec::normalizeValue` (`site/src/Ingestion/Infrastructure/Storage/RawNdjsonCodec.php:61`).

---

## B1 — Детерминизм fallback `accrualId` (🟡 MEDIUM)

**Проблема (P0.1).** `OzonAccrualByDayPreviewMapper::accrualId(array $row, int $rowIndex)`
(`site/src/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapper.php:389`) строит
fallback как `'fallback-{rowIndex}-{sha256(json(row))[0:16]}'`. `rowIndex` зависит от порядка
ответа Ozon → разный fallback на повторном fetch → дубли в каноне.

**Изменения.**
- Создать `site/src/Ingestion/Domain/Service/SourceDataHasher.php` (см. выше).
- В `accrualId`: убрать параметр `$rowIndex`; формула → `'fallback-' . substr($hasher->hash($row), 0, 16)`.
  Сигнатура становится `accrualId(array $row): string`.
- Поправить вызов в `preview(...)` (`OzonAccrualByDayPreviewMapper.php:41`) — убрать `$rowIndex`.

**Тесты.** unit: одни и те же rows в разном порядке → одинаковый fallback/`operationGroupId`;
две строки без `accrual_id` с минимальным различием → разные fallback ID.

**DoD.** `make stan && make cs && make test` зелёные на изменённом коде.

---

## B2 — Канонизация порядка строк raw payload (🟡 MEDIUM)

**Проблема (P0.3).** Top-level список строк сохраняется в порядке поступления; Ozon/WB отдают
недетерминированный порядок → новый hash `IngestRawRecord` → лишняя нормализация + перерасход storage.
`RawNdjsonCodec` **не трогаем** — канонизация это ответственность connector'а (знает доменный ключ).

**Изменения.**
- `site/src/Ingestion/Application/Source/Ozon/OzonSellerReportConnector.php` — в `pullAccrualByDay`
  перед формированием `RawBatch` (около `:103`) отсортировать `$rows` новым private
  `sortRowsCanonically(array $rows): array`. Ключ: `($row['date'] ?? '') . '|' . ($row['accrual_id'] ?? '') . '|' . $hasher->hash($row)`, `usort`.
- `site/src/Ingestion/Application/Source/Wildberries/WbFinanceReportConnector.php` — в `pull(...)`
  аналогично перед `RawBatch` (около `:70`). Ключ: `($row['rrd_id'] ?? '') . '|' . $hasher->hash($row)`.

**Тесты.** unit: одни и те же rows в разном порядке → одинаковый результат `RawNdjsonCodec::encodeRows`;
регрессия — уже отсортированный вход hash не меняет.

---

## B3 — sourceHash pre-check в UpsertAction (🟡 MEDIUM)

**Проблема (P0.2).** `externalUpdatedAt = $rawRecord->getFetchedAt()` (мапперы,
`OzonAccrualByDayMapper.php:48`, `WbFinanceSalesReportDetailedMapper.php:48`) → любой повторный
fetch двигает версию → `replaceFromNewerVersion` срабатывает зря → каскад пересчётов.
Решаем без новой колонки (допущение A3): сравнение hash on-the-fly.

**Изменения.** `site/src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php`
(метод `__invoke`, `:22`; ветка update вокруг `:70-93`):
- После `findByNaturalKey` — если транзакция найдена и
  `$hasher->hash($mapped->sourceData) === $hasher->hash($existing->getSourceData())` →
  вернуть `null` (NO_CHANGE), `replaceFromNewerVersion` не вызывать, `updatedAt`/`externalUpdatedAt`
  в БД не двигать.
- Иначе — текущее поведение `replaceFromNewerVersion`.

**Тесты.** integration: повторная нормализация того же raw_record → `UpsertResult === null`,
`updatedAt` не движется; реальное изменение `sourceData` → `replaceFromNewerVersion` вызывается,
`UpsertResult !== null`; существующие тесты на `replaceFromNewerVersion` зелёные.

---

## B4 — `NormalizationCompletedEvent` не публикуется впустую (🟢 LOW, зависит от B3)

**Изменения.** `site/src/Ingestion/Application/Action/NormalizeRawRecordAction.php`:
- Аккумулировать `$affectedPeriods` только при `UpsertResult !== null` (уже так, `:128-133` — оставить).
- **Новая ветка:** если после цикла `$affectedPeriods === []` → `NormalizationCompletedEvent`
  **не диспатчить** (сейчас всегда диспатчится, `:142-156`).
- **Перестановка:** `markNormalizationDone` (`:138`) вызывать **до** `recordControlSumIssues`
  (`:137`). Если control-sum упадёт по таймауту — raw уже DONE, ретрай не тратится на повторную
  нормализацию. Issues пишутся best-effort.

**Тесты.** integration: повторная нормализация без изменений → подписчики не дёргаются, событие
не публикуется; изменилась 1 из N транзакций → событие с одним `AffectedPeriod`.

---

## B7 — Idempotency через ловлю unique violation (🟡 MEDIUM, зависит от B3)

**Проблема (P0.6).** Параллельная нормализация одного `raw_record_id` (event + cron-страховка)
может упасть с `UniqueConstraintViolationException`. Полный `IdempotentHandlerTrait` — вне scope (A2).

**Изменения.** `site/src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php`:
- В ветке создания новой транзакции обернуть `persist + flush` в `try/catch
  (Doctrine\DBAL\Exception\UniqueConstraintViolationException)`. При перехвате — re-fetch по
  natural key и пройти ветку update (паттерн как в
  `site/src/Ingestion/Application/StoreRawBatchAction.php:99` `recoverConcurrentDuplicate`).
- Issues-дедуп (`RecordNormalizationIssueAction`) — **частично переносится** (в Phase 0 нет
  уникального индекса в БД, ловить нечего). В рамках scope ограничиваемся Upsert-веткой.

**Тесты.** integration (concurrency): два параллельных `NormalizeRawRecordHandler` для одного
`raw_record_id` → консистентное состояние БД, нет orphan-entity, нет необработанных исключений.

---

## B8 — Retry semantics в RunSyncChunkHandler (🟡 MEDIUM)

**Проблема (P0.7).** Catch-all `\Throwable` (`site/src/Ingestion/MessageHandler/RunSyncChunkHandler.php:155`)
→ `markJobFailed` + throw. На транзиентной ошибке джоб мгновенно `FAILED`; ретрай Messenger'а
делается no-op'ом ранним выходом `if ($job->getStatus()->isTerminal()) return;` (`:56`).

**Изменения.** `site/src/Ingestion/MessageHandler/RunSyncChunkHandler.php` (блок catch'ей `:131-158`):
- `ConnectorAuthException` (`:131`) — без изменений (`markJobFailed('auth')` + Unrecoverable).
- `ConnectorRateLimitedException`, `ConnectorTransientException` — без изменений.
- **`catch (\Throwable)` (`:155`) меняется:** НЕ вызывать `markJobFailed`; логировать WARNING с
  `companyId`/`jobId`/`exceptionClass`/`errorMessage`; пробросить наружу → Messenger ретрай.
- `markJobFailed` (private, `:181`) оставить — вызывается из auth-ветки.
- `messenger.yaml` НЕ трогаем; `SyncJobFailureSubscriber` закроет джоб в `FAILED` после исчерпания
  retry-strategy.

**Тесты.** integration: `RuntimeException` на 1-й попытке, успех на 2-й → джоб `COMPLETED`,
attempts=2 (не FAILED); после исчерпания ретраев `SyncJobFailureSubscriber` ставит `FAILED`.

---

## B5 — IngestionFacade отдаёт DTO (🔴 HIGH — STOP перед merge)

**Проблема (P0.4).** `IngestionFacade::getTransactions`
(`site/src/Ingestion/Facade/IngestionFacade.php:44`) возвращает `iterable<FinancialTransaction>` —
managed Entity с mutating-методами утекает в `App\Finance`. TASK-FIX-06 размножит проблему
(`getEnrichments`).

**Изменения.**
- Создать `site/src/Ingestion/Application/DTO/FinancialTransactionView.php` (`final readonly class`)
  — поля по таблице §4.4 спеки (id, companyId, shopRef, source, externalId, operationGroupId, type,
  direction, amountMinor, currency, occurredAt, sourceTz, orderRef?, payoutRef?, counterpartyId?,
  listingId?, listingSku?, description?, rawRecordId). **`enrichmentStatus` НЕ добавляем** (TASK-FIX-06).
- В `IngestionFacade`: `getTransactions(...): iterable<FinancialTransactionView>` через
  generator-проектор поверх `FinancialTransactionRepository::iterateByPeriod` (`:98`); private
  `projectTransactionToView(FinancialTransaction): FinancialTransactionView`.
- Поправить единственного потребителя — `App\Finance\Application\Action\RebuildPnlPeriodAction:113`
  под новый DTO (геттеры Entity → поля DTO).
- **Ad-hoc PHPUnit арх-тест** (решение Владельца, без новых зависимостей): сканирует `src/`,
  ассертит, что `App\Ingestion\Entity\FinancialTransaction` (и шире `App\Ingestion\Entity\*`)
  не импортируется вне `App\Ingestion\*` (исключая `App\Marketplace`, `App\Finance`, `App\Catalog`).

**Тесты.** integration: `getTransactions` отдаёт DTO, нет утечки Entity; арх-тест зелёный;
существующие тесты consumer-кода зелёные.

**🛑 STOP** перед merge — согласовать с Владельцем (меняется внутренний контракт Facade).

---

## B6 — App\Finance + PnlFacade: verify + document (🟢 LOW — сведён, STOP перед merge)

**Реальность.** Модуль `App\Finance` и `PnlFacade` уже существуют; write-path
(`NormalizationCompletedSubscriber` → `MarkPnlPeriodDirtyMessage` → `MarkPnlPeriodDirtyHandler` →
`MarkPnlPeriodDirtyAction`) уже декомпозирован и корректен. `MarkPnlPeriodDirtyAction`
(`site/src/Finance/Application/Action/MarkPnlPeriodDirtyAction.php`) — единственный писатель,
делает create-or-`reopen()`. Variant-B исключение (Finance импортирует
`App\Ingestion\Repository\PLDirtyPeriodRepository`) — уже факт.

**Изменения (только документация/верификация, без правок write-path).**
- Проверить, что `PnlFacade::markPeriodDirty` пригоден как точка входа для TASK-FIX-06
  (B4/B9 будут звать его). Текущая сигнатура — через `MarkPnlPeriodDirtyCommand`.
- Обновить `ARCHITECTURE.md`: зафиксировать `App\Finance\Facade\PnlFacade` как единственную точку
  входа для записи в `pnl_dirty_periods`, и **временное исключение Variant B** (импорт
  `PLDirtyPeriodRepository` из Ingestion — осознанный компромисс MVP, план миграции — отдельная задача).

**Тесты.** integration уже покрывают write-path; добавить тест, что `PnlFacade::markPeriodDirty`
создаёт новый `PLDirtyPeriod`, а на терминальном статусе делает `reopen` (если такого ещё нет).
`lint:container --env=test` зелёный.

**🛑 STOP** перед merge — согласовать с Владельцем (граница модулей, документирование исключения).

---

## Обновления ARCHITECTURE.md (в соответствующих этапах)

- `App\Ingestion\Application\DTO\FinancialTransactionView` (новый) — в B5.
- Изменение сигнатуры `IngestionFacade::getTransactions` → DTO — в B5.
- `App\Finance\Facade\PnlFacade` как точка входа + Variant-B исключение — в B6.
- (Опц.) `App\Ingestion\Domain\Service\SourceDataHasher` — в B1.

---

## Открытые вопросы / риски к согласованию

- **O1 (требует прод-доступа, не могу проверить):** есть ли в проде raw_records с fallback-ID
  старой формулы (`fallback-N-...`)? После B1 они станут «осиротевшими» FinancialTransaction'ами.
  **Подтвердить запросом к проду перед merge B1**; при необходимости — одноразовый бэкфилл/сверка.
- **O2 — резолвлен:** подписчик = `App\Finance\EventSubscriber\NormalizationCompletedSubscriber`.
- **O3 — резолвлен:** единственный потребитель `getTransactions` =
  `App\Finance\Application\Action\RebuildPnlPeriodAction:113`.
- **O4 — резолвлен:** `App\Finance` уже зарегистрирован в `services.yaml`/`routes.yaml`/`doctrine.yaml`.

---

## Verification (Phase Final)

1. `make test && make stan && make cs` — полный прогон зелёный.
2. `doctrine:schema:validate --skip-sync --env=test` — зелёный (схема не менялась).
3. `lint:container --env=test` — зелёный.
4. Арх-тест (ad-hoc PHPUnit): импорт `App\Ingestion\Entity\*` запрещён из `App\Marketplace`,
   `App\Finance`, `App\Catalog`.
5. Построчная сверка «Глобальных запретов» и §9 спеки (нет миграций, `messenger.yaml` не тронут,
   legacy-зона не тронута, companyId в сигнатурах).
6. `docs/tasks/<id>/handoff.md` — summary всех этапов, список изменённых контрактов
   (`getTransactions`), риски, follow-ups.
7. Stage Report после каждого этапа в `docs/tasks/<id>/stages/stage-B<N>.md`.

**Запреты по задаче:** нет миграций; `config/packages/messenger.yaml` и `doctrine.yaml` не трогаем;
legacy-зону (`src/Entity|Service|Repository|Controller`) не трогаем; TASK-FIX-06 сущности
(`EnrichmentTransaction`, `EnrichCogs*`) не создаём; merge — только PR после одобрения Владельцем.
