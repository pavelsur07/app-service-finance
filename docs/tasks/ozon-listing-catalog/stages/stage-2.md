## Stage 2: загрузка каталога Ozon — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP — Release Gate, решение Владельца

### Scope Stage
- Stage base commit: `2d32604843166e56147a7167a27e4d5420885014`
- Work items completed: `2.1`–`2.8`

Причина 🟠: внешний API, запись в таблицу, которую параллельно пишут три
финансовых процессора, и новая cron-строка.

### Что сделано

Pipeline: `app:marketplace:ozon-listing-catalog:sync` (cron `40 3 * * *`, либо
`--company=<uuid>` вручную) → `SyncOzonListingCatalogMessage` (`async_sync`) →
`SyncOzonListingCatalogHandler` → `RefreshOzonListingCatalogAction`.

Закрыты три дефекта из плана:

1. **Товар без продаж был невидим.** Вход в `/v3/product/info/list` брался из
   списка SKU нашей БД. Теперь вход — `/v3/product/list`, который отдаёт весь
   каталог независимо от финансовых операций.
2. **`items[].name` выбрасывался.** `OzonProductBarcodeFetcher` читал из ответа
   только `barcodes` и `offer_id`. Новый нормализатор читает имя, `offer_id`,
   `created_at`, баркоды и витринные поля.
3. **`name = NULL` фиксировался навсегда.** Каталожный upsert делает
   `DO UPDATE`. Финансовый `OzonListingUpsertQuery` намеренно оставлен
   `DO NOTHING`: общий `DO UPDATE` позволил бы финансовому документу
   перезаписывать каталожное имя — ровно наоборот принятому решению.

**Ключевое:** сопоставление идёт по всему множеству `sources[].sku` ∪
верхнеуровневый `sku`. На реальной выгрузке 50 товаров дали 78 SKU, у 28 два
источника (sds + fbs). Матчинг по одному sku потерял бы 36% и оставил бы без
имени ровно те листинги, которые завёл финансовый документ по FBS-схеме.
Обновляются **все** листинги товара.

Товар, ни один SKU которого не имеет листинга, создаёт **одну** строку по
верхнеуровневому `sku`. Вторая появится при первой продаже по второй схеме и
будет обогащена следующим прогоном; заводить обе сразу значило бы удваивать
таблицу мёртвыми строками.

Каталог не трогает `is_active` (решение Владельца: разбор ручной) и не пишет
колонку `price` (витринная цена ≠ цена продажи; она в `marketplace_data`).

### Затронутые файлы

Новые: `RefreshOzonListingCatalogAction`, `OzonListingCatalogSyncCommand`,
`OzonProductCatalogClient`, `OzonProductCatalogNormalizer`,
`OzonListingCatalogUpsertQuery`, `SyncOzonListingCatalogMessage`,
`SyncOzonListingCatalogHandler`, `OzonCatalogItemDTO`,
`OzonCatalogSyncResultDTO`, `OzonCatalogApiException`,
`OzonCatalogRateLimitException`, две фикстуры, шесть тестовых классов.

Изменённые: `config/packages/messenger.yaml` (routing на `async_sync`),
`docker/cron/app.cron` (слот `40 3 * * *`), `ARCHITECTURE.md` (версия 1.84,
раздел «Marketplace: загрузка каталога товаров Ozon»).

Миграций нет: обе колонки добавлены Stage 1.

### Отступление от плана Stage — заявлено осознанно

План требовал, чтобы сбой на втором чанке «не оставлял частично применённых
изменений». Реализовано иначе: **одна транзакция на информационный чанк**, а не
на весь прогон. Глобальная держала бы блокировки на `marketplace_listings` всё
время обхода и мешала бы финансовому pipeline; отдельная транзакция на строку
давала бы десятки тысяч autocommit-операций. Upsert идемпотентен, поэтому
частичное применение дозаполняется следующим прогоном.

Отступление защищено тестом `testFailureOnSecondChunkKeepsResultOfTheFirst`:
1001 товар → два чанка, HTTP 500 на втором, проверка что 1000 листингов первого
сохранились.

### Self-review
- [x] Scope compliance — только каталог Ozon; финансовый путь не тронут
- [x] Patterns / naming — `final`, `declare(strict_types=1)`, слои модуля
- [x] Forbidden actions — нет `dump()`, `new Service()`, хардкода секретов,
      `flush()` в Repository
- [x] Security / IDOR — conflict-ключ upsert'а включает `company_id`, поэтому
      чужую строку он изменить не может (тест
      `testAnotherCompanyWithSameSkuIsNotTouched`);
      `findListingsBySkusIndexed` ограничен компанией
- [x] Секреты — `Api-Key` и `Client-Id` не попадают ни в логи, ни в сообщения
      исключений, ни в пути объектного хранилища (`shopRef` = `connectionId`,
      как в `OzonSellerReportConnector::discoverShops()`)
- [x] Логирование — `info` на старт/финиш с `company_id`, `warning` на 429
      (ретрай, не инцидент), `error` на сбой диспетчеризации
