# service-snapshot v1

Выдача Symfony последнего загруженного снимка ресурса кабинета. Schema: [schema.json](schema.json).
Решение Владельца 12.11.2026 — `docs/tasks/ozon-supplies-in-transit/plan.md`.

## Запрос

`GET /internal/snapshots/latest?connection_ref=<id подключения>&resource_type=<ресурс>`,
`Authorization: Bearer <SERVICE_API_TOKEN>` (направление «Symfony → сервис», как у `/internal/coverage`).

## Семантика

- **Только снимки «на сейчас»** — состояние кабинета в момент загрузки, которое можно перечитывать
  сколько угодно: ack нет, повторный запрос безопасен. Финансовые факты и заказы этим каналом не
  идут (для них — `source-fact-envelope`).
- **Последний загруженный**: наибольший `period_key` ресурса кабинета, у которого есть загруженная
  ревизия; текущая перезагрузка сегодняшнего снимка её не прячет. Свежесть решает потребитель по
  `period_key` / `loaded_at`.
- **Страницы как есть**: тела ответов маркетплейса в порядке загрузки, сверенные с sha256 хранения.
  Сервис ничего не разбирает; разбор и проверка формы — у потребителя.
- **Белый список**: ресурс вне списка сервиса — 422, даже если снимок есть.

| Сервис | Ресурс | Страницы |
|---|---|---|
| `ozon-fetcher` | `ozon.supplies` | по порядку: страницы `/v3/supply-order/list` (`order_ids`, `last_id`), порции `/v3/supply-order/get` (`orders`), состав по одной поставке `/v1/supply-order/bundle` (`items`, `has_next`, `last_id`). Формы — `services/ozon-fetcher/docs/ozon-api.md` |

## Ошибки

`{"error": {"code": "...", "message": "..."}}`

| HTTP | code | Когда |
|---|---|---|
| 401 | `internal_auth_required` | нет или неверный токен |
| 422 | `invalid_request` | нет `connection_ref` (или длиннее 64), ресурс вне белого списка |
| 404 | `snapshot_not_found` | у кабинета ещё нет загруженного снимка ресурса |
| 502 | `snapshot_invalid` | сохранённая страница повреждена или не JSON (ERROR в сервисе) |
| 503 | `snapshot_unavailable`, `ingestion_disabled` | сбой БД или хранилища; загрузка выключена |
