# Задача: загрузка каталога листингов Ozon

## Контекст

Владелец сообщил: у листингов Ozon не подгружается наименование — пример
ИП Лазарева, SKU `3732855303`. Дополнительно требуется загружать товары, по которым
ещё не было продаж, и видеть дату создания товара на Ozon.

Разбор кода выявил **три независимых дефекта**, а не один.

### D1 — имя приходит из API и выбрасывается

`SyncOzonListingBarcodesHandler` уже ходит в `/v3/product/info/list` — метод правильный.
Но `OzonProductBarcodeFetcher` маппит ответ только в два поля
(`site/src/Marketplace/Infrastructure/Api/Ozon/OzonProductBarcodeFetcher.php:99`):

```php
$result[$sku] = [
    'barcodes' => $barcodes,
    'offer_id' => $offerId,
];
```

`items[].name` в ответе присутствует, но не читается. Хендлер
(`SyncOzonListingBarcodesHandler.php:104`) обновляет только `supplierSku`, и то лишь
когда он `null`.

### D2 — имя берётся только из финансового документа и только при создании строки

`OzonSalesRawProcessor.php:138` берёт `$item['name']` из `/v3/finance/transaction/list`
и передаёт в `OzonListingEnsureService`. Дальше `OzonListingUpsertQuery` выполняет
`INSERT ... ON CONFLICT DO NOTHING`. Если первой операцией по SKU был возврат, услуга
или заказ с пустым `items[].name`, строка создаётся с `name = NULL` навсегда: ни один
последующий батч её не обновит.

Те же три процессора — единственные источники имени:
`OzonSalesRawProcessor`, `OzonReturnsRawProcessor`, `OzonCostsRawProcessor`,
плюс `MarketplaceListingLinkingFacade:152`.

### D3 — товара без продаж в системе не существует

Вход в `/v3/product/info/list` — список SKU из нашей БД
(`findByCompanyIdAndMarketplace`). Нет продажи → нет строки → нечего запрашивать.
Новый товар невидим до первой финансовой операции.

### Что подтверждено выгрузкой реального API

Снято `site/bin/capture-ozon-listings.sh --pages 1 --limit 50` на тестовом аккаунте
2026-09-01: `/v3/product/list` → 50 товаров из 62, `/v3/product/info/list` → 50 карточек.

| Нужное поле | Источник | Заполнено |
|---|---|---|
| Наименование | `items[].name` | 50 из 50 |
| Артикул продавца | `items[].offer_id` | уникален, 50 из 50 |
| Дата создания товара | `items[].created_at` | 50 из 50 (2021-01-03 … 2026-05-05) |
| Баркоды | `items[].barcodes` | пустых нет |
| Цены | `price` / `old_price` / `min_price` + `currency_code` | строки, RUB |
| Статус | `statuses.status_name`, `is_archived` | есть |

`/v3/product/list` отдаёт `product_id` + `offer_id` + `sku` + `archived` для **всех**
товаров независимо от продаж — это недостающий вход, закрывающий D3. Пагинация
подтверждена: `total=62`, страница вернула 50 и непустой `last_id`.

### Ключевая находка: у одного товара несколько SKU

```
50 товаров → 78 различных SKU
22 товара — один источник (sds)
28 товаров — два источника (sds + fbs), у каждого свой sku
верхнеуровневый sku присутствует в sources[] у 50 из 50
```

Пример:

```json
{
  "name": "Лосины женские спортивные черные легинсы пуш ап...",
  "offer_id": "WJ1021104211/черный-M",
  "sku": 308520421,
  "sources": [
    { "sku": 308520421, "source": "sds", "created_at": "2021-08-24T15:13:21.092316Z" },
    { "sku": 308520498, "source": "fbs", "created_at": "2021-08-24T15:13:23.562997Z" }
  ]
}
```

`marketplace_listings.marketplace_sku` хранит **один** SKU — тот, что пришёл в
финансовом отчёте. Схема FBS даёт другой SKU, чем sds. Сопоставление каталога с
листингами по верхнеуровневому `sku` пропустило бы 28 из 78 SKU (36% выборки), и они
остались бы без имени даже после внедрения каталожной загрузки.

**Ключ сопоставления — всё множество `sources[].sku` ∪ верхнеуровневый `sku`.**
Один товар Ozon может соответствовать двум нашим листингам; обновлять надо оба.

