# services/go.md — правила Go-сервисов монорепо

Распространяется на `services/*/*.go`. Иерархия: корневой `AGENTS.md` →
`services/AGENTS.md` → этот файл → `services/<name>/AGENTS.md`.

## Тулчейн

- Актуальная поддерживаемая версия Go (сейчас **1.27**; следить за
  [endoflife.date/go](https://endoflife.date/go), поднимать после EOL прежней).
- Пин в `go.mod` (`go 1.27`); дев-тулы — директива `tool` (с Go 1.24), не
  глобальные установки на хост.
- Форматирование и линт — только через Makefile/CI-таргеты (см. «Гейты»).

## Раскладка

```text
services/<name>/
├── cmd/<name>/main.go      # только wiring: config → клиенты → worker/server; graceful shutdown
├── internal/
│   ├── config/             # env + валидация, fail fast
│   ├── <client>/           # клиент внешнего API: auth, rate limit, cursor, retry
│   ├── envelope/           # сборка контракта: canonical JSON, payload_hash, Money
│   ├── publisher/          # отправка фактов: errgroup, backoff+jitter, idempotent replay
│   └── observability/      # slog + OTel, trace/job propagation
├── api/                    # артефакты контрактов (OpenAPI, JSON Schema) — не код
├── test/                   # интеграционные/e2e вне internal
├── .golangci.yml
├── go.mod / go.sum
└── docker/Dockerfile
```

Правила роста: начинать плоско, пакеты добавлять по необходимости. Новый
пакет — когда появились **своя граница и свои инварианты**. `pkg/` не
создавать (сервисы — не библиотеки). Golden-файлы — рядом с тестами в
`testdata/`.

## Пакеты и направление зависимостей

- Однонаправленно: `publisher → envelope`, `main → всё`. Пакет не импортирует
  соседей через один; `envelope` не знает ни о провайдере, ни о транспорте —
  его можно переиспользовать в другом сервисе без изменений.
- Интерфейсы — у потребителя, на границах подмены (HTTP-клиент, часы,
  генератор ключей); моки — ручные фейки в тестах, при необходимости `moq`.
- DI — вручную в `cmd/<name>/main.go`. `wire`/`fx` — только когда wiring
  повторяется в 3+ сервисах.

## Зависимости (go.mod)

- Культура **stdlib-first**: внешний модуль — когда решает задачу осмысленно
  лучше 30–50 строк своего кода.
- Один модуль — одна задача; `go.sum` коммитим; обновления — Dependabot.
- Проверенный стек (не требует отдельного обоснования): `chi` (роутинг, если
  не хватает stdlib mux), `cenkalti/backoff/v4` (retry),
  `golang.org/x/sync` (errgroup), `shopspring/decimal` (деньги),
  `testify`, `go.uber.org/goleak`, `testcontainers-go` (интеграционные),
  `pgx` + `sqlc` (если у сервиса появится своя БД), OTel SDK (traces/metrics).
- Всё остальное — одна строка обоснования в описании PR. PHP-гейт §3.3 на
  зависимости сервисов `services/` не распространяется.

## Паттерны

- `context` — первым аргументом на всю глубину; дедлайн на каждый внешний
  вызов; отмена по сигналу — в `main`.
- Fan-out — `errgroup.WithContext`; без утечек горутин (`goleak` в тестах).
- Ошибки: оборачивать `%w` с префиксом пакета; sentinel + `errors.Is/As`;
  `errors.Join` для агрегации; кастомный тип — когда нужны поля. Без
  `log.Fatal`/`panic` вне `main`.
- Retry: экспоненциальный backoff + jitter; курсор/`batch_id` сохранять до
  ACK; replay идемпотентен по `Idempotency-Key` (см. `services/AGENTS.md` §3).
- Часы и генераторы идентификаторов — параметры (не `time.Now()` в глубине):
  replay и тесты детерминированны.
- Graceful shutdown: `signal.NotifyContext` + `http.Server.Shutdown`;
  live/ready health.
- HTTP: stdlib `http.ServeMux` с паттернами (Go 1.22+); middleware — ручные
  замыкания.

## Хранение: БД и S3

Дисциплина и границы — `services/AGENTS.md` §9 (отдельная БД на общем инстансе,
outbox, immutable raw, dev-контейнер `services-postgres`).

- БД: `pgx/v5` (pgxpool, лимиты 2–5 коннектов, `statement_timeout`,
  `lock_timeout`), миграции — `pressly/goose` с `embed.FS` (миграции внутри
  бинарника, команда `-migrate`); `sqlc` — при росте числа запросов.
- S3: `aws-sdk-go-v2` (S3-совместимые провайдеры; endpoint/region из env);
  объекты immutable, коррекции — новым ключом; sha256 и size передаются
  в envelope (`storage_uri`, `checksum`, `size_bytes`).

## Автономная работа (pull-модель)

Механика взаимодействия — `services/AGENTS.md` §3.

- Расписание: ticker-воркеры в процессе; watermark-догон по `checkpoint`
  (сначала backlog, потом свежие периоды).
- Конфиг-кэш: in-memory + поллинг по env-интервалу; 401 от провайдера →
  force-refresh записи кабинета; нет кэша и нет Symfony — сервис ждёт, не
  падает.
- Очередь триггеров: одна таблица, `ORDER BY priority DESC, created_at ASC`;
  приоритет не отменяет rate limit внешнего API.
- Facts API отдаёт только `status=new` батчи; ack — единственный переход в
  `acked`.

## Деньги

- `float` запрещён везде. На границе контракта — точные строки (pattern
  `source-fact-envelope`); внутри расчётов — `shopspring/decimal`.
- Округления — только явные, с тестами паритета до копейки
  (`services/AGENTS.md` §7).

## Конфиг и секреты

- env-only, валидация при старте (fail fast); помощник — `caarlos0/env`.
- Имена env — в README сервиса; значения и секреты — никогда в git и логах.

## Наблюдаемость

- `slog` JSON; обязательные поля — `services/AGENTS.md` §6.
- OTel: traces (propagator → `trace_id` envelope), metrics → Prometheus
  `/metrics`. Без ПДн/сумм/токенов в логах (M1 §2).

## Тесты

- Table-driven + `testify`; фикстуры обезличенные (`testdata/`,
  `httptest.Server` для внешних API).
- Нативный fuzzing для парсеров и ключей; `goleak`; `-race` обязателен;
  coverage-гейт в CI (стартово 70%, растёт по ratchet-принципу).
- Интеграционные — `testcontainers-go` в `test/`.

## Гейты (make/CI)

- `make <name>-lint` — golangci-lint v2, конфиг `.golangci.yml`
  (`golangci-lint fmt` — форматирование импортов).
- `make <name>-vet` — `go vet ./...`.
- `make <name>-test` — `go test -race ./...`.
- CI дополнительно: `govulncheck` (пин версии), `go build -trimpath`,
  проверка чистоты форматирования.
- Порядок: fmt → vet → lint → test; красный гейт останавливает пайплайн.

## Docker

- multi-stage: build в `golang:1.27-alpine` → рантайм `alpine` или
  `gcr.io/distroless/static`, non-root; `-trimpath -ldflags="-s -w"`.
- `.dockerignore` в контексте сервиса; healthcheck через `/health`.

## Монорепо

- У каждого сервиса свой `go.mod` — независимые релизы и версии.
- Общий Go-код — `services/shared/` (свой `go.mod`), подключение через
  `replace`-директиву; для локальной разработки — `go.work` на уровне
  `services/`.
- Каталоги с префиксом `_`/`.` игнорируются go tool: `_contracts/` — только
  не-Go артефакты (JSON Schema, OpenAPI); Go-код туда класть нельзя.
- Новый сервис — по чек-листу `services/README.md` «Как добавить новый сервис».
