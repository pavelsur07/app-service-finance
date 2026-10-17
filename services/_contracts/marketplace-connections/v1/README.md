# marketplace-connections v1

Ответ внутреннего endpoint'а Symfony `GET /internal/marketplace-connections?provider=ozon`.
Сервисы `services/` периодически опрашивают его и работают автономно
(механика — `services/AGENTS.md` §3). Schema: [schema.json](schema.json).
История: [CHANGELOG.md](CHANGELOG.md).

Статус: **проект** — до реализации endpoint'а на стороне Symfony согласовать
с `Marketplace\Entity\MarketplaceConnection`, `ActiveOzonConnectionsQuery`,
`ActiveOzonPerformanceConnectionsQuery` и кодеком ключей
(`ConnectionApiKeyCodec`).

## Правила

- Отдаёт только **активные** подключения. Credentials сгруппированы по типу
  ключа, не по ресурсу:
  - `seller` — Client-Id + Api-Key (api-seller.ozon.ru), финансовые ресурсы;
  - `performance` — client_id + client_secret → OAuth access_token
    (`api-performance.ozon.ru/api/client/token`), рекламная статистика.
  - Тип равен `null` — ключа нет, job'ы на его ресурсы не создаются.
- `resource_types` — выводимое поле (из наличия ключей), не источник истины:
  seller → `ozon.finance.*`, performance → `ozon.ads.*`. Значения seller
  первой волны: `ozon.finance.accrual-by-day`, `ozon.finance.accrual-types`,
  `ozon.finance.realization`, `ozon.finance.mutual-settlement`
  (`services/ozon-fetcher/docs/ozon-api.md`).
- Сервис кэширует ответ **только в памяти**; значения ключей не попадают в
  БД сервиса и в логи (M1 §2). `updated_at` — для инвалидации кэша и
  force-refresh при 401 от Ozon.
- Авторизация: internal static token + IP-allowlist; endpoint наружу не
  публикуется.
- Интервал поллинга — env сервиса (по умолчанию 5 мин); при недоступности
  Symfony сервис работает на последнем кэше (fail-soft).
