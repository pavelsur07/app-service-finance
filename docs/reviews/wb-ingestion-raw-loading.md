# Code Review — WB finance raw ingestion

- **Ветка:** `feature/wb-ingestion-raw-loading`
- **База:** `master`
- **Коммит:** `3273368b Add WB finance raw ingestion`
- **Объём:** 24 файла, +1627 / −6
- **Дата ревью:** 2026-06-22

> `gh` CLI в окружении недоступен — ревью выполнено по локальному diff `master...HEAD`.
> PHP/PHPUnit запускается через Docker/make, в этом окружении не исполнялся — тесты глазами проверены, прогон не делался.

---

## 1. Что делает PR

Добавляет источник ingestion **Wildberries Finance** (этап «сырой загрузки», без нормализации):

- **HTTP-клиент** `WbFinanceReportClient` — постраничное чтение `POST /api/finance/v1/sales-reports/detailed` с пагинацией по `rrdId`, классификацией статусов (401/403 → auth, 429 → rate-limit, 5xx → transient), локальным rate-limiter (1 запрос / 70 c на seller).
- **Коннектор** `WbFinanceReportConnector` (`SourceConnectorInterface`) — курсор `{date, rrdId}`, посуточные чанки, сырые строки складываются **без** нормализации (`normalizeRawRecords = false`), для пагинации возвращается `continuationDelaySeconds`.
- **DTO** `PullResult` расширен полями `normalizeRawRecords`, `continuationDelaySeconds`.
- **Handler** `RunSyncChunkHandler` — поддержка отложенного продолжения чанка (`DelayStamp`) при пагинации и при `ConnectorRateLimitedException`; `RunSyncChunkMessage` получил `?string $cursorValue`.
- **Инфраструктура** — `WbCredentialProviderInterface` + фасадный провайдер, новое исключение `ConnectorRateLimitedException`, `MapperRegistry::has()` + guard в `NormalizePendingRawRecordsCommand` (не дёргать нормализацию без зарегистрированного маппера).
- **Диагностика** — CLI `app:ingestion:wb-finance:probe` (read-only проба одной страницы) и `app:ingestion:wb-finance:status` (статус job/raw/ошибок).
- **Backfill** — `wildberries` зарегистрирован как источник, посуточный размер чанка (1 день вместо 7).
- **Тесты** — 2 unit-файла (коннектор + клиент) и 2 интеграционных кейса (backfill, отложенное продолжение).

---

## 2. Общая оценка

Сильная, аккуратная работа. Слои соблюдены (Infrastructure/Application/Facade), DI и rate-limiter сконфигурированы согласованно (`rate_limiter.yaml`, `services.yaml`, `messenger.yaml`), классификация ошибок и логирование соответствуют правилам проекта (токен **не** логируется, тело ответа не логируется — только id/статус/длительность). Изменения существующего поведения (Ozon) обратносовместимы за счёт дефолтов `normalizeRawRecords = true` / `continuationDelaySeconds = null`.

**Вердикт:** к merge близко. Блокеров нет; есть 1 заметный архитектурный вопрос (инкрементальный курсор) и несколько MEDIUM/MINOR замечаний к подтверждению Владельцем.

---

## 3. Замечания

### 🟡 MEDIUM

**M1. Инкрементальный курсор уходит в будущее без верхней границы.**
`WbFinanceReportConnector::nextIncrementalDateCursor()` при `hasMore = false` и пустом окне всегда возвращает `date + 1 day`. В инкрементальном режиме (`updateCursor`) курсор будет монотонно расти, в т.ч. **за пределы вчерашнего дня**: каждый прогон тянет следующую дату, для будущих дат API вернёт пусто, сохранится empty-marker, курсор всё равно сдвинется. Риски:
- если за день будет >1 прогона — курсор «перепрыгнет» сегодняшнюю дату, и поздно приходящие данные по дню будут пропущены;
- бесконечное накопление empty-marker записей по будущим датам.

Стоит ограничить продвижение `min(date+1, yesterday)` либо не сдвигать курсор за `today-1`. Рекомендую подтвердить ожидаемое поведение инкремента с Владельцем (WB detailed-отчёт финализируется не сразу — посуточный `daily` может быть частичным).

**M2. Дублирование метаданных в каждой строке.**
`rowsOrEmptyMarker()` добавляет `_ingestion_resource` и `_ingestion_metadata` в **каждую** строку. При полной странице (`PAGE_SIZE = 100000`) одни и те же метаданные дублируются до 100k раз внутри одного raw-батча → существенный рост `byte_size` в `ingest_raw_records` и нагрузка на JSON-(де)сериализацию. Метаданные логичнее хранить один раз на уровне батча/записи, а не на каждой строке. Подтвердить с учётом будущего маппера.

### 🟢 MINOR / NIT

**N1. Per-resource ветка в фасаде.**
`SyncFacade::startBackfill()` содержит `WbResourceType::FINANCE_SALES_REPORT_DETAILED === $command->resourceType ? 1 : 7`. Работает, но размер чанка по `resourceType` лучше вынести в конфиг/стратегию коннектора, чтобы фасад не знал про конкретные ресурсы. Не блокер.

