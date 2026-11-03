### Stage 0: вынос ядра в `services/shared/fetchkit` — DONE (ночь ozon-fetcher после деплоя — замер 04.11)

**Risk:** HIGH-LOCAL (рефакторинг работающего прод-сервиса) · **Branch:** `feat/wb-fetcher-finance`
**Stage base commit:** `62dda3879` · **Внешнее ревью:** да (Codex, по итогу этапа — diff > 500 строк)

## 1. Какую задачу решаем

spec-wb §5 запрещает копировать ядро ozon-fetcher в wb-fetcher. Ядро сейчас
привязано к Ozon в конкретных местах (разбор 02.11.2026):

| Место | Привязка |
|---|---|
| `executor/outcome.go`, `ratelimit` | классы ошибок — `ozon.APIError` / `ozon.Class*` |
| `executor/executor.go` | общий механизм (lease, запись страницы, ревизия, исход) смешан со `switch` по ресурсам Ozon; `Provider: "ozon"` в ключе S3 |
| `dispatcher`, `planner/scheduler.go`, `httpapi` | тип `connections.Cabinet` с ключами `ozon.Credentials` / `ozonperf.Credentials` |
| `connections` | `?provider=ozon`, проверка `Provider != "ozon"`, разбор ключей Ozon |
| `ratelimit.DefaultConfig` | лимиты методов Ozon |
| `store/tasks.go` | SQL `LIKE 'ozon.ads.%'` (отдельный слот и пауза для рекламы, индекс 00006), `AdsReportInFlight` |
| `observability`, `httpapi/coverage.go` | имя сервиса `ozon-fetcher` константой |

Не привязаны: `dispatcher` (кроме типа кабинета), `rawstore` (провайдер — поле объекта), основная часть `store`.

## 2. Что будет результатом работы

Модуль `services/shared/fetchkit` (`go.mod`: `github.com/pavelsur07/app-service-finance/services/shared/fetchkit`, `go 1.27`),
в ozon-fetcher — `require` + `replace => ../shared/fetchkit`, `services/go.work` для разработки.

| Пакет fetchkit | Источник | Что меняется при переносе |
|---|---|---|
| `apierr` | `ozon/errors.go` (Class, APIError) | без изменений; в `ozon` — алиасы `type APIError = apierr.APIError`, константы `ClassX = apierr.ClassX`, код Ozon не трогается |
| `store` (+ `storetest`) | `internal/store` | `NewTasks(pool, Options{SeparateSlotPrefix})`: SQL берёт префикс параметром (`''` — отдельного слота нет); `AdsReportInFlight` уходит в ozon-fetcher (`internal/ozonstore`) через `Tasks.Pool()`; `Migrate(ctx, dsn, fs)` принимает миграции сервиса; `CoreMigrations` — сжатая схема для новых сервисов |
| `rawstore` | `internal/rawstore` | без изменений; пустой `Provider` — ошибка `Put` |
| `ratelimit` | `internal/ratelimit` | `Observe(ref, endpoint, *apierr.APIError)`; `DefaultConfig` Ozon — в ozon-fetcher `internal/limits` |
| `dispatcher` | `internal/dispatcher` | дженерик по типу кабинета `C` |
| `fetch` | общий механизм `executor.go` + `outcome.go` | `Executor[C]`: Run, продление lease, запись страницы (`Run.Store`), одна страница (`Run.Single`), хеш и ревизия, общая таблица исходов (успех, lease, остановка, сбой S3/журнала, классы `apierr`, неизвестный ресурс/период), `retryable`; адаптер сервиса: `Fetch` (ресурсы), `Classify` (свои ошибки до общей таблицы), `ResumesConnection`, `LogLevel`, `StaleCursor` |
| `connections` | `internal/connections` | `Cache[C]`, `Client` с `provider` и функцией разбора записи; тип `Cabinet` Ozon и разбор ключей остаются в ozon-fetcher |
| `schedule` | `planner/scheduler.go` | `Scheduler[C]` с функцией построения плана; `TaskSpec`/`Plan`/`Refresh` — типы fetchkit |
| `httpapi` | `internal/httpapi` | `Deps.Service`; вместо списка кабинетов — `Expected() (refs, map[resource]int)` |
| `observability` | `internal/observability` | `Setup(service, …)` |

В ozon-fetcher остаются: `ozon`, `ozonperf`, ресурсы executor'а (адаптер),
`planner.Build`, `config`, `app`, `cmd`, тип кабинета, `limits`, `ozonstore`,
**свои миграции 00001–00007 без изменений** (история goose на проде та же).

## 3. Что считаем выполненной работой

- ozon-fetcher собран на fetchkit; поведение не изменилось: прежние тесты зелёные
  **без правки ожиданий** (меняются импорты и конструкторы), golden-тесты до/после совпадают.
- В fetchkit нет импортов ozon-fetcher и строк `ozon` вне тестов совместимости.
- Схема ozon-fetcher после его миграций совместима с fetchkit (тест).
- Docker-образ собирается из контекста `services/`; compose dev/prod, deploy.yml, Makefile обновлены.

## 4. Work items