### Непроверенное

Гипотеза по `3732855303` (это вторичный fbs-SKU) **не подтверждена**. Прод-доступ
из окружения агента заблокирован: алиас `vf-prod-codex` не резолвится, в `~/.ssh/config`
только root-алиас, root агенту запрещён. На тестовом аккаунте товара нет
(`items: []` — ожидаемо). Проверяется одним read-only запросом Владельца:

```bash
ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-psql-ro -c \"SELECT id, marketplace_sku, supplier_sku, name, created_at FROM marketplace_listings WHERE marketplace_sku = '3732855303';\"" < /dev/null
```

Результат на дизайн не влияет: сопоставление по множеству SKU корректно в обоих случаях.

Точные rate limits Ozon для этих двух эндпоинтов не подтверждены замером —
закладываем ретраи с backoff, а не конкретное число RPS.

## Решения

| Вопрос | Решение | Основание |
|---|---|---|
| Модуль обработчика | `Marketplace` | целевая сущность `MarketplaceListing` его; Ozon-клиенты и креды там же; прямой прецедент `RefreshWbListingCatalogAction` |
| Raw на S3 | через `Ingestion\Facade\RawStorageFacade` | единственный настоящий S3-raw-слой: gzip-ndjson, дедуп по sha256, указатель в `ingest_raw_record`. `InventoryRawSnapshot` держит payload в Postgres — этот прецедент не повторяем |
| Источник истины для `name` / `supplier_sku` | каталог, с перезаписью | карточка Ozon точнее строки отчёта о начислениях |
| Пропал из каталога | `is_active` не трогаем, пишем `last_seen_at` | решение Владельца: разбор вручную. Побочно защищает от массового гашения при частичном сбое пагинации |
| Дата создания товара | новая колонка `marketplace_created_at` | `MarketplaceListing.createdAt` — дата создания нашей строки, другое понятие |
| Сколько строк на товар с двумя sources | одна, по верхнеуровневому `sku` | вторая появится сама при первой продаже по второй схеме и будет обогащена следующим прогоном; иначе удваиваем таблицу мёртвыми строками |
| Финансовый upsert | **не меняем** | `OzonListingUpsertQuery` остаётся `DO NOTHING`. Каталогу — отдельный query с `DO UPDATE`. Иначе финансовый документ перезаписывал бы каталожное имя, то есть ровно наоборот принятому решению |
| `price` | не трогаем, кладём в `marketplace_data` | каталожная цена — текущая витринная, а не цена продажи. Перезапись сменила бы смысл поля |
| Триггеры | и команда, и кнопка UI | решение Владельца |

## Stage 1: схема и raw-seam

Risk: 🟡 MEDIUM
owner_gate: no
release_candidate: yes
independently_deployable: yes
stage_base_commit: `0a541a3ccc683a53113ef4cf723754d34a258b70`

Подготовительный этап без изменения поведения: колонки и метод фасада, которыми
пользуется Stage 2.

Definition of Done:
- Миграция добавляет в `marketplace_listings` две nullable-колонки:
  `marketplace_created_at TIMESTAMP(0) WITHOUT TIME ZONE`, `last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE`.
- `MarketplaceListing` получает поля, геттеры и сеттеры; конструктор не меняется.
- `RawStorageFacade::storeAndGetIds(RawBatch): list<string>` — возвращает скалярные id
  вместо `list<IngestRawRecord>`. Без него вызывающий код из `Marketplace` держал бы
  managed-сущность чужого модуля; `tests/Unit/Ingestion/Architecture/EntityBoundaryTest`
  ловит текстовую ссылку на `App\Ingestion\Entity\*` вне Ingestion.
- `ARCHITECTURE.md` синхронизирован: новые поля сущности и новый метод фасада.
- Существующее поведение не меняется: ни один вызывающий код новых полей не пишет.

Work items:
- 1.1 — миграция `VersionYYYYMMDDHHMMSS` (две колонки, без индексов: не FK и не фильтры)
- 1.2 — поля в `MarketplaceListing` + unit-тест сеттеров/nullable
- 1.3 — `RawStorageFacade::storeAndGetIds` + unit-тест, что возвращаются строки-id
- 1.4 — `ARCHITECTURE.md`

Карта изменений: 1 миграция, 1 Entity, 1 Facade. Новых Repository, Message, Enum нет.

