# ozon-fetcher

Go-сервис: загрузка финансовых данных Ozon Seller API по всем активным
кабинетам, raw в S3, журнал загрузок в своей БД; выдача фактов Symfony по
контракту [source-fact-envelope](../_contracts/source-fact-envelope/v1/)
(pull-модель, после первой волны).

Статус: **scaffold + store** — health/ready endpoints, конфигурация, доступ к
собственной БД (pgxpool + goose-миграции, таблицы `sync_job`/`checkpoint`/
`outbox_batch`). План работ по этапам —
[`docs/tasks/ozon-fetcher-ingestion/plan.md`](../../docs/tasks/ozon-fetcher-ingestion/plan.md);
ресурсы и лимиты Ozon — [`docs/ozon-api.md`](docs/ozon-api.md).

Границы: сервис не пишет в БД Symfony, не решает об идемпотентности по
состоянию БД, не назначает категории ОПиУ, периоды и проводки
(`services/AGENTS.md` §1). Хранение — по `services/AGENTS.md` §9.

## Запуск (dev)

```bash
make ozon-fetcher-build     # сборка runtime-образа
make ozon-fetcher-up        # запуск сервиса (порт 8180)
make ozon-fetcher-migrate   # накат миграций на dev-БД
curl -s http://localhost:8180/health   # {"status":"ok"}
curl -s http://localhost:8180/ready    # {"status":"ready","db":"up"}
```

Dev-БД: контейнер `services-postgres` (compose-профиль `services`; init создаёт
БД `ozon_fetcher` и роль `svc_ozon_fetcher`, пароль dev-only). DSN прописан в
`services-ozon-fetcher-dev` как `DB_DSN`. Без `DB_DSN` сервис работает в
режиме без БД (`/ready` отвечает `db: disabled`).

## Переменные окружения

Секреты — только env, значения не коммитятся (шаблон — `.env.example`).
Ключи Ozon в env не задаются: они приходят по кабинету из Symfony
(`marketplace-connections` v1) и живут только в памяти.

| Переменная | Назначение | Обязательная |
|---|---|---|
| `LISTEN_ADDR` | Адрес HTTP, по умолчанию `:8080` | нет |
| `DB_DSN` | PostgreSQL сервисной БД (`sslmode=require` в prod) | нет |
| `S3_ENDPOINT` | `https://s3.twcstorage.ru` | этап 8 |
| `S3_REGION` | `ru-1` | этап 8 |
| `S3_BUCKET` | `vashfindir-services-raw` | этап 8 |
| `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY` | Пара доп. пользователя бакета | этап 8 |
| `SYMFONY_INTERNAL_BASE_URL` | Базовый URL внутреннего API Symfony (`/internal/marketplace-connections`) | этап 6 |
| `INTERNAL_AUTH_TOKEN` | Внутренний static token для `/internal/*` (с обеих сторон) | этап 6 |
| `CONNECTIONS_POLL_INTERVAL` | Интервал поллинга конфигурации, по умолчанию `5m` | этап 6 |
| `MAX_PARALLEL_CONNECTIONS` | Сколько кабинетов загружается одновременно | этап 10 |

## Внутренний API сервиса (контракты, к кодовым Stage'ам)

Сервис автономен (расписание + журнал загрузок); Symfony забирает готовое. Семантика
— `services/AGENTS.md` §3, контракты — `services/_contracts/`.

| Метод | Назначение |
|---|---|
| `GET /internal/source-facts` | Выдача батчей `status=new` (фильтры `connection_ref`/`resource_type`/`business_period`, курсор) |
| `POST /internal/source-facts/ack` | `{batch_ids}` → батчи в `acked`, payload purge (retention) |
| `POST /internal/fetch-requests` | Загрузка по запросу (отложено): `{request_id, connection_ref, resource_type, period_from, period_to}` → 202 после записи; повтор сливается в активный запрос |
| `GET /internal/fetch-status` | Состояние загрузки по кабинетам и ресурсам: пропуски, последняя успешная загрузка, текущий лимит, 429, пауза (этап 12) |
| `GET /internal/fetch-requests/{id}` | `{status: queued\|fetching\|ready\|failed, progress: {periods_done, periods_total}, error_class}` |

Мапа статусов для UI Symfony: queued → «В очереди»; fetching → «Загружаем:
x/y периодов»; ready → «Готово»; failed → «Ошибка загрузки, повторим».

## Тесты и гейты

```bash
make ozon-fetcher-test   # go test -race ./...
make ozon-fetcher-vet    # go vet ./...
make ozon-fetcher-lint   # golangci-lint v2 (.golangci.yml)
```

Правила и состав гейтов — `services/go.md` «Гейты». Тулчейн-контейнеры:
`services-ozon-fetcher-dev` (golang:1.27), `services-ozon-fetcher-lint`
(golangci-lint) — compose-профиль `services`.

## План

Этапы, критерии готовности и решения Владельца —
[`docs/tasks/ozon-fetcher-ingestion/plan.md`](../../docs/tasks/ozon-fetcher-ingestion/plan.md).
