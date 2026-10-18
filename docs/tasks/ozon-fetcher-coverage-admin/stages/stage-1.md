### Stage 1: контракт и `/internal/coverage` в сервисе — DONE

**Risk:** MEDIUM · **Base:** `21706471a` · **Branch:** `feat/ozon-fetcher-coverage-admin`
**Внешнее ревью:** нет (на handoff задачи)

## 1. Какую задачу решаем

Странице покрытия нужна сводка «отчёт × период → сколько кабинетов в каком
статусе». Сейчас у сервиса есть только `/internal/fetch-status` — он отдаёт
состояние **по кабинетам** без разбивки по дням, и его схема своя, не
стандартная. По правилу шлюза (`docs/architecture/service-gateway.md` §2.3–2.4,
§2.9) у каждого сервиса должен быть стандартный `/internal/coverage` по
версионированному контракту, а запросы Symfony → сервис — со своим токеном.

## 2. Что будет результатом работы

- **Контракт** `services/_contracts/service-coverage/v1`: `schema.json`,
  `README.md` (семантика полей), `CHANGELOG.md`; строка в индексе
  `services/_contracts/README.md`. Контракт общий для будущих сервисов.
- **`store.Coverage(ctx, refs, from, to)`** — один запрос `GROUP BY
  resource_type, period_key, status` по `fetch_task` только активных кабинетов
  (`connection_ref = ANY(refs)`), с `max(last_success_at)`; окно: дни
  `from…to`, месяцы этих дат, справочник `ref`.
- **`GET /internal/coverage?from=YYYY-MM-DD&to=YYYY-MM-DD`** в `internal/httpapi`:
  - по умолчанию — окно сервиса M-2…конец M (МСК);
  - `cabinets_total` и `cabinets_expected` по ресурсу — из снимка кабинетов
    (`Supports(resource)`);
  - ответ — по схеме v1, без `connection_ref`, `company_id`, сумм;
  - 422 `invalid_period`: кривая дата, `from > to`, окно шире 93 дней.
- **Токен `SERVICE_API_TOKEN`** (≥ 32 символов) — для всех `/internal/*`
  сервиса (`coverage`, `fetch-status`). Пустой — `/internal/*` закрыт (401).
  `INTERNAL_AUTH_TOKEN` остаётся только для направления «сервис → Symfony».
- Документация: README сервиса (env, endpoint), `docs/maintenance/ozon-fetcher-prod.md`
  (проверка статуса — новым токеном).

## 3. Что считаем выполненной работой

Сервис по запросу с `SERVICE_API_TOKEN` отдаёт сводку покрытия за окно,
которая проходит проверку по `schema.json` v1 и совпадает с данными
`fetch_task`; без токена или с токеном `INTERNAL_AUTH_TOKEN` — 401; гейты
сервиса зелёные.

## 4. Чек-лист приёмки

- [x] контракт: `schema.json` (draft 2020-12, `additionalProperties: false`), README, CHANGELOG, индекс
- [x] `store.Coverage`: один SQL-запрос; только активные кабинеты; дни, месяцы и `ref` окна
- [x] интеграционный тест `store.Coverage` (своя схема `storetest`): статусы, чужие кабинеты не считаются, вне окна не попадает
- [x] `/internal/coverage`: окно по умолчанию M-2…M по МСК; `from`/`to` из запроса
- [x] 422 `invalid_period` на кривую дату, `from > to`, окно > 93 дней
- [x] `cabinets_expected` по ресурсу; кабинет без ключа seller не ожидается
- [x] ответ без `connection_ref`, `company_id`, сумм; ключи ответа совпадают со схемой (тест)
- [x] `SERVICE_API_TOKEN` ≥ 32 в конфиге; `/internal/*` закрыт при пустом; `INTERNAL_AUTH_TOKEN` на `/internal/*` не подходит (тест)
- [x] README сервиса, `.env.example`, `ozon-fetcher-prod.md` обновлены
- [x] `go vet`, `golangci-lint` — 0 issues; `go test -race` по пакетам

## Итог

- Контракт `services/_contracts/service-coverage/v1` (schema, README, CHANGELOG), строка в индексе.
- `store.Coverage` (`internal/store/coverage.go`) — один `GROUP BY` по `fetch_task`; тест
  `TestCoverageCountsActiveCabinetsWithinWindow` на своей схеме: статусы, чужой кабинет, вне окна.
- `GET /internal/coverage` (`internal/httpapi/coverage.go`): окно по умолчанию 2026-08-01…2026-10-31
  при «сейчас» 17.10 МСК; 93 дня включительно — граница, 94 — 422; `cabinets_expected` без дублей,
  кабинет без Seller не ожидается и в запрос не попадает; ключи ответа совпадают со схемой (тест —
  в сервисе, т. к. CI монтирует только `services/ozon-fetcher`; проверку `schema.json` делает шлюз,
  этап 2).
- `SERVICE_API_TOKEN` (< 32 — ошибка конфига; пусто — `/internal/*` закрыт); `fetch-status` и
  `coverage` — через общий `requireToken`; другой токен — 401 (тест).
- Документы: README сервиса, `.env.example`, `docs/maintenance/ozon-fetcher-prod.md` (секрет
  `OZON_FETCHER_API_TOKEN`, проверка статуса новым токеном), §4 `service-gateway.md`.
- Гейты: `gofmt` чисто, `go vet` ок, `golangci-lint` 0 issues, `go test -race ./...` — все пакеты ok.

**Внимание к деплою:** пока секрет и env (этап 4) не заведены, `/internal/*` сервиса на проде
закрыт — проверка `fetch-status` старым токеном перестанет работать. Деплой — только вместе с
этапом 4 (одним handoff).
