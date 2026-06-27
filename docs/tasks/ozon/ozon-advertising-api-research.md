# Ozon Advertising / Performance API Research

Дата исследования: 2026-06-27

## Цель

Изучить актуальные возможности Ozon API для получения данных о рекламе, особенно новые варианты доступа к Performance API без отдельного performance token.

Код не менялся. Это исследовательская заметка для дальнейшего проектирования интеграции.

## Основной вывод

Обычный Seller API доступ через `api-seller.ozon.ru` с заголовками `Client-Id` и `Api-Key` в документации не показан как прямой способ вызова Performance API.

Performance API по-прежнему находится на отдельном host:

```text
https://api-performance.ozon.ru
```

В примерах методов Performance API используется:

```text
Authorization: Bearer <token>
```

При этом Ozon опубликовал свежее обновление: с 2026-04-06 для Performance API продавцов РФ добавлен OAuth-доступ, аналогичный контуру прав Seller API. Практический смысл: вместо старого отдельного `client_id/client_secret` Performance API можно ожидать доступ через общий OAuth/Bearer-контур прав, но это не равно прямой поддержке обычных Seller API headers `Client-Id` / `Api-Key`.

Перед реализацией нужно проверить на реальном кабинете, какой именно Bearer выдаётся для Performance API:

- legacy token через `POST /api/client/token`;
- новый OAuth token через общий Ozon OAuth/access flow;
- обычный Seller API key, если Ozon фактически добавил bridge, хотя документация этого явно не показывает.

## Авторизация

### Legacy Performance API token

Документация Performance API содержит получение Bearer token:

```http
POST /api/client/token
Host: api-performance.ozon.ru
Content-Type: application/json
Accept: application/json
```

Тело:

```json
{
  "client_id": "XYZ@advertising.performance.ozon.ru",
  "client_secret": "...",
  "grant_type": "client_credentials"
}
```

Ответ содержит:

```json
{
  "access_token": "...",
  "expires_in": 1800,
  "token_type": "Bearer"
}
```

### Новый OAuth-доступ

Ozon for dev сообщает об оптимизации контроля доступов Performance API через OAuth для продавцов РФ с 2026-04-06.

Вывод для архитектуры: auth-слой рекламной интеграции нужно проектировать как отдельный provider, который умеет работать минимум в двух режимах:

- legacy Performance credentials;
- новый OAuth/Bearer token.

Не стоит смешивать это с текущей моделью Seller API `Client-Id` + `Api-Key`, пока не будет подтверждения реальным запросом.

## Методы получения рекламных данных

### Кампании и рекламные объекты

#### `GET /api/client/campaign`

Получение списка рекламных кампаний.

Основные фильтры:

- `campaignIds[]`
- `advObjectType`
- `state`
- `page`
- `pageSize`

Важные типы `advObjectType`:

- `SKU` — товарная реклама / pay-per-click;
- `BANNER` — баннерные кампании;
- `SEARCH_PROMO` — продвижение с оплатой за заказ.

Важные состояния:

- `CAMPAIGN_STATE_RUNNING`
- `CAMPAIGN_STATE_PLANNED`
- `CAMPAIGN_STATE_STOPPED`
- `CAMPAIGN_STATE_INACTIVE`
- `CAMPAIGN_STATE_ARCHIVED`
- `CAMPAIGN_STATE_MODERATION_DRAFT`
- `CAMPAIGN_STATE_MODERATION_IN_PROGRESS`
- `CAMPAIGN_STATE_MODERATION_FAILED`
- `CAMPAIGN_STATE_FINISHED`

#### `GET /api/client/campaign/{campaignId}/objects`

Получение продвигаемых объектов кампании.

Подходит для:

- pay-per-click кампаний;
- баннеров;
- видеобаннеров.

Для pay-per-order/search promo документация рекомендует использовать отдельный метод товаров search promo.

#### `POST /api/client/campaign/search_promo/v2/products`

Получение товаров в продвижении с оплатой за заказ.

Метод read-like, несмотря на `POST`: используется для получения списка товаров search promo с пагинацией.

#### `GET /api/client/products_with_bonuses`

Получение SKU, по которым начислены бонусы, которые могут расходоваться на клики перед расходом основного бюджета кампании.

## Методы статистики

### Daily statistics

#### `GET /api/client/statistics/daily`

Ежедневная статистика кампаний. Если даты не переданы, документация указывает поведение по последним 7 дням.

#### `GET /api/client/statistics/daily/json`

То же, но в JSON-формате.

### Product campaign statistics

#### `GET /api/client/statistics/campaign/product`

Статистика товарных кампаний. Поддерживает фильтр по датам и `campaignIds`.

Параметры дат:

- `from` / `to` в RFC3339;
- `dateFrom` / `dateTo`, приоритетнее, если переданы обе пары.

#### `GET /api/client/statistics/campaign/product/json`

JSON-вариант отчёта по товарным кампаниям.

### Media campaign statistics

#### `GET /api/client/statistics/campaign/media`

Статистика media/banner кампаний. Поддерживает фильтр по датам и `campaignIds`.

#### `GET /api/client/statistics/campaign/media/json`

JSON-вариант отчёта по media/banner кампаниям.

### Асинхронные отчёты

#### `POST /api/client/statistics`

