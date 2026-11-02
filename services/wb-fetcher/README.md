# wb-fetcher

Go-сервис: загрузка финансовых отчётов Wildberries по всем активным кабинетам
в raw S3, журнал загрузок в своей БД, покрытие для админки Symfony. Ядро —
[`services/shared/fetchkit`](../shared/fetchkit/README.md). План —
[`docs/tasks/wb-fetcher-finance/plan.md`](../../docs/tasks/wb-fetcher-finance/plan.md);
API WB — [`docs/wb-api.md`](docs/wb-api.md).

Статус: этап 3 — дневной и недельный детальный отчёт о реализации
(`wb.finance.sales-report.daily`, `wb.finance.sales-report.weekly`); недельный —
эталон сверки с дневными. Покрытие — админка, «Покрытие Сервис GO» → Wildberries. Прод —
[`docs/maintenance/wb-fetcher-prod.md`](../../docs/maintenance/wb-fetcher-prod.md) (за переключателем
`WB_FETCHER_ENABLED`). Загрузка WB в Symfony продолжает работать.

## Ресурсы

| Ресурс | Период | План |
|---|---|---|
| `wb.finance.sales-report.daily` | `YYYY-MM-DD` (МСК) | вчера — с 04:00 МСК; 14 дней перед ним — сразу; вчера и хвост перечитываются раз в сутки (WB дописывает строки задним числом). У кабинета с недельным ресурсом окно продлевается до понедельника самой старой перечитываемой недели |
| `wb.finance.sales-report.weekly` | `YYYY-Www` (ISO-неделя МСК) | прошлая неделя — с 04:00 МСК понедельника; две перед ней — сразу; три недели перечитываются раз в сутки. Пустой ответ до ср 00:00 МСК после недели — `not_ready` (повтор через час) |

Выдаётся кабинету, если Symfony включил ресурс (`wb_fetcher.finance_companies`).

## Запуск (dev)

```bash
make wb-fetcher-build     # сборка runtime-образа (контекст services/)
make wb-fetcher-up        # запуск сервиса (порт 8181)
make wb-fetcher-migrate   # накат миграций на dev-БД
curl -s http://localhost:8181/health
```

Dev-БД `wb_fetcher` и роль `svc_wb_fetcher` создаёт init
`services/docker/postgres/init/03-wb-fetcher.sql` — только на новом томе
`services-postgres`; на существующем выполнить его вручную.

## Схема БД

Миграции — миграции ядра (`fetchkit/store`, `00001_core.sql`), таблицы —
`fetch_task`, `fetch_attempt`, `raw_object`, `connection_state`,
`connection_limit`, `resource_stop` (описание — README ozon-fetcher «Схема
БД»). У кабинета не больше одной `running`-задачи.

**Raw в S3:** `wb/{connection_ref}/{resource_type}/{period_key}/{fetched_at}_{attempt}_p{NNN}_{sha8}.json.gz`
— ответ WB как есть (204 и `[]` — `[]`), gzip, `If-None-Match: *`.

## Переменные окружения

Секреты — только env (шаблон `.env.example`). Токены WB в env не задаются:
приходят по кабинету из Symfony и живут только в памяти.

| Переменная | Назначение | По умолчанию |
|---|---|---|
| `LISTEN_ADDR` | Адрес HTTP | `:8080` |
| `DB_DSN` | PostgreSQL сервисной БД | — |
| `S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`, `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY` | Raw: тот же бакет и пользователь, что у ozon-fetcher | — |
| `SYMFONY_INTERNAL_BASE_URL`, `INTERNAL_AUTH_TOKEN` | Снимок кабинетов `?provider=wildberries`; пусто — загрузка выключена | — |
| `SERVICE_API_TOKEN` | Токен «Symfony → сервис» для `/internal/*`, ≥ 32 символов; пусто — закрыт | — |
| `CONNECTIONS_POLL_INTERVAL` | Поллинг снимка кабинетов | `5m` |
| `MAX_PARALLEL_CONNECTIONS` | Кабинетов одновременно (1…64) | `2` |
| `CONNECTION_ALLOWLIST` | Только эти кабинеты: `connection_ref` или `company_id` через запятую | все |
| `WB_FINANCE_BASE_URL` | finance-api (на стенде — мок) | `https://finance-api.wildberries.ru` |
| `WB_DAILY_TAIL_DAYS` | Дней перед вчера в окне дневного отчёта (1…30) | `14` |
| `WB_FINANCE_MIN_INTERVAL` | Не чаще запроса отчёта на кабинет (≥ 60s) | `70s` |
| `PLAN_EVERY` | Проход планировщика | `1h` |
| `SENTRY_DSN`, `SENTRY_RELEASE`, `SENTRY_ENVIRONMENT` | GlitchTip: только ERROR | — |

## Внутренний API

`GET /health`, `GET /ready`, `GET /internal/fetch-status`,
`GET /internal/coverage` (контракт `service-coverage` v1, `service: "wb-fetcher"`)
— реализация ядра (`fetchkit/httpapi`); `/internal/*` — по `SERVICE_API_TOKEN`.

## Тесты

```bash
make wb-fetcher-vet wb-fetcher-lint wb-fetcher-test
```

Интеграционный тест исполнителя на PostgreSQL — `TEST_DB_DSN` (пустая
PostgreSQL 15); без переменной пропускается.