Stage checks:
- `php bin/phpunit --testsuite=unit --filter 'MarketplaceListing|RawStorageFacade'`
- `composer test:unit`, `composer test:integration`
- `make site-cs-check`, `make site-cs-strict-types`, `make site-stan`
- миграция прогоняется на локальной БД в обе стороны (`up`, затем `down`)

Reviewer focus:
- `marketplace_created_at` не путается с `created_at` ни в имени, ни в маппинге;
- миграция обратима и не блокирует таблицу надолго (ADD COLUMN nullable без DEFAULT);
- `storeAndGetIds` не подтекает сущность через возвращаемый тип или докблок.

## Stage 2: загрузка каталога Ozon

Risk: 🟠 HIGH-LOCAL
owner_gate: no
release_candidate: yes
independently_deployable: yes
stage_base_commit: (HEAD после Stage 1)

Причина 🟠: внешний API, запись в таблицу, которую параллельно пишут три финансовых
процессора, и новая cron-строка.

Definition of Done:
- `OzonProductCatalogClient` обходит `/v3/product/list` по `last_id` (limit 1000) и
  добирает карточки `/v3/product/info/list` чанками по 1000 `product_id`.
- Оба ответа постранично уходят в S3 через `RawStorageFacade::storeAndGetIds`,
  ресурсы `ozon_seller_product_list` и `ozon_seller_product_info`,
  `IngestSource::OZON`, `externalId` = номер страницы/чанка.
- Карта `sku → карточка` строится по **всем** `sources[].sku` плюс верхнеуровневому `sku`.
- Существующие листинги (поиск через `MarketplaceListingRepository::findListingsBySkusIndexed`)
  обновляются: `name`, `supplier_sku`, `marketplace_created_at`, `last_seen_at`,
  `marketplace_data` (statuses.status_name, primary_image, description_category_id,
  is_archived, price/old_price/min_price/currency_code).
- Товар, ни один SKU которого не имеет листинга, создаёт **одну** строку по
  верхнеуровневому `sku` (`size = 'UNKNOWN'`, `price = '0.00'`, как в существующем upsert).
- `is_active` каталогом не меняется ни при каких условиях.
- Идемпотентность: повторный прогон не создаёт дублей и не меняет данные
  (кроме `last_seen_at`).
- Команда `app:marketplace:ozon-listing-catalog:sync` с `LockableTrait`; прогон,
  не давший ни одной задачи из-за ошибок, завершается ненулевым exit code.
- Строка в `docker/cron/app.cron`: `40 3 * * *` (03:40 MSK). Выбор слота: **до**
  `app:marketplace:ozon-daily-sync` в 04:00, чтобы финансовые процессоры видели уже
  именованные листинги. Суточные соседи: 03:00 `app:ingestion:run-incremental`,
  03:10 WB financial daily, 04:00/04:05/04:15/04:30/04:45 — Ozon и WB выгрузки.
  В 03:40 попадают только частые лёгкие задачи (`*/2` ozon-poll-reports,
  `*/5` storage healthcheck, `*/10` ingestion normalize-pending, `*/30` heartbeat).
  Риск, который проверяет ревьюер: `run-incremental` от 03:00 может ещё работать и
  тоже ходит в Ozon Seller API и пишет `ingest_raw_record` — при 429 сдвинуть слот
  или разнести по транспортам.
- Routing в `config/packages/messenger.yaml`: `SyncOzonListingCatalogMessage: async_sync`
  (внешние HTTP-запросы — по правилу транспорта).
- `ARCHITECTURE.md` синхронизирован: таблица cron-задач, новый pipeline.

Work items:
- 2.1 — `Marketplace/Infrastructure/Api/Ozon/OzonProductCatalogClient` (пагинация, чанки, ретраи)
- 2.2 — обезличенная фикстура `tests/Fixtures/Marketplace/Ozon/product_info_list.json`;
  **обязательно с товаром на два источника** — без него тест не поймает главный дефект
- 2.3 — `Marketplace/Application/RefreshOzonListingCatalogAction` (raw→S3, матчинг, upsert)
- 2.4 — `OzonListingCatalogUpsertQuery` (`ON CONFLICT ... DO UPDATE`), отдельный от
  финансового `OzonListingUpsertQuery`
- 2.5 — `SyncOzonListingCatalogMessage` + handler + routing
- 2.6 — `OzonListingCatalogSyncCommand` + cron-строка
- 2.7 — тесты (см. ниже)
- 2.8 — `ARCHITECTURE.md`