Генерация отчёта статистики. Возвращает UUID отчёта.

Использовать, когда нужен файл/архив или большой период/набор кампаний.

#### `GET /api/client/statistics/{uuid}`

Получение статуса и метаданных отчёта по UUID.

#### `GET /api/client/statistics/report?UUID=...`

Скачивание готового отчёта.

#### `GET /api/client/statistics/list`

Список отчётов, созданных через интерфейс рекламного кабинета.

#### `GET /api/client/statistics/externallist`

Список отчётов, созданных через API/service accounts.

### Отчёты по заказам и товарам

#### `POST /api/client/statistic/orders/generate`

Генерация отчёта по заказам.

#### `POST /api/client/statistic/products/generate`

Генерация отчёта по товарам.

### Video statistics

#### `POST /api/client/statistics/video`

Статистика видеобаннеров.

## External traffic / vendor analytics

Эти методы относятся к аналитике внешнего трафика. Это не прямой аналог рекламных расходов Performance, но может быть полезно для attribution/marketing analytics.

#### `POST /api/client/vendors/statistics`

Генерация отчёта по external traffic analytics.

Типы отчётов из документации:

- `TRAFFIC_SOURCES`
- `ORDERS`

Ограничение периода: не более 3 месяцев.

#### `GET /api/client/vendors/statistics/list`

Список запрошенных отчётов external traffic analytics.

#### `GET /api/client/vendors/statistics/{uuid}`

Получение информации по конкретному отчёту external traffic analytics.

В документации также встречается вариант пути `/vendors/statistics/{UUID}`. Перед реализацией нужно уточнить точный актуальный path в консоли Ozon или через тестовый запрос.

## Ставки и справочные рекламные данные

Эти методы полезны не для факта расходов, а для понимания рекламных настроек и рекомендаций.

#### `GET /api/client/campaign/{campaignId}/products/bids/competitive`

Получение конкурентных ставок по товарам кампании.

#### `POST /api/client/search_promo/get_cpo_min_bids`

Получение минимальных/fixed CPO ставок для товаров.

#### `POST /api/client/search_promo/bids/recommendation`

Рекомендации ставок для search promo.

## Методы управления рекламой, которые не нужны для read-only интеграции

Не включать в первую read-only интеграцию:

- создание кампаний;
- активация/деактивация кампаний;
- изменение бюджета;
- добавление/удаление товаров;
- изменение ставок.

Примеры таких методов:

- `POST /api/client/campaign/cpc/v2/product`
- `PATCH /api/client/campaign/{campaignId}`
- `POST /api/client/campaign/{campaignId}/activate`
- `POST /api/client/campaign/{campaignId}/deactivate`
- `POST /api/client/campaign/{campaignId}/products/delete`
- `POST /api/client/campaign/search_promo/v2/bids/set`
- `POST /api/client/campaign/search_promo/v2/bids/delete`
- `POST /api/client/search_promo/product/enable`
- `POST /api/client/search_promo/product/disable`
- `POST /api/client/search_promo/product/update`

## ORD API

ORD API — отдельный рекламно-юридический контур, не основной источник расходов Performance.

Может понадобиться, если нужно:

- рекламные креативы;
- площадки;
- договоры;
- акты;
- маркировка интернет-рекламы;
- данные для отчётности ОРД.

Релевантный метод:

#### `POST /api/external/v2/statistic`

Получение/фильтрация рекламной статистики ORD.

## Рекомендация по будущей реализации

Создать отдельный Ozon Advertising integration, не смешивая его с обычным Ozon Seller API ingestion.

Минимальный read-only MVP:

1. Auth provider:
   - legacy Performance token;
   - новый OAuth/Bearer token.
2. Sync campaigns:
   - `GET /api/client/campaign`.
3. Sync campaign objects:
   - `GET /api/client/campaign/{campaignId}/objects`;
   - `POST /api/client/campaign/search_promo/v2/products`.
4. Sync daily statistics:
   - `GET /api/client/statistics/daily/json`;
   - `GET /api/client/statistics/campaign/product/json`;
   - `GET /api/client/statistics/campaign/media/json`.
5. Async report fallback:
   - `POST /api/client/statistics`;
   - `GET /api/client/statistics/{uuid}`;
   - `GET /api/client/statistics/report?UUID=...`.

Первую версию лучше делать строго read-only.

## Открытые вопросы перед кодом

1. Проверить на реальном кабинете, какой token реально принимает `api-performance.ozon.ru` после OAuth-обновления.
2. Проверить, работает ли Performance API с текущим стандартным Seller API ключом напрямую. Документация этого явно не подтверждает.
3. Уточнить точный path для external traffic report by UUID:
   - `/api/client/vendors/statistics/{uuid}`;
   - или `/vendors/statistics/{UUID}`.
4. Уточнить лимиты, ретраи и максимальные периоды для каждого статистического метода.
5. Проверить, какие отчёты возвращают JSON сразу, а какие требуют async UUID flow.

## Источники

- https://docs.ozon.ru/api/performance
- https://dev.ozon.ru/news/691-Performance-API-Optimizatsiia-kontrolia-dostupov-cherez-OAuth/
- https://docs.ozon.ru/api/seller/
- https://docs.ozon.ru/api/ord
