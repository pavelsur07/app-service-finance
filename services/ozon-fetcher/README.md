# ozon-fetcher

Go-сервис: получение финансовых данных Ozon Seller API и публикация source
facts в Symfony по контракту [source-fact-envelope](../_contracts/source-fact-envelope/v1/).

Статус: **scaffold + store** — health/ready endpoints, конфигурация, доступ к
собственной БД (pgxpool + goose-миграции, таблицы `sync_job`/`checkpoint`/
`outbox_batch`); Ozon API client, сборка envelope и publisher — следующие
Stages (см. «Roadmap»).

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

| Переменная | Назначение | Обязательная |
|---|---|---|
| `LISTEN_ADDR` | Адрес HTTP, по умолчанию `:8080` | нет |
| `DB_DSN` | PostgreSQL сервисной БД (`sslmode=require` в prod) | нет |
| `OZON_CLIENT_ID`, `OZON_API_KEY` | Ozon Seller API | Stage «Ozon API client» |
| `S3_ENDPOINT` | `https://s3.twcstorage.ru` | Stage raw |
| `S3_REGION` | `ru-1` | Stage raw |
| `S3_BUCKET` | `vashfindir-services-raw` | Stage raw |
| `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY` | Пара доп. пользователя бакета | Stage raw |

## Тесты и гейты

```bash
make ozon-fetcher-test   # go test -race ./...
make ozon-fetcher-vet    # go vet ./...
make ozon-fetcher-lint   # golangci-lint v2 (.golangci.yml)
```

Правила и состав гейтов — `services/go.md` «Гейты». Тулчейн-контейнеры:
`services-ozon-fetcher-dev` (golang:1.27), `services-ozon-fetcher-lint`
(golangci-lint) — compose-профиль `services`.

## Roadmap (Stages, отдельные задачи)

1. Ozon API client: auth, rate limit, курсорная пагинация, retry на 429.
2. Сборка envelope v1 + `payload_hash` (SHA-256 канонической формы).
3. Publisher: запись в outbox → `POST /internal/source-facts`, backoff/jitter,
   idempotent replay; выгрузка raw в S3 (`storage_uri` + sha256).
4. Dual-run против Symfony-пути, паритет сумм до копейки на архивном raw.
5. Cutover по кабинету — только после данных M1/M2 и решения владельца.
