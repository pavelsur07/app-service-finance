### Stage 2: `wb-fetcher` — каркас и ежедневный отчёт — DONE

**Risk:** MEDIUM · **Ветка:** `feat/wb-fetcher-finance` (одна на задачу, PR на handoff)
**Внешнее ревью:** нет (на handoff)

## 1. Какую задачу решаем

Нужен сервис, который по WB-кабинетам из Symfony грузит дневной детальный отчёт о
реализации в raw S3 с журналом и отдаёт покрытие.

## 2. Факты (02.11.2026, из `WbFinanceSalesReportClient` и этапов 0–1)

- `POST {base}/api/finance/v1/sales-reports/detailed`, заголовок `Authorization: <токен>` (без `Bearer`),
  тело `{"dateFrom","dateTo","limit":100000,"rrdId","period":"daily"}`, таймаут 120 с.
- Ответ: 200 — JSON-список объектов; 204 — пусто. Курсор — поле **`rrdId`** последней строки
  (camelCase); следующая страница запрашивается только если строк `>= limit`. `rrdId` последней
  строки `<=` текущего курсора — нарушение формы (Symfony бросает invalid response).
- 429: пауза из `X-Ratelimit-Retry` / `Retry-After` / `X-Ratelimit-Reset`, иначе 70 с.
  401/403 — ключ; 400 — запрос; 5xx и транспорт — временная; иной статус — временная (как Symfony).
- `GET {base}/ping` — проверка доступа (в этапе 2 не нужна; `/ready` сервиса — БД и S3).
- Кабинет из снимка: `connection_ref` = id подключения = `seller.client_id` (этап 1);
  ресурсы — по `resource_types`.
- Ядро: `fetchkit` (этап 0): `store`, `fetch.Adapter`, `dispatcher`, `schedule`, `httpapi`,
  `ratelimit`, `rawstore` с `Provider: "wb"` → ключ `wb/{ref}/{resource}/{period}/…`.

## 3. Что будет результатом работы

| Пакет / файл | Что |
|---|---|
| `cmd/wb-fetcher/main.go` | wiring: config → БД/миграции → S3 → кабинеты → планировщик, диспетчер, HTTP; graceful shutdown; `-migrate` |
| `internal/config` | env (§4), валидация, fail fast |
| `internal/wb` | клиент finance-api: страница отчёта, классификация ответов в `apierr` (`Source: "wb"`), разбор пауз 429; токен не попадает в ошибки и логи |
| `internal/connections` | `Cabinet{Ref, CompanyID, UpdatedAt, Token, ResourceTypes}` + `Decoder` поверх `fetchkit/connections`, `Provider = "wildberries"` |
| `internal/planner` | `Build(now, cabinets)`: вчера (МСК) каждым проходом, хвост `WB_DAILY_TAIL_DAYS` раз в сутки; `period_key = YYYY-MM-DD` |
| `internal/executor` | `fetch.Adapter[Cabinet]`: пагинация по `rrdId` с курсором задачи, предел страниц, лимитер «1 запрос / `WB_FINANCE_MIN_INTERVAL`» на кабинет (`ratelimit`), окно :10/:20 |
| `internal/app` | wiring цепочки; `Migrate` = миграции ядра `store.CoreMigrations()` (своего пакета БД нет — схема ядра), `NewTasks` без отдельного слота, `Limits` |
| `test/stand/mock` | мок снимка кабинетов и finance-api для стенда (429, 100 000 строк, задержка второй страницы) |
| `internal/httpapi` | обёртка `fetchkit/httpapi`: `Service = "wb-fetcher"`, ожидания покрытия из снимка |
| `docs/wb-api.md` | хост, категория токена «Финансы», метод, тело, пагинация, лимит, статусы, расписание |
| `AGENTS.md`, `README.md`, `.env.example`, `.golangci.yml`, `docker/Dockerfile` (+`.dockerignore`, контекст `services/`) | по `services/go.md` |
| `docker-compose.yml` | `services-wb-fetcher`, `-dev`, `-lint` (профиль `services`) |
| `services/docker/postgres/init/03-wb-fetcher.sql` | dev-БД `wb_fetcher`, роль `svc_wb_fetcher` |
| `Makefile` | `wb-fetcher-build/-up/-test/-vet/-lint/-migrate` |
| CI `deploy.yml` | фильтр `services/wb-fetcher/**`, цикл гейтов по модулям — `+ wb-fetcher`; образ в матрицу — **этап 5** (вместе с прод-compose) |
| `services/README.md` | строка в таблице «Сервисы» |

Окно :10/:20 — диспетчер не выдаёт задачи WB finance в минуты `:10` и `:20` часа (cron Symfony
03:10 и `orchestrate` в :20 — решение 3 плана): проверка в адаптере перед запросом, задача
возвращается в очередь `pending` с `next_run_at` = конец окна, без попытки к WB.

## 4. Переменные окружения

