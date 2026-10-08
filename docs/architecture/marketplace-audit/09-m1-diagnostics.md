# Marketplace M1: лёгкая диагностика производительности

Срез: ветка `feat/marketplace-m1-perf-diagnostics`, 2026-10-08. M1 из [06](06-migration-roadmap.md):
измеримый baseline Ozon/WB без новой инфраструктуры и без изменения поведения. Граница
нормализации — в [08](08-normalization-boundary.md), шаблон результатов — в [10](10-m1-baseline-template.md).

## Что сделано и чего нет

Добавлены только замеры. Сообщения Messenger, их классы и сериализация, routing и транспорты,
хранение и формат raw, нормализация, финансовые расчёты, закрытие месяца и порядок posting не
изменены. Ни одна существующая проверка, исключение или ретрай не перехватываются: `measure()`
пробрасывает исходное исключение тем же объектом. Существующие 3223 unit-теста проходят без
единой правки (и 266 тестов затронутых классов — с `MARKETPLACE_PERF_DIAGNOSTICS=1`); интеграционный
`PerformanceDiagnosticsPipelineTest` обрабатывает один и тот же raw WB без замера (регистратор вне области —
тот же путь, что при выключенном флаге) и с замером и сверяет строки продаж, возвратов и затрат до копейки.

### Расхождение с исходной посылкой: где лежит raw

Бриф исходил из того, что raw Marketplace хранится в S3. По коду это не так:
`MarketplaceRawDocument.rawData` — JSON-колонка PostgreSQL (`site/src/Marketplace/Entity/MarketplaceRawDocument.php:65-66`),
её `json_encode` выполняет Doctrine на `flush()`, `json_decode` — при гидрации на `find()`.
S3 (`ObjectStorageInterface`, драйвер `s3`) хранит raw Ingestion (`StoreRawBatchAction`,
`ReadRawRecordAction`) и бронзу MarketplaceAds. Поэтому этап storage замеряется раздельно по
`backend`: `s3`/`local` — декоратор хранилища, `postgres` — загрузка и запись raw-документа.
Ничего в хранении не меняется.

## Архитектура замеров

```text
воркер: WorkerMessageReceived ──► beginScope(job, provider, company_id, transport, trace, retry_count)
                                     └─ queue_wait пишется сразу
   handler ─► start()/add()/measure() в точках этапов ─► накопление в памяти (сумма, вызовы, rows, bytes, errors)
воркер: Handled / Failed ─────────► endScope(ok|retry|error): одно событие на этап + `handler`
                                         └─ Monolog channel `performance` → var/log/performance-<Y-m-d>.jsonl
```

- **Область** — одно сообщение воркера из `App\Marketplace\*` или `App\Ingestion\*`
  (`MessengerPerformanceSubscriber`) или один запуск `app:marketplace:*` команды
  (`ConsolePerformanceSubscriber`). Сообщения других модулей на тех же воркерах (Cash, Inventory,
  MarketplaceAds, почта) не замеряются и не расходуют лимит событий; их влияние на очередь видно через
  lag замеряемых сообщений того же транспорта. Вне области (php-fpm, прочие
  команды, `messenger:consume` между сообщениями) ничего не замеряется.
- **Накопление.** Строки классифицируются тысячами, mapping вызывается на каждую строку — писать
  событие на вызов значило бы залить диск. Внутри области этап копится в памяти и пишется одним
  событием `(stage, provider, backend)` с `calls`, суммой `rows`/`bytes` и `errors`.
- **Ядро:** `site/src/Shared/Infrastructure/Performance/` — `PerformanceRecorder`, `PerformanceStage`,
  `PerformanceOutcome`, `PerformanceProbe`, подписчики, `PerformanceLogReader`, `PerformanceReportBuilder`.

### Этапы и точки замеров

