# source-fact-envelope v1

Канал «сервис загрузки → Symfony» для данных, которые сервис загружает
периодами (начисления дня, отчёт недели). Schema: [schema.json](schema.json),
примеры — [examples/](examples/), история решений — [CHANGELOG.md](CHANGELOG.md).
Статус: **действует** с 2026-11-13, ресурсы `ozon.finance.accrual-by-day`, `ozon.ads.statistics` (с 2026-11-14), `wb.finance.sales-report.daily` (с 2026-11-14), `ozon.analytics.funnel` и `wb.analytics.funnel` (с 2026-11-18), `wb.analytics.search` (с 2026-11-19).

Снимки «на сейчас» (остатки, поставки) идут другим контрактом —
`service-snapshot` v1 (pull без подтверждения).

## Что такое факт

**Факт — одна запись источника целиком**, как её вернул API провайдера:
`payload` — исходный JSON записи без изменений. Сервис не раскладывает запись
на суммы, не назначает категорию, признание, период и SKU
(`services/AGENTS.md` §1) — разбор делает Symfony в переводчике канала.

Сервис добавляет к записи только:

- `source_key` — стабильный ключ записи внутри ресурса (семантика — ниже);
- `payload_hash` — идентификатор версии записи: SHA-256 байтов записи в ответе
  источника. Symfony его не пересчитывает (JSON в пути перекодируется) и
  использует только для сравнения версий.

Запись идентична прежней, если совпадают `source_key` и `payload_hash`.
Одинаковые записи внутри одной пачки — одна запись.

## Пачка

**Пачка — одна загруженная ревизия одного периода одного кабинета**:
`(connection_ref, resource_type, period_key, revision)`. Источник изменил
период (дописал, исправил, убрал запись) — сервис при следующей загрузке
получает другой `content_hash`, ревизия растёт, появляется новая пачка с
**полным** содержимым периода. Перезагрузка без изменений пачку не создаёт.

Правила приёма на стороне Symfony:

- ревизия применяется целиком: записи, которых нет в новой ревизии периода,
  перестают быть действующими; прежние версии не затираются;
- ревизия не новее уже применённой — подтверждается без применения;
- `batch_id` стабилен для ревизии — повторное получение безопасно.

## Доставка: pull и подтверждение

Все вызовы — `Authorization: Bearer <SERVICE_API_TOKEN>` (токен направления
«Symfony → сервис», `services/AGENTS.md` §3), только внутри docker-сети.

| Вызов | Что делает |
|---|---|
| `GET /internal/source-facts?resource_type=…&after=…&limit=…` | Неподтверждённые пачки ресурса по порядку постановки, только метаданные. `limit` 1–500, по умолчанию 100. `next_cursor` — для следующей страницы, `null` — пачек больше нет. Курсор непрозрачен. |
| `GET /internal/source-facts/page?batch_id=…&page=N` | Записи страницы `N` (с 1) пачки. Доступна и после подтверждения. |
| `POST /internal/source-facts/ack` `{"batch_ids": [...]}` | Пачки больше не выдаются списком. До 1000 идентификаторов; повтор и неизвестные идентификаторы — не ошибка. |

Symfony подтверждает пачку **после** commit применения. Пачка, которую нельзя
принять (неизвестная `schema_version`, неизвестный кабинет, ответ вне схемы,
расхождение `record_count`), **не подтверждается**: она остаётся в
списке, уходит в карантин Symfony и поднимает гейт — не пропускается молча.

## Ошибки

Формат — `{"error": {"code", "message"}}`.

| Статус | `code` | Когда | Повтор |
|---|---|---|---|
| 401 | `internal_auth_required` | нет или неверный токен | нет — дефект настройки |
| 422 | `invalid_request` | ресурс не публикуется, чужой курсор, `limit`/`page`/`batch_ids` вне границ | нет — дефект клиента |
| 404 | `batch_not_found` | нет пачки или страницы | нет |
| 502 | `fact_page_invalid` | страница ревизии потеряна, повреждена или не режется на записи | нет — нарушен инвариант хранения |
| 503 | `facts_unavailable`, `ingestion_disabled` | БД или S3 сервиса недоступны; загрузка выключена | да |

