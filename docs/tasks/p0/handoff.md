# Handoff — TASK-PHASE0: Pre-flight pack для COGS-обогащения

**Ветка:** `feature/ingestion-preflight-cogs` (от `master`)
**Тип:** refactor + integration prep. **Миграций БД нет. Публичный HTTP API не меняется.**
**Статус:** все 8 этапов закрыты, self-review зелёный. 🛑 Ждёт Final Owner review (merge только PR).

> ⚠️ **Важно:** реальный корень проекта — `site/`. Спека писалась без этого префикса.
> Также по ходу выяснилось, что модуль `App\Finance` и `PnlFacade` **уже существуют** — это
> поменяло содержание B5/B6 (см. ниже и `plan.md`).

---

## 1. Summary по этапам

| Этап | Цель | Риск | Результат |
|---|---|---|---|
| **B1** | Детерминированный fallback `accrualId` (без `rowIndex`) | 🟡 | `SourceDataHasher` + правка `OzonAccrualByDayPreviewMapper`. P0.1 закрыт. |
| **B2** | Канонизация порядка строк raw payload (Ozon + WB) | 🟡 | `sortRowsCanonically` в обоих коннекторах. P0.3 закрыт. |
| **B3** | sourceHash pre-check в `UpsertFinancialTransactionAction` (NO_CHANGE) | 🟡 | Hash-сравнение on-the-fly, без новой колонки (A3). P0.2 закрыт. |
| **B4** | `NormalizationCompletedEvent` не публикуется впустую + reorder | 🟢 | Gate по `affectedPeriods`; `markDone` до controlSum в том же tx. |
| **B7** | Идемпотентность при гонке нормализации | 🟡* | **Clean recoverable retry** (решение Владельца) — см. §3. P0.6 закрыт. |
| **B8** | Retry-семантика `RunSyncChunkHandler` | 🟡 | catch-all больше не делает `markJobFailed`; rethrow → Messenger retry. P0.7 закрыт. |
| **B5** | `IngestionFacade::getTransactions` → DTO | 🔴 | `FinancialTransactionView` + projector + arch-test. P0.4 закрыт. |
| **B6** | `App\Finance` + `PnlFacade` (verify + document) | 🔴 | Модуль/Facade уже есть → только verify + docs (решение Владельца). P0.5 закрыт. |

Stage Reports: `docs/tasks/p0/stages/stage-B{1..8}.md`. План: `docs/tasks/p0/plan.md`.

---

## 2. Изменённые публичные/межмодульные контракты

- **`IngestionFacade::getTransactions`** (внутренний межмодульный контракт): возвращаемый тип
  `iterable<FinancialTransaction>` → `iterable<App\Ingestion\Application\DTO\FinancialTransactionView>`.
  Единственный потребитель — `App\Finance\Application\Action\RebuildPnlPeriodAction` — обновлён в том
  же этапе (координированный merge, O3). Внешний HTTP API не затронут.
- **Новый DTO** `App\Ingestion\Application\DTO\FinancialTransactionView` (read-only, enum-поля как
  scalar `value`).
- **Сигнатуры мапперов/коннекторов** (internal): `accrualId(array $row)` (убран `$rowIndex`);
  коннекторы получили необязательный параметр `SourceDataHasher` (дефолт `new SourceDataHasher()`).

---

## 3. Отклонения от спеки (требуют внимания ревьюера)

1. **B7 — механизм изменён по согласованию с Владельцем.** Спека предписывала ловить
   `UniqueConstraintViolationException` **внутри** `UpsertFinancialTransactionAction`. Это
   неприменимо: flush батчевый (в `NormalizeRawRecordAction`), и эмпирический пробник подтвердил,
   что unique violation **закрывает EntityManager и отравляет outer-транзакцию** даже при
   `use_savepoints: true`. Владелец выбрал **clean recoverable retry**: ловим на flush
   нормализации, rollback + INFO-лог + `RecoverableMessageHandlingException` → Messenger retry; на
   ретрае B3 делает upsert'ы no-change → конвергенция без дублей.
2. **B5 arch-test — ad-hoc PHPUnit** (решение Владельца), без deptrac/phparkitect (нет новой
   composer-зависимости): `tests/Unit/Ingestion/Architecture/EntityBoundaryTest`.
3. **B6 — verify + document** (решение Владельца): `App\Finance`/`PnlFacade` уже существуют,
   write-path уже декомпозирован (`NormalizationCompletedSubscriber` → message → handler →
   `MarkPnlPeriodDirtyAction`). Кода write-path не трогали.
