# Checkpoint — загрузка заказов Ozon и WB

Ветка: `feat/marketplace-orders`. База стадий: `01a508ab`.

## Готово

### Stage 1 — уборщик зависших SyncJob (`01a508ab`, `af86040f`)
`app:ingestion:reap-stale-jobs`, cron `52 * * * *`. OPEN → CANCELLED,
RUNNING → FAILED с причиной `stale_no_progress`. `SyncJob::heartbeat()` и его
вызов в `RunSyncChunkHandler` — без него многочасовой backfill выглядел бы
зависшим и был бы убран на живом ходу.

### Stage 2 — модель данных и словарь статусов (`3fd602e0`)
`IngestOrder`, `IngestOrderItem`, `IngestOrderStatusEvent`,
`IngestOrderStatus`, `IngestOrderScheme`, `IngestOrderStatusMapper`,
миграция `Version20260901180000`.

### Stage 3 — коннектор заказов Ozon, почасовая каденция
`28cb775a`, `586dd23f` и семь коммитов по итогам внешнего ревью
(`9bbacc2d`, `f3829fca`, `3bc99761`, `853fa385`, `fed5506b`, `0a642b2e`,
`1584d3ea` и далее).

Состав: `OzonOrdersClient` + интерфейс, `OzonOrdersConnector`,
`OzonOrderMapper`, контракты `OrderMapperInterface` / `MappedOrder` /
`MappedOrderItem` / `MappedOrderBatch` / `OrderMapperRegistry`,
`NormalizeOrderRawRecordAction` и ветвление в `NormalizeRawRecordHandler`,
`AbstractHourlyCursorIncrementalStrategy` + `OzonOrdersIncrementalStrategy`,
`EnsureOrdersCursorAction`, опция `--resource` у `run-incremental`,
часовые строки крона `35/37 * * * *`.

**Внешнее ревью: `REVIEW_GREEN` на десятом круге.** Найдено и исправлено
2 BLOCKER и 21 IMPORTANT. Самые дорогие:

- продолжение пагинации теряло окно и смещение — заказы после 20-й страницы
  не читал никто и никогда;
- Ozon получал обратное окно при курсоре впереди часов, и HTTP 400 останавливал
  загрузку неповторяемой ошибкой;
- `ConnectorRateLimitedException` вызывался с одним аргументом — 429 давал
  ArgumentCountError вместо отложенного ре-диспатча;
- идентичность заказа без `connectionRef` слила бы два кабинета Ozon одной
  компании в одну запись;
- позиционная идентичность позиции перемешивала данные соседних товаров при
  перестановке `products`;
- даты со смещением не приводились к UTC — сдвиг заказа на три часа;
- порог каденции ровно в 60 минут делал ресурс двухчасовым;
- опечатка в `--resource` была тихим успехом при `--quiet`.

Гейты на момент закрытия: `cs-check` 0/2424, `cs-strict-types` 0/2424,
PHPStan level 8 — 0 ошибок (baseline сокращён 13 → 12, не рос),
unit 2091, integration 1068. Схема сверена с сущностями: дрейфа нет.

## Осталось

- **Stage 4** — коннектор заказов Wildberries: три ресурса
  (`wb_orders_marketplace`, `wb_orders_statistics`, `wb_orders_sales`),
  сшивка по `rid = srid`, реализация `WbListingResolver` (сейчас заглушка).
- **Stage 5** — почасовой цикл перепроса статусов
  (`app:ingestion:orders:refresh-statuses`) и журнал.
- **Stage 6** — retention raw: удаление объектов старше года вместе со
  строками `ingest_raw_records`.

## Известные ограничения и FOLLOW-UP

1. **Ozon фильтрует отправления по времени СОЗДАНИЯ.** Почасовой обход видит
   заказ один раз; его дальнейшие смены статуса заметит только цикл перепроса
   нетерминальных заказов (Stage 5). До него история статусов заполнена лишь
   первым наблюдением.
2. **Нет данных FBS.** Снятое окно вернуло ноль отправлений, поэтому словарь
   статусов FBS не наблюдался, а поле даты не выбрано однозначно: маппер
   принимает `in_process_at` и `created_at` с приоритетом по схеме. Сузить
   можно после реальной выгрузки с непустым окном.
3. **Нет защиты от гонки при параллельной нормализации одного заказа.**
   Заказы читаются обычным SELECT без блокировки и без версии. Монотонность
   `statusObservedAt` защищает от переупорядочивания сообщений, но не от
   одновременных обработчиков. Лечится сериализацией обновления
   (PESSIMISTIC_WRITE либо optimistic version с повтором) — архитектурное
   решение для всего пути нормализации, включая финансовый.
4. **Красный baseline вне этой задачи.**
   `DashboardSnapshotControllerTest::testCurrencyFiltersCashWidgetsAndSeparates
   CachedSnapshots` падает 1 из 577 функциональных. Тест берёт
   `new DateTimeImmutable('today')` в Europe/Moscow (+03:00), а окно
   `preset=day` считается в UTC, поэтому ежедневно с 21:00 до 24:00 UTC
   проводка попадает в следующие сутки и виджет получает 0 вместо 100.
   Воспроизводится на `master`. Выбор между UTC и таймзоной компании —
   доменное решение, поэтому чинить в этой задаче не стал.

## Не проверено на реальных данных

Сквозной прогон на тестовых ключах (`run-incremental --resource=ozon_orders_fbo`
→ заказы и позиции в БД, raw на S3, `listingId` у резолвнувшихся) ещё не
выполнялся: PROD-доступа у агента нет, а локальный прогон требует ключей.