## `source_key`

Семантика за провайдером, версионируется вместе с контрактом.

| resource_type | `source_key` | `period_key` |
|---|---|---|
| `ozon.finance.accrual-by-day` | `ozon:accrual-by-day:{accrual_id}`; у записи без пригодного `accrual_id` (`^[A-Za-z0-9_.-]{1,128}$`) — `ozon:accrual-by-day:fallback-{sha256(payload)[:16]}` | `YYYY-MM-DD`, день начисления |
| `ozon.ads.statistics` | `ozon:ads-statistics:{campaign_id}:{sku}`; повтор пары в отчёте дня — `…:{n}` по порядку строк; небезопасная часть (`^[A-Za-z0-9_.-]{1,64}$`) — `x{sha256(payload)[:16]}`. `payload` — строка CSV отчёта Performance: колонки по заголовку, значения строками как в CSV (`"1152,19"`), плюс `campaign_id` и `sku` | `YYYY-MM-DD`, день статистики |
| `wb.finance.sales-report.daily` | `wb:sales-report.daily:{rrdId}`; строка без числового `rrdId` — `wb:sales-report.daily:fallback-{sha256(payload)[:16]}`. `payload` — строка ответа finance-api `sales-reports/detailed` как есть (camelCase-поля); пустая последняя страница `[]` — 0 записей | `YYYY-MM-DD`, день отчёта (МСК) |
| `wb.ads.daily` | `wb:ads.daily:{advertId}`. `payload` — кампания дня: `{"advertId": "…", "expenses": [строки /adv/v1/upd кампании как есть], "statistics": строка /adv/v3/fullstats кампании как есть или null}`; день без списаний — 0 записей | `YYYY-MM-DD`, день списания (МСК) |
| `ozon.analytics.funnel` | `ozon:analytics.funnel:{sku}`. `payload` — строка SKU ответа `/v1/analytics/data` как есть: `{"dimensions": [{"id", "name"}], "metrics": [14 чисел]}`, метрики по порядку запроса сервиса (`ozon.FunnelMetrics`, порядок — часть контракта). День, который Ozon не отдал по тарифу кабинета, — одна запись `ozon:analytics.funnel:unavailable` с `payload` `{"subscription_required": true}` | `YYYY-MM-DD`, день МСК |
| `wb.analytics.funnel` | `wb:analytics.funnel:{nmId}`. `payload` — `{"product": {"nmId"}, "statistic": {"selected": …как в ответе `sales-funnel/products`}}`; остальные поля карточки (остатки, рейтинг, прошлый период) не передаются | `YYYY-MM-DD`, день МСК |
| `wb.analytics.search` | `wb:analytics.search:{nmId}`. `payload` — `{"nmId", "openCard", "addToCart", "orders", "visibility", "avgPosition"}`: значения дня (`current` ответа `search-report/table/details`), показателя нет — `null`. День без подписки «Джем» — одна запись `wb:analytics.search:unavailable`, `payload` — `{"unavailable": true, "status": 402 \| 403}` | `YYYY-MM-DD`, день МСК |

Ключи остальных ресурсов фиксируются здесь до реализации их публикации.

## Деньги

Сервис суммы не читает и не пересчитывает: они остаются внутри `payload` в
том виде, в котором их отдал источник. В БД сервиса данных источника нет —
пачка хранит только указатель на страницы ревизии в S3 (`services/AGENTS.md` §9).

## Неизвестное на приёме

Неизвестная `schema_version` → карантин в Symfony, не skip. Неизвестный тип
операции или категория внутри `payload` — карантин переводчика канала.