Тесты (минимум по `CLAUDE.md`):
- unit на маппинг ответа → DTO, включая товар с двумя `sources`;
- unit: `sources[].sku` попадают в ключ сопоставления, верхнеуровневый `sku` не теряется;
- integration happy-path на фикстуре с моком `HttpClientInterface`: создаёт новые
  листинги, обогащает существующие;
- integration негативный: HTTP 500 на втором чанке не оставляет частично применённых
  изменений и не гасит `is_active`;
- **регрессионный на главный дефект**: листинг с `name = NULL`, чей `marketplace_sku`
  равен **вторичному** (fbs) SKU товара, после прогона получает имя. Поведение новое,
  поэтому по `CLAUDE.md` доказываем на комбинации «новое поведение + старое условие»:
  тот же тест на матчинге только по верхнеуровневому `sku` обязан падать. Факт
  фиксируется в Stage Report;
- integration: повторный прогон идемпотентен.

Stage checks:
- `composer test:unit`, `composer test:integration`
- `make site-cs-check`, `make site-cs-strict-types`, `make site-stan`
- сухой прогон команды на тестовых ключах, сверка счётчиков с `_meta.json` выгрузки

Reviewer focus:
- матчинг по всему множеству SKU, а не по одному (главный риск задачи);
- каталог не меняет `is_active` и не трогает `price`;
- финансовый `OzonListingUpsertQuery` остался `DO NOTHING` — иначе финансы начнут
  перезаписывать каталожные имена;
- `companyId` в каждом методе Repository и Query, IDOR (`docs/workflow/stage-report.md`);
- поведение при нуле подключений, при пустом каталоге, при 429/5xx;
- выбор cron-слота 03:40 и отсутствие конкуренции за `async_sync` с 04:00/04:05.

## Stage 3: ручной запуск из UI

Risk: 🟢 LOW
owner_gate: no
release_candidate: yes
independently_deployable: yes
stage_base_commit: (HEAD после Stage 2)

Definition of Done:
- Кнопка на странице листингов (`marketplace_listings_index`,
  `templates/marketplace/listings/`) диспатчит `SyncOzonListingCatalogMessage`.
- Новое значение `JobType::LISTING_CATALOG_SYNC_OZON` + label; статус прогона виден
  через `MarketplaceJobLog` — как у `BARCODE_SYNC_OZON`.
- Повторное нажатие при активном прогоне не плодит задачи.
- CSRF, `ROLE_COMPANY_USER`, активная компания через `getActiveCompany()`.

Work items:
- 3.1 — значение `JobType` + запись `MarketplaceJobLog` в хендлере
- 3.2 — контроллер-action на dispatch + защита от повторного запуска
- 3.3 — кнопка и отображение статуса в шаблоне
- 3.4 — functional-тест кнопки (happy-path + повторное нажатие)

Stage checks:
- `composer test:functional`, `composer test:integration`
- `make site-cs-check`, `make site-cs-strict-types`, `make site-stan`

Reviewer focus:
- IDOR: компания берётся из `getActiveCompany()`, а не из запроса;
- нет бизнес-логики в контроллере.

## Открытые вопросы

- Нужно ли переносить barcode-sync на каталожный прогон? Каталог уже отдаёт
  `barcodes`, и после Stage 2 отдельный `SyncOzonListingBarcodesHandler` частично
  дублирует работу. Слияние — отдельная задача (FOLLOW-UP), в этот scope не входит:
  у него своя семантика уникальности баркодов и свой `JobType`.
- Товары в архиве (`is_archived: true`) в выборке `visibility: ALL` присутствуют.
  Сейчас пишем их как обычные, помечая `is_archived` в `marketplace_data`. Если
  окажется, что архив шумит в списках — отдельное решение, не в этом scope.

## Артефакты

- `site/bin/capture-ozon-listings.sh` — снятие реальных ответов Ozon в JSON.
  Флаги: `--pages`, `--limit`, `--sku <id>`, `--with-attributes`, `--out`.
  Ключи спрашивает интерактивно (без эха) либо берёт из `OZON_CLIENT_ID` / `OZON_API_KEY`.
- `site/tests/Fixtures/Marketplace/Ozon/captured/` — снимки под `.gitignore`:
  реальные данные продавца в репозиторий не кладём.