- [x] N+1 — один запрос листингов на чанк, не на товар
- [x] Границы модулей — Ingestion используется только через `RawStorageFacade`
      и `App\Ingestion\DTO\RawBatch`; PHPat-правила зелёные
- [x] `ARCHITECTURE.md` обновлён

### Тесты

55 тестов в шести классах, все написаны до реализации и прогнаны красными:

| Класс | Тестов |
|---|---|
| `OzonProductCatalogNormalizerTest` | 12 |
| `OzonProductCatalogClientTest` | 14 |
| `OzonListingCatalogUpsertQueryTest` | 11 |
| `RefreshOzonListingCatalogActionTest` | 9 |
| `OzonListingCatalogSyncCommandTest` | 6 |
| `SyncOzonListingCatalogHandlerTest` | 3 |

**Регрессионный тест на главный дефект** —
`testFillsNameOfListingMatchedOnlyBySecondarySourceSku`: листинг с
`marketplace_sku` = вторичный (fbs) SKU и `name = NULL` после прогона получает
имя. Поведение новое, поэтому по `CLAUDE.md` доказательство — на комбинации
«новое поведение + старое условие»: `OzonProductCatalogNormalizerTest::
testCollectsEverySkuFromSourcesNotOnlyTheTopLevelOne` красный при сборе только
верхнеуровневого `sku`.

Один тест написан **после** реализации и помечен характеризующим:
`testItemWithoutTopLevelSkuStillYieldsSourceSkus` — отдельного production-кода
под него нет, поведение выпало из общего сбора SKU.
`testFailureOnSecondChunkKeepsResultOfTheFirst` тоже прошёл сразу: транзакции
на тот момент не было вовсе. Он оставлен как guard — после введения транзакции
на чанк он доказывает, что она не стала глобальной.

Отдельно исправлен тест, проходивший **по неверной причине**:
`testStopsWhenCursorDoesNotAdvance` сначала зеленел из-за исчерпания очереди
мока, а не из-за защиты. Переписан на бесконечный источник одинаковых страниц.

### Команды для проверки

| Проверка | Результат |
|---|---|
| `composer test:unit` | OK — 1981 tests, 11040 assertions |
| `composer test:integration` | OK — 1021 tests, 5010 assertions |
| `composer cs:check` | Found 0 of 2382, exit 0 |
| `composer cs:strict-types` | Found 0 of 2382, exit 0 |
| `composer stan` (PHPStan level 8) | `[OK] No errors` |
| `composer test:functional` | 1 failure — **красный baseline**, см. ниже |

**Красный baseline.** `DashboardSnapshotGoldenTest::
testSnapshotGoldenValuesForCurrentMonthFromA22Fixtures` падает с
`Failed asserting that 0 is greater than 0`. Доказано, что это не регресс:
изменения спрятаны через `git stash -u`, тест прогнан на
`2d32604843166e56147a7167a27e4d5420885014` — то же падение, то же сообщение,
та же строка. Тест date-зависимый (golden-значения «текущего месяца» на
фикстурах A22), задача его не касается.

Промежуточно интеграционная сюита давала 136 ошибок
`Undefined column: marketplace_created_at` — тестовая БД без миграции Stage 1,
не код. После `make site-test-migrations` зелёная.

### External review

- Reviewer: Codex CLI 0.151.0 (`codex exec -s read-only --ephemeral`, дифф через stdin)
- Iterations: 9
- Result: **REVIEW_GREEN не достигнут.** Stage закрыт решением Владельца:
  оставшиеся находки зафиксированы как FOLLOW-UP. Читать это как «ревьюер не
  нашёл замечаний» нельзя.

Исправлено по ходу итераций (1 BLOCKER отклонён, 12 находок принято):

| Итерация | Класс | Находка | Что сделано |
|---|---|---|---|
| 1 | BLOCKER | каталог создаёт строку с `price='0.00'`, финансовый pipeline потом не запишет цену | **Отклонено.** Живой путь `OzonSalesRawProcessor::processBatch()` → `OzonListingEnsureService` → `OzonListingUpsertQuery` сам вставляет `'0.00'` и никогда не обновляет `price`; единственный метод с `setPrice()` при создании (`ProcessOzonSalesAction`) помечен `@deprecated` «No active callers in production» |
| 1 | IMPORTANT | 429 в `RecoverableMessageHandlingException` обходит `max_retries` | обёртка убрана, исключение пробрасывается как есть |
| 1 | IMPORTANT | бесконечный обход при неподвижном курсоре | guard по повтору `last_id` |
| 1 | IMPORTANT | сбои команды не видны под `--quiet` | внедрён `LoggerInterface` |
| 1 | IMPORTANT | десятки тысяч autocommit-операций | одна транзакция на чанк |
| 1 | MINOR | тест частичного применения ничего не доказывал | тест на 1001 товаре, два чанка |
| 2 | IMPORTANT | freshness-guard закрывал только `last_seen_at` | `WHERE` на весь `DO UPDATE` |
| 2 | IMPORTANT | битый контракт API выглядел успехом | валидация `result.items` / `last_id` / `items` |
| 2 | MINOR | `ARCHITECTURE.md` описывал убранную обёртку | синхронизирован |
| 2 | MINOR | текст исключения транспорта в консоли (может нести DSN) | выводится класс |
| 3 | MINOR | `listings_upserted` считал попытки, а не записи | `upsert()` возвращает affected rows |
| 4 | MINOR | нестрогое `>=` при точности `last_seen_at` в секунду | строгое `>`; моё обоснование `>=` было ошибочным — у листингов одного товара разные conflict-ключи |
| 5 | IMPORTANT | ответ без пригодных элементов = успех | ошибка при нуле пригодных, `warning` при частичном пропуске |
| 6 | IMPORTANT | счёт по элементам ответа, а не по запрошенным `product_id` | счёт от запрошенного, сверка по `items[].id` |
| 7 | IMPORTANT | guard курсора срабатывал на штатной пустой финальной странице | guard только для непустых страниц |
| 7 | IMPORTANT | дубликат карточки прятал пропавший товар | индексация по `product_id` |
| 7 | MINOR | `items` из скаляров давал `RawStorageException` чужого модуля | доменное исключение |
| 8 | IMPORTANT | `result.total` игнорировался, оборванный обход = успех | сверка покрытия обхода |
| 9 | IMPORTANT | каталожные raw навсегда остаются `PENDING` и вытесняют финансовые из окна safety net | **исправлено, см. ниже** |

