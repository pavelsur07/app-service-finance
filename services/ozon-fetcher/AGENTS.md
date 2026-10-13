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
публикацию фактов с идемпотентным replay.

Не делает (§1 `services/AGENTS.md`): не пишет в БД и очереди Symfony, не
нормализует категории начислений (в v1 публикует коды источника как есть), не
принимает решения об идемпотентности по состоянию БД, не знает о периодах и
закрытии месяца.

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
