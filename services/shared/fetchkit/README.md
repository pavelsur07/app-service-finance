# fetchkit — ядро загрузчиков маркетплейсов

Go-модуль `services/shared/fetchkit`: общий код сервисов загрузки raw
(`ozon-fetcher`, `wb-fetcher`). Вынесен из ozon-fetcher 02.11.2026
(`docs/tasks/wb-fetcher-finance/stages/stage-0.md`) без изменения поведения.

Ядро не знает о провайдерах: имена ресурсов, классы ошибок конкретного API,
лимиты и план загрузки — в сервисе. Гейт — тест `internal/boundary`
(нет литералов и импортов провайдеров вне `_test.go`).

| Пакет | Что | Что задаёт сервис |
|---|---|---|
| `apierr` | Классы ошибок внешнего API (`rate_limit`, `transient`, `auth`, `obsolete_endpoint`, `not_ready`, …) | `Source` в тексте ошибки, разбор ответов своего API |
| `store` | Очередь `fetch_task`, журнал попыток, raw_object, пауза кабинета, `resource_stop`, покрытие; `CoreMigrations()` | `Options.SeparateSlotPrefix` — ресурсы со своим слотом кабинета; свою историю миграций (схема = ядро) |
| `rawstore` | Raw в S3: gzip, `If-None-Match: *`, sha256 | `Provider` — префикс ключа (`ozon/`, `wb/`): сервисы делят бакет и пользователя только префиксом |
| `ratelimit` | Лимитер пар (кабинет, endpoint), адаптация к 429 | `Config` по endpoint |
| `connections` | Снимок кабинетов `marketplace-connections` v1, кэш, allowlist | тип кабинета `C` и `Decoder[C]` ключей |
| `fetch` | Исполнитель задачи: lease, страницы в S3, `content_hash`, таблица исходов | `Adapter[C]`: `Fetch`, свои исходы `Classify`, `ResumesConnection`, `LogLevel` |
| `dispatcher` | До N задач параллельно, одна на кабинет | — |
| `schedule` | Цикл планировщика: `Ensure` задач, перечитывания | `Builder[C]` — план по времени и кабинетам |
| `httpapi` | `/health`, `/ready`, `/internal/fetch-status`, `/internal/coverage` (контракт `service-coverage` v1) | `Service`, `Expected()` — сколько кабинетов ждёт каждый ресурс |
| `observability` | slog JSON + GlitchTip для ERROR | имя сервиса |

## Подключение

```text
require github.com/pavelsur07/app-service-finance/services/shared/fetchkit v0.0.0
replace github.com/pavelsur07/app-service-finance/services/shared/fetchkit => ../shared/fetchkit
```

Образ сервиса собирается с контекстом `services/` (образец —
`ozon-fetcher/docker/Dockerfile` и `Dockerfile.dockerignore`).

## Гейты

`make fetchkit-vet fetchkit-lint fetchkit-test`. Интеграционные тесты `store`
— `TEST_DB_DSN` (пустая PostgreSQL 15), `rawstore` — `TEST_S3_ENDPOINT`,
`TEST_S3_BUCKET`; без переменных пропускаются.

Изменение ядра меняет поведение всех сервисов: прогонять гейты ядра и каждого
сервиса на нём; эталонные тесты ozon-fetcher (`planner/golden_test.go`,
`httpapi/golden_test.go`) должны оставаться зелёными без `-update`.