**Побочная находка, которой не было в ревью.** Тест на пустой ответ упал не тем
исключением и вскрыл настоящий баг: `RawStorageFacade` отвергает батч без строк,
а обход сохранял в raw каждую страницу. Каталог, кратный размеру страницы
(ровно 1000, 2000 товаров), штатно отдаёт пустую последнюю страницу — на ней
ночной прогон падал бы с `RawStorageException`. Закрыто тестом
`testEmptyTrailingPageDoesNotBreakTheWalk`.

**Регресс в соседнем пайплайне, исправленный несмотря на решение об остановке.**
Находка 9 подтверждена в коде: `IngestRawRecordRepository::findStuckPending()`
берёт 50 самых старых `PENDING` (`ORDER BY fetched_at ASC`), а
`NormalizePendingRawRecordsCommand` пропускает записи без маппера через
`continue` **без смены статуса**. Каталожные записи (2+ на компанию за ночь)
оседали бы там навсегда и в пределах ~25 компанио-ночей вытеснили бы из окна
финансовые raw-записи, которые обработать можно. Это поломка существующего
production-механизма, а не доработка новой фичи, поэтому отложена не была.
Исправление минимальное и по правилу `CLAUDE.md` о недостижимых состояниях:
запись без маппера переводится в терминальный `SKIPPED` (статус уже существовал
в `RawNormalizationStatus`). Тест
`NormalizePendingRawRecordsCommandTest::testRecordWithoutMapperIsMarkedSkippedInsteadOfStayingPendingForever`.

### FOLLOW-UP — не сделано в этом Stage

1. **Память `O(весь каталог)` вместо `O(чанк)`** (находка 9.2, не исправлена).
   Action накапливает все `product_id` в памяти, затем `array_chunk()`, а
   `findListingsBySkusIndexed()` на каждом чанке гидратирует полные
   `MarketplaceListing` с присоединённым `Product`, хотя нужен только факт
   наличия SKU. `EntityManager` между чанками не очищается. На повторном
   прогоне по заполненному большому каталогу это может упереться в бюджет
   памяти воркера. Лечится скалярным DBAL-запросом существующих SKU без
   ORM-гидратации и ленивым формированием чанков. **До первого прогона на
   продавце с крупным каталогом — проверить потребление памяти.**
2. **Сериализация прогонов по `(companyId, connectionId)`.** Freshness-guard
   закрывает наблюдаемое последствие гонки (подмену свежего снимка старым), но
   взаимного исключения нет. Уместно в Stage 3, где появляется второй
   пользовательский триггер — кнопка UI.
3. **Слияние с barcode-sync.** Каталог уже отдаёт `barcodes`, и
   `SyncOzonListingBarcodesHandler` частично дублирует работу. У него своя
   семантика уникальности баркодов и свой `JobType` — отдельная задача.
4. **Посторонний дрейф схемы `marketplace_listings`** (`created_at`/`updated_at`
   TYPE, `size DROP DEFAULT`, отсутствующий частичный уникальный индекс).
   Существовал до задачи, в дифф не тащился.
5. **Гипотеза по SKU 3732855303** (вторичный fbs-SKU у ИП Лазарева) остаётся
   непроверенной: прод-доступ из окружения агента не работает.

### Риски / на что обратить внимание ревьюеру
- Первый ночной прогон затронет **все** активные Ozon SELLER-подключения и может
  создать листинги на весь каталог каждого продавца. Объём строк в
  `marketplace_listings` вырастет до размера каталогов.
- Слот `03:40` соседствует с `app:ingestion:run-incremental` (03:00), который
  тоже ходит в Ozon Seller API и пишет `ingest_raw_record`. При 429 слот
  сдвинуть.
- Точные rate limits Ozon не замерены: заложены ретраи транспорта (3 попытки),
  но не throttling между запросами.

### Открытые вопросы
- нет