| stage | Где измеряется | rows / bytes |
|---|---|---|
| `api_fetch` | `OzonAccrualByDayClient::request()` (страница), `fetchServiceTypes()`; `OzonRealizationFetcher::fetch()`; `WbFinanceSalesReportClient::fetchDetailedPage()` (HTTP + тело) | rows — строк в ответе; bytes: Ozon — `size_download` (байты по сети, при gzip — сжатые), WB — длина распакованного тела; bytes/s между провайдерами **не сравнимы**. Неуспешный статус (429, 5xx, 401) — `errors+1` |
| `source_parse` | WB: `json_decode` тела ответа | rows, bytes |
| `storage_read` | `postgres`: первая загрузка `MarketplaceRawDocument` в `ProcessDayReportHandler` и `ProcessRawDocumentStepMessageHandler` (гидрация JSON); `s3`/`local`: `PerformanceObjectStorage::read/readStream/exists` | rows — `recordsCount` документа; bytes для S3 |
| `storage_write` | `postgres`: Doctrine flush, в котором есть `MarketplaceRawDocument` (`MarketplaceFlushPerformanceListener`); `s3`/`local`: `write/delete` | bytes для S3; rows для postgres недоступны |
| `source_normalize` | классификация строк в `ProcessMarketplaceRawDocumentAction::processRows()` (время копится локально); Ozon by-day `extractSales/extractReturns/extractEntries` | rows |
| `financial_mapping` | `MarketplaceCostCategoryResolver::resolve()`, `MarketplaceCostPriceResolver::resolve*()` | rows = вызовы |
| `financial_posting` | Doctrine flush со строками учёта (`MarketplaceSale/Return/Cost/OzonRealization`) или сущностями `App\Finance\Entity\*` | rows = вставки/изменения/удаления этих сущностей |
| `processor_total` | вызов процессора (`processBatch`/`process`) из `ProcessMarketplaceRawDocumentAction`; `ProcessOzonRealizationAction` целиком | rows — строк в батче / результат |
| `queue_wait` | `WorkerMessageReceivedEvent`, из id записи Redis Stream | — |
| `handler` | от Received до Handled/Failed | `memory_base_bytes` в начале области |

**Вложенность.** `financial_mapping`, `financial_posting` и Ozon `source_normalize` выполняются
внутри `processor_total`; сумма этапов не равна `handler`. Остаток `processor_total − mapping −
posting − normalize` — сборка сущностей, загрузка листингов и справочников, SQL-запросы процессора.

### Формат события (schema `v=1`)

JSON-строка Monolog: `message="perf"`, поля в `context`:

| Поле | Значение |
|---|---|
| `v` | версия схемы (1); отчёт пропускает другие версии и считает их |
| `stage`, `provider` (`ozon`/`wb`/`none`), `backend` (`postgres`/`s3`/`local`/null) | ключ группы |
| `duration_ms` | сумма времени этапа в области; у `queue_wait` — lag, `null` если недоступен |
| `calls`, `rows`, `bytes`, `errors` | счётчики этапа |
| `memory_peak_bytes` | пик памяти процесса в области (см. ограничения); `memory_base_bytes` — только у `handler` |
| `outcome` | `ok` / `retry` / `error` / `unknown` — исход всей области |
| `retry_count` | из `RedeliveryStamp` |
| `company_id` | UUID компании из сообщения (политика логирования требует companyId в обработчиках) |
| `job` | короткое имя класса сообщения или имя команды |
| `transport`, `trace` | имя транспорта; id записи Redis Stream — корреляция `queue_wait` и `handler` |
| `lag_source`, `dropped_events` | источник lag; число событий, отброшенных лимитом |

Не пишутся: тела ответов и raw, суммы и любые финансовые значения, SKU/srid/артикулы, пути
объектов, токены, ФИО/ИНН, тексты исключений. Это закреплено тестом белого списка полей
(`PerformanceRecorderTest`) и интеграционным тестом, ищущим суммы и srid в событиях.

### Очередь

- **Время постановки.** Redis transport добавляет запись `XADD … '*'`, id = `<unix ms>-<seq>`;
  Symfony кладёт его в `TransportMessageIdStamp`. lag = время старта обработки − ms из id. Контракт
  сообщений не меняется.
