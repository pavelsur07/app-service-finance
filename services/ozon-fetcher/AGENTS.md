# ozon-fetcher — правила сервиса

Первый сервис монорепо `services/`: получение финансовых данных Ozon Seller
API и публикация source facts в Symfony (вариант A: HTTP push + durable inbox).

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

Делает: HTTP к Ozon API (rate limit, курсорная пагинация, retry на 429 с
backoff), контрольные суммы и `payload_hash` raw-строк, сборку envelope v1,
накопление фактов в outbox и выдачу их Symfony по pull (ack, §3
`services/AGENTS.md`).

Не делает (§1 `services/AGENTS.md`): не пишет в БД и очереди Symfony, не
нормализует категории начислений (в v1 публикует коды источника как есть), не
принимает решения об идемпотентности по состоянию БД, не знает о периодах и
закрытии месяца.

## Хранение

- Собственная БД `ozon_fetcher` (роль `svc_ozon_fetcher`) — только
  техническое состояние: `sync_job`, `checkpoint`, `outbox_batch`
  (миграции `internal/store/migrations`; прогон — `make ozon-fetcher-migrate`).
  Дисциплина — `services/AGENTS.md` §9.
- S3: бакет `vashfindir-services-raw` (Timeweb), raw immutable, sha256/size в
  envelope; ключи — дополнительного пользователя бакета, только env.

## Внутренний API (контракты, к кодовым Stage'ам)

- `GET /internal/source-facts` — выдача `status=new` батчей (фильтры +
  курсор); `POST /internal/source-facts/ack` — единственный переход в `acked`.
- `POST /internal/fetch-requests`, `GET /internal/fetch-requests/{id}` —
  триггер первичной загрузки; статусы `queued|fetching|ready|failed`.
- Семантика и мапа статусов для UI — `services/AGENTS.md` §3; endpoint'ы в
  README сервиса. Внутренняя авторизация: internal token + allowlist.

## Специфика Ozon (для агентов)

- Seller API, finance (`/v3/finance/transaction-list` и смежные): курсорная
  пагинация; соблюдать rate limit, на 429 — retry с экспоненциальным backoff +
  jitter, курсор сохранять между попытками.
- Секреты: `OZON_CLIENT_ID`, `OZON_API_KEY` — только env; в коде, логах и
  тестах-фикстурах — никогда.
- Семантика `source_key` для `ozon.finance.transaction-list` — в README
  контракта (`_contracts/source-fact-envelope/v1/`); менять её нельзя без v2.

## Тесты

- Table-driven, фикстуры ответов API — обезличенные (без ПДн, сумм, токенов).
- Парсинг и сборку ключа/`payload_hash` покрывать эталонными векторами из
  фикстур.
- `go test ./...` зелёный до коммита (в dev: через compose, см. README).
