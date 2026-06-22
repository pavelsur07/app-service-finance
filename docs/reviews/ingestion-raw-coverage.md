# Code Review — Ingestion raw coverage (+ WB follow-up fixes)

- **Ветка:** `feature/ingestion-raw-coverage`
- **База:** `master`
- **Коммиты:** `3273368b` → `0bccda84` → `38183e1a` → (merge #2014) → `60bb249b`
- **Объём (vs master):** 29 файлов, +1948 / −25
- **Дельта с прошлого ревью (`3273368b`):** 13 файлов, +351 / −49
- **Дата ревью:** 2026-06-22

> `gh` CLI недоступен — ревью по локальному diff. PHP только в Docker → тесты в этом окружении **не прогонялись**, проверено чтением.
> Ревью сфокусирован на новой работе поверх ранее отревьюенного WB-ингеста: фиксы WB + фича «raw coverage».

---

## 1. Что нового с прошлого ревью

**Закрыты замечания прошлого ревью** (WB finance raw ingestion):
- ✅ **M1 (курсор в будущее)** — `nextIncrementalDateCursor()` теперь ограничен `today`: если `date+1 > today`, возвращается `null`, инкремент не уходит за текущий день.
- ✅ **M2 (дублирование метаданных)** — `_ingestion_metadata` убран из каждой строки (`rowsOrEmptyMarker`), остаётся только лёгкий маркер `_ingestion_resource`.

**Новое:**
- **Фича coverage** (`60bb249b`): `CoverageQuery` перестроен — базовая таблица сменилась с `ingest_financial_transactions` на `ingest_raw_records`, транзакции/issues подключаются `LEFT JOIN`. Теперь покрытие показывает «сырые» записи **даже без нормализованных транзакций** (ключевой кейс WB raw-only). Дата берётся `COALESCE(ft.occurred_at, j.window_from)`, shop — `COALESCE(NULLIF(ft.shop_ref,''), r.shop_ref)`.
- **Статус `SKIPPED`** в `RawNormalizationStatus` + `IngestRawRecord::markNormalizationSkipped()`; `RunSyncChunkHandler` помечает raw-only записи как `SKIPPED` (а не оставляет `PENDING`). `WbFinanceStatusCommand` показывает колонку `skipped`.
- **Общий rate-limiter**: `WbFinanceReportClient` переключён с локального `RateLimiterFactory` на разделяемый `WbFinanceRateLimiter` (модуль Marketplace) с поддержкой cooldown после remote-429, per-seller bucket. `retryAfterSeconds()` теперь `?int` (null → дефолт решает limiter).

---

## 2. Общая оценка

Хорошая итерация: предыдущие замечания закрыты по существу, новая фича аккуратно встроена и покрыта тестами (`testCoverageIncludesRawOnlyIngestionRecordsByJobWindowDate` + расширение functional-теста контроллера). Логирование/безопасность не регрессировали.

**Вердикт:** Approve с замечаниями. Один значимый конвенционный вопрос (кросс-модульная зависимость) и пара edge-кейсов в `CoverageQuery` на подтверждение.

---

## 3. Замечания

### 🟡 MEDIUM

**M1. Кросс-модульная зависимость Ingestion → Marketplace (Service).**
`WbFinanceReportClient` (модуль `Ingestion`, слой `Infrastructure`) напрямую импортирует `App\Marketplace\Application\Service\WbFinanceRateLimiter`. По правилам CLAUDE.md «import Service/Repository чужого модуля — только через Facade».
*Контекст:* лимит WB (1 req/min на seller-аккаунт) — общий внешний ресурс, и Marketplace, и Ingestion **обязаны** делить одно состояние, иначе оба модуля независимо ловят 429 — техническая причина переиспользования законна. Кроме того, в модуле уже есть похожие нарушения (`ReconciliationQuery` → `Marketplace\Repository\...`, `RunIncrementalCommand` → `Marketplace\Infrastructure\Query\...`), т.е. это продолжение существующего долга, а не новый паттерн.
*Рекомендация:* вынести лимитер в `Shared`/общий слой или обернуть Facade-методом Ingestion. Решение — за Владельцем (затрагивает границы модулей → high-risk).

**M2. `CoverageQuery`: raw-only записи без window_from невидимы.**
Условие `WHERE` для ветки без транзакций: `ft.id IS NULL AND j.window_from >= :fromDate AND j.window_from <= :toDate`. Для инкрементальных job-ов `window_from = NULL` → сравнение даёт NULL → запись выпадает из покрытия. Для WB-бэкафилла (windowed) всё работает, но raw-only записи инкрементального режима в coverage не попадут. Подтвердить, что это ожидаемо (coverage = про окна бэкафилла), либо добавить fallback на `r.fetched_at`.

### 🟢 MINOR / NIT

**N1. Производительность `CoverageQuery`.**
Базовая таблица теперь `ingest_raw_records` (потенциально крупная) + 3 `LEFT JOIN` + `GROUP BY` по выражениям `TO_CHAR(...)`/`COALESCE(...)`. Нужны индексы на `ingest_raw_records(company_id)`, `ingest_financial_transactions(company_id, raw_record_id)`, `ingest_sync_jobs(company_id, id)`. Группировка по выражению индекс не использует (неизбежно при бакетировании по дате) — приемлемо для отчётного запроса, но стоит проверить план на реальных объёмах.

**N2. `markNormalizationSkipped()` делает `flush()` внутри `do…while`.**
Флаш на каждой странице WB. Функционально корректно (raw уже сохранён), но при многостраничном дне это N флашей. Не критично; при желании можно батчить. Хендлер получил `EntityManagerInterface` в конструктор — для записи статуса напрямую, ок.

**N3. Асимметрия диапазонов дат.**
Ветка транзакций фильтрует по timestamp `occurred_at >= from AND < to+1day`, ветка окна — по `window_from BETWEEN fromDate AND toDate` (date, inclusive). Обе покрывают `[from, to]` по дням, но смешение `DATETIME_IMMUTABLE`/`DATE_IMMUTABLE` параметров стоит держать в уме при правках.

**N4. `retryAfterSeconds(): ?int`.**
Семантика «не нашли заголовок → null, дефолт решает limiter» чистая. Убедиться, что все вызывающие (`cooldownUntilAfterRemote429($retryAfterSeconds, DEFAULT)`) корректно трактуют null — по коду да (дефолт-параметр передаётся явно).

---

## 4. Корректность (проверено)

- ✅ Все методы `WbFinanceRateLimiter`, вызываемые клиентом (`getActiveSalesReportsCooldownUntil`, `secondsUntil`, `buildSalesReportsRateLimitKeyForSellerBucket`, `tryConsume`, `cooldownUntilAfterRemote429`, `setSalesReportsCooldownUntil`), существуют — фатала нет.
- ✅ Remote-429 теперь записывает cooldown в общее хранилище (`setSalesReportsCooldownUntil`) → следующий запрос по тому же seller-bucket уважает паузу до HTTP-вызова (`getActiveSalesReportsCooldownUntil`). Закрывает класс «оба модуля долбят WB после 429».
- ✅ `sellerBucketId()` устойчив к пустому `connectionRef` (→ `global`).
- ✅ Курсор ограничен `today` (исправление M1 прошлого ревью), валидация формата сохранена.
- ✅ `SKIPPED` корректно отделяет raw-only от `PENDING` — `NormalizePendingRawRecordsCommand` их и так не трогает, плюс теперь статус явный и виден в `wb-finance:status`.
- ✅ `CoverageQuery` использует `COUNT(DISTINCT …)` — дедуп при множественных join-строках на один raw.

## 5. Безопасность

- ✅ `CoverageQuery`: `Assert::uuid($companyId)`, все join-условия и `WHERE` несут `company_id`, явное перечисление колонок, параметризация.
- ✅ Токен/PII по-прежнему не логируются; rate-limiter логирует только `hash_prefix`/`wait_seconds`.
- ✅ Новый статус `skipped` в SQL `wb-finance:status` — строковый литерал в `FILTER`, инъекции нет.

## 6. Тесты

- ✅ `VerificationQueriesTest::testCoverageIncludesRawOnlyIngestionRecordsByJobWindowDate` — raw-only WB запись (`SKIPPED`) попадает в покрытие по дате окна job-а: `rawCount=1, txCount=0, issueCount=0`, дата = `window_from`, shop = `r.shop_ref`. Точно бьёт в новую ветку SQL.
- ✅ Functional `VerificationApiControllerTest` расширен (+69).
- ✅ Юнит-тесты коннектора/клиента расширены (+65/+65) под новый лимитер, cooldown и границу курсора.
- ✅ `RunSyncChunkHandlerTest` (+7) — путь `SKIPPED`.
- ⚠️ Не покрыт edge-кейс M2 (raw-only без `window_from` в инкременте) — если поведение «невидим» намеренное, добавить негативный тест-фиксатор.
- ⚠️ Полный прогон `make test && make stan && make cs` в этом окружении не выполнялся.

---

## 7. Рекомендация

**Approve с замечаниями.** Перед merge:

1. Решение Владельца по кросс-модульной зависимости (**M1**) — это границы модулей, формально high-risk; допустимо принять как осознанный долг с follow-up на вынос лимитера в Shared/Facade.
2. Подтвердить ожидаемость невидимости raw-only без `window_from` (**M2**) или добавить fallback на `fetched_at`.
3. Проверить индексы/план `CoverageQuery` на реальных объёмах (**N1**).
4. Прогнать `make test && make stan && make cs`.

Предыдущие блокирующие наблюдения (курсор в будущее, дублирование метаданных) — **закрыты**.