- **Отложенные** (`DelayStamp`, ретраи) лежат в sorted set `<stream>__queue` и переносятся в поток,
  только когда воркер опрашивает транспорт (`Connection::get()`): новый id = момент опроса, а не срок
  готовности. Время, которое созревшее отложенное сообщение ждало занятого воркера, **не видно** —
  lag повторов занижен (≈ 0). Поэтому в отчёте отдельно считается `redelivered`; lag повторов
  трактовать как нижнюю границу.
- **Повторная доставка своего pending** после рестарта воркера и claim после `redeliver_timeout`
  сохраняют старый id: lag завышен на время первой попытки.
- **failed** (Doctrine, целочисленный id) — lag недоступен, событие с `lag_source=unavailable`.
- **Часы:** Redis и воркеры на одном хосте; при рассинхронизации lag смещается на разницу.
- **Снимок очередей** в отчёте: `XLEN`, сводка `XPENDING`, старейшая запись `XRANGE - + COUNT 1`,
  `ZCARD` отложенных — O(1)/O(log n), без ACK/claim; `failed` — тот же агрегатный SELECT, что у
  `app:messenger:failed-queue-check`. `ingest_fetch/normalize` делят потоки с `async_sync/pipeline` и
  в снимке видны вместе с ними.

## Управление

| Действие | Как |
|---|---|
| Включено в prod | `MARKETPLACE_PERF_DIAGNOSTICS: ${MARKETPLACE_PERF_DIAGNOSTICS:-1}` в `x-php-env` `docker-compose.prod.yml` — деплой включает |
| Выключить без релиза | `MARKETPLACE_PERF_DIAGNOSTICS=0` в host-env и пересоздать PHP-контейнеры (`docker compose -f docker-compose.prod.yml up -d` воркеров, php-cli, php-fpm). Флаг читается при старте процесса. Это изменение prod-окружения — отдельное согласование (AGENTS.md §3.3). Следующий штатный деплой экспортирует только переменные из `deploy.yml` и вернёт умолчание `1`; закрепить выключение — отдельная правка `deploy.yml` (CI/CD) или PR с умолчанием `0` |
| Локально / тесты | по умолчанию `0` (`site/.env`, параметр `app.performance_diagnostics_default`) |

**Объём и retention.** `rotating_file`, 14 суточных файлов, `file_permission 0666` (файл дня может
создать процесс под `app` или ручной запуск под `root`; принятый риск: любой процесс контейнеров может
дописать в файл ложное событие — данные только диагностические). Событие ≈ 380 байт. Лимит — 300 событий
в минуту на процесс (`app.performance_diagnostics_max_events_per_minute`), излишек отбрасывается и
считается в `dropped_events`. Худший случай: 300 × 1440 × 380 Б ≈ 165 МБ/сутки на процесс, ~6 PHP-процессов
пишут — до ~1 ГБ/сутки и ~14 ГБ за 14 дней; при росте `dropped_events` выключать флагом (инцидент 19.09 —
диск по inode, файлов здесь не больше 14). Оценка для 2–3 кабинетов:
каждое сообщение даёт 2 события (`queue_wait`, `handler`) плюс по одному на затронутый этап (обычно
2–6) — порядка 10–40 тыс. событий в сутки, 4–15 МБ/сутки, до ~200 МБ за 14 дней на томе
`site_var_log`. Фактический объём — в первой строке отчёта.

**Отказоустойчивость.** Любая ошибка записи (нет прав, диск) перехватывается внутри регистратора:
одна строка `Performance diagnostics write failed; …: <класс исключения>` на процесс прямо в `error_log`
(stderr контейнера, мимо буфера fingers_crossed), дальше счётчик. Исключение подписчика на Received не
роняет воркер. Исключение бизнес-кода проходит через `measure()` без изменений.

## CLI-отчёт

```bash
php bin/console app:marketplace:perf-report                       # последние 7 дней, Markdown
php bin/console app:marketplace:perf-report --from=2026-10-09 --to=2026-10-22 --format=json
php bin/console app:marketplace:perf-report --skip-queues --max-mb=64
```

