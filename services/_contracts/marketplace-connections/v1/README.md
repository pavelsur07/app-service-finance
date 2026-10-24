# marketplace-connections v1

Ответ внутреннего endpoint'а Symfony `GET /internal/marketplace-connections?provider=ozon`.
Сервисы `services/` периодически опрашивают его и работают автономно
(механика — `services/AGENTS.md` §3). Schema: [schema.json](schema.json).
История: [CHANGELOG.md](CHANGELOG.md).

Статус: **проект** — до реализации endpoint'а на стороне Symfony согласовать
с `Marketplace\Entity\MarketplaceConnection`, `ActiveOzonConnectionsQuery`,
`ActiveOzonPerformanceConnectionsQuery` и кодеком ключей
(`ConnectionApiKeyCodec`).

## Реализация в Symfony

`site/src/Marketplace/Controller/Internal/MarketplaceConnectionsController.php`
(firewall `internal_api`, этап 2 `docs/tasks/ozon-fetcher-ingestion/plan.md`).

- Поддерживается только `provider=ozon` (по умолчанию): `seller` требует
  `client_id`, у WB его нет. Другой провайдер — 422 `unsupported_provider`.
- **Кабинет = компания.** Seller- и performance-подключение одной компании —
  один элемент. `connection_ref` — id seller-подключения, без него — id
  performance. Новое подключение даёт новый ref; правка ключей того же
  подключения ref не меняет.
- Ключ, который не удалось расшифровать, или seller без `client_id` — тип
  ключа `null`. Кабинет без пригодных ключей в ответ не попадает.
- `auth_status` подключения не фильтруется: пауза кабинета по 401 — на
  стороне сервиса.
- `resource_types`: seller → `ozon.finance.accrual-by-day`,
  `ozon.finance.accrual-types`, `ozon.finance.realization`,
  `ozon.finance.mutual-settlement`, а `ozon.orders.fbo`, `ozon.orders.fbs` —
  только компаниям из `ozon_fetcher.orders_companies` (`['*']` — всем),
  `ozon.finance.accrual-postings` — из них компаниям `ozon_fetcher.accrual_postings_companies`;
  `ozon.products.list`, `ozon.products.info` — компаниям `ozon_fetcher.products_companies`;
  `ozon.stocks` — компаниям `ozon_fetcher.stocks_companies`;
  `ozon.prices` — компаниям `ozon_fetcher.snapshots_companies`, `ozon.stocks.warehouses` — им же,
  если выдан `ozon.stocks`; `ozon.analytics.queries`, `ozon.analytics.queries.details` — компаниям
  `ozon_fetcher.search_analytics_companies`, если выдан `ozon.stocks`; `ozon.returns` — компаниям
  `ozon_fetcher.returns_companies`;
  performance → `ozon.ads.campaigns`, `ozon.ads.expense`, `ozon.ads.daily` — компаниям
  `ozon_fetcher.ads_companies`; `ozon.ads.statistics`, `ozon.ads.statistics.tail` — из них
  компаниям `ozon_fetcher.ads_reports_companies`.
- `updated_at` — максимум из подключений кабинета.

## Правила

- Отдаёт только **активные** подключения. Credentials сгруппированы по типу
  ключа, не по ресурсу:
  - `seller` — Client-Id + Api-Key (api-seller.ozon.ru), финансовые ресурсы;
  - `performance` — client_id + client_secret → OAuth access_token
    (`api-performance.ozon.ru/api/client/token`), рекламная статистика.
  - Тип равен `null` — ключа нет, job'ы на его ресурсы не создаются.
- `resource_types` — выводимое поле (из наличия ключей), не источник истины:
  seller → `ozon.finance.*`, performance → `ozon.ads.*` (значения — раздел
  «Реализация в Symfony»).
- Сервис кэширует ответ **только в памяти**; значения ключей не попадают в
  БД сервиса и в логи (M1 §2). `updated_at` — для инвалидации кэша и
  force-refresh при 401 от Ozon.
- Авторизация: internal static token + IP-allowlist; endpoint наружу не
  публикуется.
- Интервал поллинга — env сервиса (по умолчанию 5 мин); при недоступности
  Symfony сервис работает на последнем кэше (fail-soft).