4. **B6 — расхождение спеки §4.5 vs код.** Спека хотела «`BLOCKED_BY_CLOSE` не трогать», но
   фактический `MarkPnlPeriodDirtyAction` reopen'ит `DONE`/`FAILED`/`BLOCKED_BY_CLOSE` → `PENDING`.
   В verify-only режиме поведение НЕ менялось; `ARCHITECTURE.md` приведён к фактическому поведению,
   расхождение вынесено в follow-up.
5. **B4 — транзакционность.** Спека §4.1 противоречиво требовала и «тот же tx-блок», и «issues в
   отдельной транзакции best-effort». Выбран консервативный вариант: тот же single-tx блок, только
   swap порядка `markDone`/`recordControlSumIssues`.

---

## 4. Миграции БД

**Нет.** Ни одной. Схема `ingest_*` и `pnl_dirty_periods` без изменений. Деструктивных операций нет.

---

## 5. Архитектурное исключение (Variant B) — зафиксировано

`App\Finance` (`PnlFacade`/`MarkPnlPeriodDirtyAction`/`RebuildPnlPeriodAction`/
`RebuildDirtyPnlPeriodsCommand`) импортирует `App\Ingestion\Entity\PLDirtyPeriod` и
`App\Ingestion\Repository\PLDirtyPeriodRepository` напрямую — осознанный временный компромисс MVP.
Задокументировано в `ARCHITECTURE.md`, whitelisted в `EntityBoundaryTest`. План миграции: перенос
`PLDirtyPeriod` Entity + Repository в `App\Finance` (отдельная задача, не Phase 0).

---

## 6. Открытые вопросы / follow-ups (вне scope Phase 0)

- **O1 (требует прод-доступа — не проверено):** есть ли в проде raw_records со старой формулой
  fallback (`fallback-N-...`)? После B1 они станут «осиротевшими» FinancialTransaction'ами.
  **Подтвердить запросом к проду перед merge B1**; при необходимости — одноразовый бэкфилл/сверка.
- Issues-дедуп (часть B7 спеки) — не делался: в Phase 0 нет уникального индекса на issues.
- §4.5 BLOCKED_BY_CLOSE skip — решить, нужно ли менять `MarkPnlPeriodDirtyAction` (follow-up).
- Полный `IdempotentHandlerTrait` с `processed_messages` — отложен (A2).
- `EnrichmentTransactionView` как паттерн для TASK-FIX-06 — готов на примере `FinancialTransactionView`
  (сам класс в Phase 0 не создаётся).

---

## 7. Verification (как проверить)

- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit` — 1126 зелёных
  (включая `EntityBoundaryTest`).
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite integration`
  — зелёный (ключевые: UpsertFinancialTransactionActionTest, NormalizeRawRecordActionTest,
  RunSyncChunkHandlerTest, RebuildPnlPeriodActionTest, PnlFacadeTest).
- `docker compose run --rm -T site-php-cli php bin/console lint:container --env=test` — OK.
- CS: все 20 изменённых файлов чисты (`php-cs-fixer` intersection). Полный `composer cs:check`
  падает на **пред-существующем** долге кодовой базы (666/1832 файлов), не связанном с задачей.
- **PHPStan не установлен** в проекте — `make stan` не сконфигурирован; статанализ не прогонялся.

---

## 8. Запреты — построчная сверка (§9 спеки)

- [x] `config/packages/messenger.yaml` — не тронут (routings/transports/retry без изменений).
- [x] `config/packages/doctrine.yaml` — не тронут.
- [x] Schema БД / миграции — нет.
- [x] Legacy-зона (`src/Entity|Service|Repository|Controller`) — не тронута.
- [x] `EncryptedJsonType`/`SecretCodec`/`IngestionCredential` — не тронуты (Phase 2).
- [x] `MaybeFinalizeParentAction`/`SplitJobIntoChunksAction`/`CompanyFilter*` — не тронуты (Phase 1).
- [x] TASK-FIX-06 сущности (`EnrichmentTransaction`/`EnrichCogs*`) — не создавались.
- [x] `companyId` сохранён во всех изменённых сигнатурах; IDOR не затронут.
- [x] Merge / force-push — не делались (только коммиты в feature-ветку).

🛑 **Final Owner review.** Merge — только после одобрения Владельцем.