Только чтение: суточные файлы за период (не больше 31 дня), построчно, потолок `--max-mb` (256) и
`--max-events` (1 000 000) — при достижении отчёт помечается «ОБРЕЗАНО». PostgreSQL — только
агрегат по `failed`. Разделы: этапы (events, calls, p50/p95/p99/max, rows, rows/s, bytes, bytes/s,
errors, max peak memory, событий без rows), очередь по транспортам (lag p50/p95/p99, lag n/a,
redelivered), обработчики по типу сообщения (время, failed/retried/unknown, пик и прирост памяти),
полнота (попытки без события завершения по ключу `transport|trace` с учётом повторов — убитый воркер
или граница периода; события без длительности; отброшенные), живой снимок очередей. Нет данных — явное сообщение с чек-листом.

Перцентили — nearest-rank по событиям. Событие этапа — сумма за сообщение, поэтому p95 этапа =
«95% сообщений тратят на этап не больше», а не латентность одного HTTP-запроса. rows/s и bytes/s —
сумма rows/bytes, делённая на сумму длительности событий, где они известны.

## Ограничения: что не измеряется или измеряется грубо

1. **Ozon: разбор JSON ответа не отделён от загрузки.** `toArray()` читает и декодирует тело одним
   вызовом; отделить значит заменить его своим `json_decode` — изменение поведения клиента. Время
   разбора Ozon входит в `api_fetch`, `source_parse` есть только у WB.
2. **Разбор raw из PostgreSQL не отделён от чтения.** `json_decode` колонки делает Doctrine при
   гидрации; `storage_read/postgres` = SELECT + декодирование. Последующие `find()` того же документа
   в сообщении берутся из identity map и не замеряются; процессоры затрат и `ProcessOzonRealizationAction`,
   перечитывающие документ после `clear()`, учтены только внутри `processor_total`.
3. **Нормализация WB, сопоставление и сборка сущностей идут одним циклом** (`WbSalesRawProcessor`,
   `WbReturnsRawProcessor`, `ProcessWbCostsAction`), как и в `ProcessOzonRealizationAction`. Отдельно
   видны только классификация строк, `financial_mapping` и `financial_posting`; остальное — в
   `processor_total`. Искусственная граница ради метрики не вводилась (TASK §2).
4. **`financial_posting` — только Doctrine flush.** Не входят: `commit` внешней транзакции DBAL
   (замена строк by-day), DELETE/UPDATE через DBAL (`deleteByRawDocument`, очистка затрат), upsert
   `pl_daily_totals` в `PLRegisterUpdater`. Сбойный flush до `postFlush` не доходит и не учитывается —
   его видно по `outcome` события `handler`. Flush, в котором одновременно raw-документ и строки учёта,
   считается только как `storage_write`; flush, запущенный из чужого `postFlush`, перезаписывает замер
   внешнего.
5. **Память.** `memory_reset_peak_usage()` в начале области опускает пик до текущего потребления,
   поэтому `memory_peak_bytes` включает базу процесса (ядро, контейнер; ~120–150 МБ). Прирост от
   сообщения — `memory_peak_bytes − memory_base_bytes` («max growth» в отчёте). Пик относится ко всей
   области, у этапов отдельного пика нет.
6. **Ретраи внутри HTTP-клиентов отсутствуют** (их нет в коде: ретраит Messenger). `retry_count` — номер
   повторной доставки сообщения; число попыток статуса синка (`getAttempts()`) не пишется.
7. **Scheduler не замеряется:** у контейнера нет тома `site_var_log`, флаг ему не передаётся. Cron-команды
   лишь ставят сообщения; их обработка замеряется в воркерах.
8. **Не покрыто:** сообщения MarketplaceAds, Inventory, Cash и др. — вовсе (только косвенно через lag);
   Ingestion-нормализация как этапы (её сообщения получают только `queue_wait`/`handler`, обращения к
   S3 — `storage_*`), `ReprocessCostsMessage`, ручной
   `ProcessRawDocumentAction` вне конвейера, `cleanupWbOpenRowsByExternalIds()` (повторная
   классификация при forceRefresh).