`LISTEN_ADDR`, `DB_DSN`, `S3_ENDPOINT/REGION/BUCKET/ACCESS_KEY_ID/SECRET_ACCESS_KEY`,
`SYMFONY_INTERNAL_BASE_URL`, `INTERNAL_AUTH_TOKEN`, `SERVICE_API_TOKEN`, `SENTRY_*`,
`CONNECTION_ALLOWLIST`, `CONNECTIONS_POLL_INTERVAL` (5m), `MAX_PARALLEL_CONNECTIONS` (2),
`WB_FINANCE_BASE_URL` (`https://finance-api.wildberries.ru`), `WB_DAILY_TAIL_DAYS` (14, 1…30),
`WB_FINANCE_MIN_INTERVAL` (70s, ≥ 60s), `PLAN_EVERY` (1h). Имена S3 — те же, что у ozon-fetcher
(решение В1: тот же пользователь и бакет).

## 5. TDD — тесты пишутся первыми

Клиент (`internal/wb`, `httptest.Server`):
- [x] запрос: метод, путь, `Authorization: <токен>`, тело `period=daily`, `dateFrom=dateTo`, `limit`, `rrdId`
- [x] 200 со списком → тело страницы, `next` = `rrdId` последней строки, `more` = (строк ≥ limit)
- [x] 204 и `[]` → пустая страница, конец
- [x] `rrdId` последней строки ≤ курсора → `invalid_response`
- [x] 429 с `X-Ratelimit-Retry: 30` → `rate_limit`, 30 с; с `Retry-After`; без заголовков → 70 с
- [x] 401/403 → `auth`; 400 → `invalid_request`; 5xx, транспорт, иной статус → `transient`
- [x] не-JSON, не-список, элемент не объект → `invalid_response`
- [x] токен не встречается в тексте ошибок

Кабинеты (`internal/connections`):
- [x] запись WB → `Cabinet` с токеном из `seller.api_key`; без seller → отвергнута; `%v` кабинета не печатает токен

Расписание (`internal/planner`, фиксированные часы):
- [x] 03:00 МСК → вчера + хвост 14 дней (Ensure), `period_key` по МСК
- [x] 00:30 МСК (= 21:30 UTC предыдущего дня) → «вчера» — по МСК
- [x] кабинет без `wb.finance.sales-report.daily` → задач нет
- [x] перечитывание: вчера и хвост выдаются как Refresh раз в сутки (повтор `done` → проверка `unchanged`)

Исполнитель (`internal/executor`, фейковый клиент + `fetch` ядра):
- [x] одна неполная страница → 1 запрос, 1 raw, `done`
- [x] полная страница + неполная → 2 запроса, курсор второго = `rrdId` первой
- [x] задача с сохранённым курсором → первый запрос с этим курсором (продолжение после рестарта)
- [x] предел страниц превышен → `failed_permanent`
- [x] минута :10 или :20 → запроса нет, задача `pending` до конца окна
- [x] два запроса одного кабинета → второй ждёт `WB_FINANCE_MIN_INTERVAL` (лимитер)
- [x] 429 → `pending` с паузой из заголовка (сквозь таблицу исходов ядра)

Интеграция (PG15 `TEST_DB_DSN`):
- [x] миграции ядра на пустой БД (схема = ядро по построению; сверка схемы ядра с ozon — тест ozonstore)
- [x] сквозной прогон исполнителя: задача → страницы в фейковом raw → `done`, `content_hash`; повтор тем же содержимым → `unchanged`

HTTP API:
- [x] `/internal/coverage` → `service: "wb-fetcher"`, строка дня, `cabinets_expected` по ресурсу из снимка; без токена → 401

Config:
- [x] значения по умолчанию; `WB_DAILY_TAIL_DAYS` вне 1…30 и `WB_FINANCE_MIN_INTERVAL` < 60s → ошибка старта

## 6. Чек-лист приёмки

- [x] тесты §5 написаны до реализации каждого пакета (красные — пакет без реализации не собирался)
- [x] `wb-fetcher` использует `fetchkit`; гейт `internal/boundary` ядра зелёный; ядро не скопировано
- [x] раскладка и файлы по `services/go.md`; чек-лист `services/README.md`
- [x] `docs/wb-api.md` заполнен
- [x] стенд (02.11, мок `test/stand/mock` вместо локального Symfony — сторона Symfony покрыта functional-тестом этапа 1): минута :10 → `symfony_window`, запроса нет; 429 → `rate_limit`, пауза, темп кабинета снижен до 1/120 с; страница 100 000 строк → вторая с `rrdId=100000`; остановка (SIGTERM) в ожидании и во время запроса второй страницы → `released`, курсор сохранён; рестарт продолжил с курсора; день `done`, 2 raw `wb/wb-stand/…` без дублей; `/internal/coverage` — `service: wb-fetcher`, без токена 401; `/ready` — db/s3/cabinets up
- [x] образ собирается из `services/`
- [x] гейты `go vet`, `golangci-lint`, `go test -race` для fetchkit, ozon-fetcher, wb-fetcher (по пакету, память OOM)
- [x] строка в таблице «Сервисы» `services/README.md`