| # | Шаг | Проверка |
|---|---|---|
| 0.1 | Golden-тесты на **текущем** коде: план `Build` (3 кабинета × 4 момента), ответ `/internal/coverage` на фейковых данных, ключ S3 — отдельным коммитом | зелёные на старом коде |
| 0.2 | Каркас `services/shared/fetchkit`, `go.work`; перенос `apierr`, `rawstore`, `observability` | тесты пакетов |
| 0.3 | Перенос `store` + `storetest`, параметр отдельного слота, `ozonstore.AdsReportInFlight`, `CoreMigrations` | unit + интеграционные на services-postgres |
| 0.4 | Перенос `ratelimit`, `connections` (дженерик), `dispatcher` (дженерик) | тесты пакетов |
| 0.5 | `fetch.Executor[C]` + адаптер Ozon; перенос `schedule`, `httpapi` | весь набор ozon-fetcher |
| 0.6 | Dockerfile (контекст `services/`), compose dev и prod, deploy.yml, Makefile `fetchkit-*`, гейт «нет ozon в fetchkit» | сборка образа, `docker compose config` |
| 0.7 | README/AGENTS ozon-fetcher, `services/README.md`, `services/go.md` (раздел fetchkit) | — |

## 5. TDD — тесты пишутся первыми

Фиксация поведения (на текущем коде, зелёные до переноса, остаются зелёными после):
- [x] `planner`: golden `Build(now, cabinets, cfg)` — кабинеты: seller+performance со всеми ресурсами, только seller с базовыми, кабинет без ключа; моменты: пн 06:00, вт 03:00, 1-е число 00:30, вс 23:30 МСК → `testdata/plan_*.golden.json`
- [x] `httpapi`: golden-ответ `/internal/coverage` (фейковые `Coverage` и кабинеты) — тело побайтно
- [x] `rawstore`: формат ключа `ozon/{ref}/{res}/{period}/{ts}_{attempt}_p002_{sha8}.json.gz` (есть, сохраняется)

Новые тесты fetchkit (красные до реализации):
- [x] `rawstore`: `Provider: "wb"` → ключ `wb/…`; пустой `Provider` → ошибка `Put`
- [x] `store` (интеграционный): `SeparateSlotPrefix=""` — у кабинета не больше одной running-задачи на все ресурсы; `"ozon.ads."` — реклама и продажи в разных слотах, пауза кабинета не держит рекламу (текущие тесты, теперь с параметром)
- [x] `store`: схема после `CoreMigrations` и после миграций ozon-fetcher совпадает по таблицам и колонкам (тест в ozon-fetcher)
- [x] `connections`: `provider=wildberries` уходит в query; запись чужого провайдера отбрасывается; функция разбора адаптера вызывается на каждую запись
- [x] `dispatcher`: работает с произвольным типом кабинета (фейковый тип)
- [x] `fetch`: фейковый адаптер — «первая страница и тишина» (2 страницы → 2 raw, `done`); повтор того же содержимого → `unchanged`; `Classify` адаптера перехватывает свою ошибку раньше общей таблицы; `apierr.ClassRateLimit` с `RetryAfter` → `pending` через паузу; `ClassObsoleteEndpoint` → `StopResource` + `failed_permanent`; `ClassAuth` → `RecordAuthFailure`, вторая подряд — ERROR
- [x] `httpapi`: `Service` из `Deps` в ответе coverage (тест ядра); в логах имя сервиса несёт `observability.Setup(service)`

## 6. Чек-лист готовности

- [x] golden-тесты сняты на старом коде и закоммичены отдельно (284bc13e4)
- [x] `services/shared/fetchkit/go.mod`, `replace` в ozon-fetcher; `go.work` не заводим — replace достаточно, сборка с `GOWORK=off` (`services/go.md` «Монорепо»)
- [x] в fetchkit нет импортов сервисов и литералов провайдеров вне `_test.go` — гейт тестом `fetchkit/internal/boundary` (красный на подставленном нарушении), идёт в CI с тестами ядра
- [x] все прежние тесты ozon-fetcher зелёные без правки ожиданий; golden совпадают
- [x] интеграционные тесты на PostgreSQL 15 (`TEST_DB_DSN`): fetchkit/store, ozonstore, dispatcher, executor (132 PASS, 1 SKIP — нужен S3, как на базе)
- [x] миграции ozon-fetcher не изменены: перенесены в `internal/ozonstore/migrations` rename 100% (goose ведёт версии по номеру, не пути); схема ядра сверяется `TestSchemaMatchesFetchkitCore`
- [x] Dockerfile ozon-fetcher собирается из `services/` (локальная сборка образа ок); compose dev и deploy.yml указывают новый контекст; prod-compose берёт готовый образ — без правок
- [x] `go vet`, `golangci-lint` (0 issues), `go test -race` по fetchkit и ozon-fetcher (по пакету, контейнер с лимитом памяти)
- [x] документы: README/AGENTS ozon-fetcher, `services/README.md`, `services/go.md`, `shared/fetchkit/README.md`
- [x] внутреннее ревью diff от `62dda3879`, внешнее ревью (Codex): раунд 1 — IMPORTANT (типы credentials проверялись после фильтрации записи) исправлен в 43846f0df с регрессионным тестом; раунд 2 — REVIEW_GREEN
- [ ] после деплоя (в рамках handoff задачи): ночь без новых ERROR в GlitchTip ozon-fetcher, покрытие месяца не изменилось

## Baseline (02.11.2026)

`go test -race -count=1` по пакетам ozon-fetcher (контейнер golang:1.27, 1200m, -p=1) —
12 пакетов ok, интеграционные store/rawstore без `TEST_DB_DSN`/`TEST_S3_*` пропущены.