9. **Права на файл.** Если том `site_var_log` уже содержит каталог с владельцем, в который не может
   писать пользователь `app`, замеры молча (один warning) не пишутся — бизнес-операции не страдают.
   Проверяется в post-deploy sanity.

## Накладные расходы (локально, PHP 8.4, test env)

| Замер | Результат |
|---|---|
| `start()` + `add()`, флаг выключен / вне области | ≈ 107 нс на пару вызовов |
| `start()` + `add()`, флаг включён, в области | ≈ 670 нс на пару вызовов (накопление в памяти) |
| область с двумя событиями в файл | ≈ 45 мкс (≈ 22 мкс на событие JSON + fwrite) |
| средний размер события | ≈ 380 байт |
| WB sales, 1500 строк, 3 прогона × 2 серии | выключено: 2216–2423 мс; включено: 2219–2492 мс — в пределах шума |

Расчётная добавка на 1500 строк: ~1500 вызовов mapping × 0,67 мкс + ~5 событий ≈ 1 мс, ≈ 0,05% времени
шага. На 100 000 строк WB ≈ 70 мс mapping-замеров при шаге в десятки секунд. Production-замер
«выключено vs включено» — в [10](10-m1-baseline-template.md); до него оценка остаётся локальной.

Побочное наблюдение того же локального прогона (гипотеза, не вывод): `financial_mapping`
(поиск себестоимости на каждую строку) занял ~60% времени шага продаж WB. Проверяется на
production-baseline до любых решений M2.

## Rolling deploy и откат

- Сообщения и штампы не меняются: старые сообщения в Redis и `failed` читаются новым кодом; старый код
  не видит новых полей (их нет). Порядок раскатки не важен.
- Новые конструкторные зависимости имеют умолчание «выключенный регистратор», поэтому ручное создание
  сервисов (тесты, консольные скрипты) не ломается.
- Схема БД и миграции не затронуты.
- Откат: флаг `0` (без релиза, с пересозданием контейнеров) или предыдущий образ. Файлы
  `performance-*.jsonl` остаются и удаляются ротацией через 14 дней; их удаление не влияет ни на что.

## Post-deploy sanity (после «merge and deploy», read-only wrappers)

0. **Wrapper.** `app:marketplace:perf-report` добавлен в репозиторную копию `docs/maintenance/codex-console.sh`
   (read-only, без `--log-dir`). Установка обновлённого wrapper'а на прод — расширение доступа, отдельное
   согласование (AGENTS.md §3.3); до неё шаги 2 и шаблон [10](10-m1-baseline-template.md) недоступны.
1. Воркеры `sync`, `pipeline`, `wb-finance`, `ads` в `Up (healthy)`; рестартов после деплоя нет.
2. `app:marketplace:perf-report --from=<день деплоя>` через `codex-console`: события есть, `invalid_lines=0`,
   `dropped_events` близко к 0; есть этапы `api_fetch`, `storage_*`, `financial_*` для обоих провайдеров
   после ближайшего ночного синка.
3. В stderr контейнеров (логи docker) нет `Performance diagnostics write failed`, и в отчёте есть события
   от каждого типа воркера (`transport` sync / pipeline / wb_finance / ads) — иначе процесс не может писать
   в `var/log`.
4. Очереди: снимок отчёта и `app:messenger:failed-queue-check` — без роста относительно дня до деплоя.
5. Синки Ozon/WB за день деплоя завершились (статусы дней), сверки и гейты (`wb-unrecognized-costs`,
   reconciliation) не покраснели; суммы дня по `marketplace_sales/returns/costs` совпадают с ожиданием
   по raw (те же проверки, что в шаблоне [10](10-m1-baseline-template.md)).
6. Объём `var/log/performance-*.jsonl` за сутки — в пределах оценки.
