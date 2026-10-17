# ozon-fetcher — правила сервиса

Первый сервис монорепо `services/`: загрузка финансовых данных Ozon Seller API
по всем активным кабинетам, хранение raw в S3 и журнала загрузок в своей БД;
выдача фактов Symfony — pull-модель (`services/AGENTS.md` §3). План работ —
`docs/tasks/ozon-fetcher-ingestion/plan.md`.

Общие правила — `services/AGENTS.md` (обязателен, переопределяет этот файл при
конфликте только ближайший: этот AGENTS.md специфичнее для этого каталога).

## Стек

- Go 1.27 (пин в `go.mod`); общие правила — `services/go.md` (раскладка,
  паттерны, зависимости, гейты). stdlib-first — культура, не запрет;
  проверенный стек — в `go.md` «Зависимости».
- `cmd/ozon-fetcher/` — только wiring (config, server); вся логика в `internal/`.
- Гейты до коммита: `make ozon-fetcher-lint`, `make ozon-fetcher-vet`,
  `make ozon-fetcher-test` (см. `go.md` «Гейты»).

## Границы ответственности

Делает: получение активных кабинетов и ключей из Symfony
(`marketplace-connections` v1), HTTP к Ozon API по нескольким кабинетам
параллельно с лимитом на каждый кабинет, курсорную пагинацию, retry на 429 с
backoff, запись raw в S3 без перезаписи, журнал загрузок и дозагрузку
пропусков; позже — сборку envelope v1 и выдачу фактов по pull (ack).

Не делает (§1 `services/AGENTS.md`): не пишет в БД и очереди Symfony, не
нормализует категории начислений (коды источника — как есть), не принимает
решения об идемпотентности по состоянию БД Symfony (ключи записей Marketplace
зависят от занятости — их назначает Symfony при импорте), не знает о периодах
закрытия месяца. `EARLIEST_SAFE_DAY` Symfony — ограничение импорта, не
загрузки raw.

## Хранение

- Собственная БД `ozon_fetcher` (роль `svc_ozon_fetcher`) — только
  техническое состояние, без сумм, ПДн, ключей. `00001`: `sync_job`,
  `checkpoint`, `outbox_batch` (выдача фактов, позже); `00002_ingestion`:
  `fetch_task`, `fetch_attempt`, `raw_object`, `connection_limit` (этап 7
  плана). Миграции — `internal/store/migrations`, прогон —
  `make ozon-fetcher-migrate`. Дисциплина — `services/AGENTS.md` §9.
- S3: бакет `vashfindir-services-raw` (Timeweb), raw immutable — новый объект
  на каждую загрузку, sha256 и размер в `raw_object`; ключи S3 —
  дополнительного пользователя бакета, только env.

## Внутренний API

- `GET /internal/source-facts`, `POST /internal/source-facts/ack` — выдача
  фактов (после первой волны).
- `POST /internal/fetch-requests`, `GET /internal/fetch-requests/{id}` —
  загрузка по запросу (отложено; ляжет на `fetch_task` с приоритетом).
- `GET /internal/fetch-status` — состояние загрузки по кабинетам (этап 12).
- Семантика и мапа статусов для UI — `services/AGENTS.md` §3; endpoint'ы в
  README сервиса. Внутренняя авторизация: internal token + allowlist.

## Специфика Ozon (для агентов)

- Ресурсы первой волны, форма запросов, классы ответов и дефолтные лимиты —
  `docs/ozon-api.md`. Живой финансовый путь — `/v1/finance/accrual/by-day`;
  `/v3/finance/transaction/list` снят Ozon 09.09.2026 (400 `code 9`).
- Лимит — на пару `(кабинет, endpoint)`: старт из дефолтов, ручное значение
  по кабинету в `connection_limit`, адаптация на 429 (`Retry-After`). Ozon
  лимиты финансовых методов не публикует — дефолты консервативные.
- Ключи кабинетов приходят из Symfony и живут только в памяти; в коде, логах,
  БД и фикстурах — никогда. Env-ключей Ozon у сервиса нет.
- Семантика `source_key` — в README контракта
  (`_contracts/source-fact-envelope/v1/`); менять её после утверждения
  контракта нельзя без v2.

## Тесты

- Table-driven, фикстуры ответов API — обезличенные (без ПДн, сумм, токенов).
- Парсинг, ключи S3 и sha256 покрывать эталонными векторами из фикстур.
- Конкурентность (лимитер, захват задач, диспетчер) — с `-race`.
- `go test ./...` зелёный до коммита (в dev: через compose, см. README).