**N2. Граница пагинации `count($rows) >= $limit`.**
Если WB вернёт ровно `limit` строк на последней странице — будет лишний запрос следующей (пустой) страницы. Это стандартный безопасный оверхед пагинации, отмечаю как ожидаемое поведение.

**N3. `nextRrdId <= $rrdId` → `RuntimeException`.**
Защита от бесконечной пагинации корректна, но «строго возрастающий rrdId» — предположение о контракте WB. Если WB на границе страницы вернёт равный rrdId, чанк упадёт в fail. Поведение защитное и приемлемое; зафиксировать как известное допущение.

**N4. Реальный `WbFinanceReportConnector` покрыт только unit-тестами.**
В `services_test.yaml` тег коннектора снят (`tags: []`), интеграционные кейсы используют `FakeConnector` под `IngestSource::WILDBERRIES`. Unit-покрытие коннектора и клиента подробное, но связки «registry → handler → реальный WB-коннектор» в интеграции нет. Приемлемо для этапа сырой загрузки; учесть при добавлении маппера.

**N5. `WbResourceType` — `final class` с константой, не enum.**
Соответствует существующему `OzonResourceType`, замечание только на согласованность — ок.

**N6. ARCHITECTURE.md.**
В коммите ветки не затронут (в рабочем дереве помечен как modified, но не в этом коммите). Публичная сигнатура `SyncFacade` не изменилась, нового Facade-метода нет — формально обновление не требуется, но стоит свериться, что новый источник/коннектор отражён там, где описаны connectors/sources.

---

## 4. Корректность (проверено)

- ✅ Обратная совместимость Ozon: дефолты новых полей `PullResult` сохраняют прежний путь (`do…while` + нормализация).
- ✅ Continuation-флоу: при `hasMore && continuationDelaySeconds` хендлер диспатчит отложенное сообщение и `return` **до** `markJobCompleted`; завершение наступает только на последней странице (`hasMore = false`). Прогресс не двойной счёт.
- ✅ Rate-limit: локальный лимитер (`fixed_window`, 1/70 c, ключ `wb-finance:{connectionRef}`) согласован с `continuationDelaySeconds = 70` и `wb_finance.retry_delay_ms`. Токен не расходуется при отклонении (проверка до HTTP-вызова).
- ✅ Retry-After: парсинг `retry-after` / `x-ratelimit-retry` (относительные секунды) и `x-ratelimit-reset` (абсолютный timestamp/дата) через `ClockInterface`, fallback 70 c, `max(1, …)`.
- ✅ Курсор: декодирование принимает и JSON `{date,rrdId}`, и «голую» дату; валидация формата `!Y-m-d` строгая; `rrdId` неотрицательный.
- ✅ Континуация при rate-limit повторяет **тот же** `cursorValue` (страница не была получена) — корректно.

## 5. Безопасность

- ✅ Токен/PII не логируются; логируются только `companyId/connectionRef/endpoint/date/rrdId/limit/statusCode/durationMs`.
- ✅ Тело ответа внешнего API не логируется.
- ✅ `WbFinanceStatusCommand`: raw SQL с явным перечислением колонок (нет `SELECT *`), все запросы фильтруют `company_id`, параметризация через DBAL (`ArrayParameterType::STRING`).
- ✅ Все CLI принимают и валидируют `company-id` как UUID (`Assert::uuid`).
- ⚠️ `WbFinanceProbeCommand --with-values` печатает усечённый JSON строк отчёта в stdout — admin-CLI, токен не печатается; приемлемо.

## 6. Производительность

- ⚠️ См. **M2** (дублирование метаданных в строках) — основной перф-риск по объёму.
- ✅ `wb-finance:status` агрегирует через `GROUP BY` + `COUNT(*) FILTER`, лимит ошибок `LIMIT 10` — без выгрузки больших наборов.
- ✅ HTTP timeout 120 c под крупные отчёты — разумно.

## 7. Тесты

- ✅ `WbFinanceReportConnectorTest` — capabilities/push, raw без нормализации, отложенная континуация, курсор из encoded-значения, empty-marker, валидация однодневного окна.
- ✅ `WbFinanceReportClientTest` — формирование запроса/парсинг, hasMore, 204, 401/403, 429 + retry-header, локальный лимитер блокирует второй запрос.
- ✅ Интеграция — backfill `wildberries` с посуточными чанками (3 дня → 3 чанка), отложенная континуация с `DelayStamp(70000)` и `cursorValue`, job остаётся `RUNNING`.
- ⚠️ Не покрыто: продвижение инкрементального курсора (см. M1); прогон тестов в этом окружении **не выполнялся** (нет PHP вне Docker) — рекомендуется `make test --filter Wb` + `make stan` перед merge.

---

## 8. Рекомендация

**Approve с замечаниями.** Перед merge:

1. Подтвердить/ограничить поведение инкрементального курсора (**M1**).
2. Решение по дублированию метаданных на строку (**M2**).
3. Прогнать `make test && make stan && make cs` (в этом окружении не запускалось).

MINOR-замечания (N1–N6) — на усмотрение, не блокируют.
