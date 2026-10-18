# ozon-fetcher-coverage-admin: страница «Покрытие Сервис GO» в админке

Промежуточный этап на Twig перед новой админкой `admin.vashfindir.ru`
(решение Владельца 2026-10-18). Делается по правилу шлюза
`docs/architecture/service-gateway.md`: фронт → Symfony → сервис, экран
читает витрину, а не сервис.

## Цель

Админ за один взгляд видит, что `ozon-fetcher` загрузил за месяц: по каждому
отчёту и дню — сколько кабинетов из всех загружено, что в работе, что упало.

## Решения Владельца (2026-10-18)

| # | Решение |
|---|---|
| 1 | Страница в текущей админке на Twig, `ROLE_ADMIN` |
| 2 | Строки — отчёты (endpoint'ы) сервиса, столбцы — дни месяца, тепловая карта по образцу `/ingestion/coverage`; кабинеты сводно, без разбивки |
| 3 | Месячные отчёты (реализация, взаиморасчёты) и справочник — строкой с одной ячейкой на месяц |
| 4 | Месяцы — только окно сервиса: текущий, M-1, M-2; старше — не показываем |
| 5 | По правилу шлюза: витрина в БД Symfony + фоновое обновление; страница читает витрину |

## Вне задачи

React, разбивка по кабинетам, WB и другие источники, общая витрина
`data_coverage` для всех источников, модуль-шлюз целиком (реестр, circuit
breaker) — следующие задачи. Здесь — минимум шлюза, нужный странице, но по его
правилам.

## Как это работает

```
cron */10 мин ─► app:service-gateway:coverage:refresh
                   └─ GET http://ozon-fetcher:8080/internal/coverage?from=M-2&to=M   (токен Symfony → сервис)
                   └─ запись сводки в витрину service_coverage (одна транзакция)
админ ─► /admin/services/ozon-fetcher/coverage?month=YYYY-MM
                   └─ читает только service_coverage → тепловая карта (Twig)
```

### Контракт `service-coverage` v1 (`services/_contracts/service-coverage/v1`)

Стандартный endpoint из правила шлюза (§2.3) — одинаковый для будущих сервисов.

```json
{
  "schema_version": "1",
  "service": "ozon-fetcher",
  "generated_at": "2026-10-18T09:00:00+03:00",
  "from": "2026-08-01",
  "to": "2026-10-31",
  "cabinets_total": 4,
  "rows": [
    {
      "resource_type": "ozon.finance.accrual-by-day",
      "period_key": "2026-10-17",
      "cabinets_expected": 4,
      "by_status": {"done": 3, "running": 1, "pending": 0, "not_ready": 0, "failed_retryable": 0, "failed_permanent": 0},
      "last_success_at": "2026-10-18T00:05:12+03:00"
    }
  ]
}
```

- Без `connection_ref`, `company_id`, сумм — только счётчики.
- `period_key`: `YYYY-MM-DD` (дневные), `YYYY-MM` (месячные), `ref` (справочник).
- `cabinets_expected` — активные кабинеты, у которых есть этот ресурс
  (из снимка кабинетов сервиса); отсутствующая задача = «не загружено».

### Витрина `service_coverage` (БД Symfony, модуль `ServiceGateway`)

| Поле | Тип |
|---|---|
| `service` | `varchar` — `ozon-fetcher` |
| `resource_type`, `period_key` | `varchar` |
| `cabinets_expected` | `int` |
| `done`, `running`, `pending`, `not_ready`, `failed_retryable`, `failed_permanent` | `int` |
| `last_success_at` | `timestamptz null` |
| `refreshed_at` | `timestamptz` — время снимка |

PK `(service, resource_type, period_key)`. Обновление — замена окна сервиса
одной транзакцией. Данные не принадлежат компании (сводка без кабинетов) —
`@companyScopeExempt` с причиной.

### Ячейка тепловой карты

| Цвет | Условие | Подпись |
|---|---|---|
| зелёный | `done = cabinets_expected` | `4/4` |
| жёлтый | часть загружена, остальное `pending` / `running` / `not_ready` / `failed_retryable` | `3/4` |
| красный | есть `failed_permanent` | `3/4 !` |
| серый | задач нет (день ещё не наступил или не запланирован) | `—` |

Подсказка при наведении: отчёт, дата, разбивка по статусам, последняя
успешная загрузка. Сверху: «данные на HH:MM»; предупреждение «устарело», если
снимку больше 30 минут; общий процент покрытия месяца; легенда. Цвета и
классы — дизайн-система «Ваш Финдир» (`CLAUDE.frontend.md`).

### Авторизация Symfony → сервис

По правилу (§2.9) — отдельный токен на направление: секрет
`OZON_FETCHER_API_TOKEN`. Сервис принимает его на своих `/internal/*`
(`coverage`, `fetch-status`). Направление «сервис → Symfony» остаётся на
`INTERNAL_AUTH_TOKEN`.

---

## Этапы

| # | Этап | Сторона | Риск | Внешнее ревью |
|---|---|---|---|---|
| 1 | Контракт и `/internal/coverage` в сервисе | Go | MEDIUM | нет (на handoff) |
| 2 | Модуль `ServiceGateway`: клиент, витрина, обновление | Symfony | HIGH-LOCAL (миграция, вызов сервиса) | **да** |
| 3 | Страница «Покрытие Сервис GO» | Symfony / Twig | MEDIUM | нет (на handoff) |
| 4 | Прод: секрет, env, cron, деплой, приёмка | infra | HIGH-EXTERNAL | — (merge and deploy) |
| — | Handoff: полные гейты, внешнее ревью задачи, PR | | | **да** |

### Этап 1. Контракт и `/internal/coverage` в сервисе

- **Цель:** сервис отдаёт сводку покрытия по стандартному контракту.
- **Результат:** `services/_contracts/service-coverage/v1` (schema, README,
  CHANGELOG); `store.Coverage(ctx, from, to)` — один `GROUP BY resource_type,
  period_key, status` по `fetch_task`; `GET /internal/coverage` в
  `internal/httpapi`; `cabinets_expected` — из снимка кабинетов по
  `Supports(resource)`; токен `SERVICE_API_TOKEN` для `/internal/*` сервиса.
- **Выполнено, когда:** ответ проходит схему; дни без задач учтены через
  `cabinets_expected`; без токена — 401; окно ограничено M-2…M.
- **Чек-лист:**
  - [ ] schema + README + CHANGELOG контракта
  - [ ] `store.Coverage` — один запрос, интеграционный тест (свой schema `storetest`)
  - [ ] `/internal/coverage`: параметры `from`/`to` (по умолчанию окно M-2…M), 422 на кривые даты, окно не шире 3 месяцев
  - [ ] `SERVICE_API_TOKEN` (≥ 32) для `/internal/*`; пусто — `/internal/*` сервиса закрыт
  - [ ] unit-тесты handler'а: токен, форма, валидация
  - [ ] `go vet`, `golangci-lint`, `go test -race`

### Этап 2. Модуль `ServiceGateway`: клиент, витрина, обновление

- **Цель:** Symfony раз в 10 минут забирает сводку и хранит её у себя.
- **Результат:** новый модуль `src/ServiceGateway` (`docs/workflow/new-module.md`):
  - `Infrastructure/Client/OzonFetcherClient` — `HttpClient`, таймауты
    connect 2 с / request 5 с, Bearer `OZON_FETCHER_API_TOKEN`, проверка ответа
    по схеме v1 (иначе `ContractViolationException`), заголовок `X-Trace-Id`;
  - `Entity/ServiceCoverage` + миграция `service_coverage` + Builder;
  - `Application/Action/RefreshServiceCoverageAction` — замена окна одной транзакцией;
  - консольная команда `app:service-gateway:coverage:refresh` (flush в Command);
  - `Infrastructure/Query/ServiceCoverageMonthQuery` — сетка месяца → `list<DTO>`;
  - cron `*/10 * * * *` в `docker/cron/app.cron`.
- **Выполнено, когда:** команда наполняет витрину со стенда сервиса;
  недоступный сервис или битый ответ — витрина прежняя, WARNING (повтор
  через 10 мин), без 500 и без пустой витрины; токен не в логах.
- **Чек-лист:**
  - [ ] конфигурация модуля: `routes.yaml`, `doctrine.yaml`, `twig.yaml`; `ModuleBoundaryRules`
  - [ ] env `OZON_FETCHER_BASE_URL`, `OZON_FETCHER_API_TOKEN` (`.env` — пусто; пусто — команда пишет INFO «выключено» и выходит 0)
  - [ ] клиент: таймауты, токен, проверка схемы, `X-Trace-Id`; unit-тесты на `MockHttpClient` (успех, 401, 5xx, таймаут, нарушение схемы)
  - [ ] миграция с PK; Entity + Builder в `tests/Builders/ServiceGateway/`
  - [ ] Action: замена окна в транзакции; сбой — витрина не меняется; integration-тест
  - [ ] Query сетки месяца: одна выборка, без N+1; тест
  - [ ] команда + cron; логи старта/финиша с числом строк
  - [ ] `ARCHITECTURE.md`: модуль, витрина, cron; `service-gateway.md` — «текущее состояние»
  - [ ] PHPStan/CS по изменённым файлам; baseline не растёт

### Этап 3. Страница «Покрытие Сервис GO»

- **Цель:** админ видит тепловую карту месяца.
- **Результат:** `ServiceGateway/Controller/Admin/OzonFetcherCoverageController`
  (`#[IsGranted('ROLE_ADMIN')]`), маршрут
  `admin_service_gateway_ozon_fetcher_coverage` →
  `/admin/services/ozon-fetcher/coverage?month=YYYY-MM`; шаблон в
  `templates/admin/service_gateway/`; пункт меню «Покрытие Сервис GO» в
  группе «Ingestion» сайдбара админки.
- **Выполнено, когда:** страница показывает сетку текущего месяца, M-1 и
  M-2; чужой месяц — redirect на текущий с flash; пустая витрина —
  понятное пустое состояние; «устарело» при снимке старше 30 минут.
- **Чек-лист:**
  - [ ] модель представления ячейки (цвет, подпись, подсказка) — отдельный класс, unit-тесты на все цвета
  - [ ] строки: by-day — дни месяца; реализация, взаиморасчёты, справочник — одна ячейка на месяц
  - [ ] переключатель месяцев только M, M-1, M-2; будущие дни — `—`
  - [ ] шапка: «данные на», «устарело», % покрытия, легенда
  - [ ] модульный гейт: `ServiceGateway\Controller\Admin` под `ROLE_ADMIN` (exempt как `Marketplace\Controller\Admin`)
  - [ ] functional-тесты: 200 для админа, 403 для пользователя, месяц вне окна, пустая витрина, данные из витрины в HTML
  - [ ] визуальная проверка на локальном стенде (`docs/workflow` / память «Локальная визуальная проверка»)
  - [ ] дизайн-система: классы и токены, без хардкода цветов

### Этап 4. Прод

- **Цель:** страница работает на проде с живыми данными.
- **Результат:** секрет `OZON_FETCHER_API_TOKEN` (агент заводит через `gh`,
  hex-48); env в `docker-compose.prod.yml` (`x-php-env`, scheduler и
  `ozon-fetcher` как `SERVICE_API_TOKEN`); экспорт в `deploy.yml`; миграция —
  через гейт миграций (`docs/workflow/release.md`).
- **Выполнено, когда:** после «merge and deploy» cron наполнил витрину,
  страница показывает 4 кабинета за M, M-1, M-2; GlitchTip без новых ошибок;
  ручная проверка статуса сервиса переведена на новый токен (`docs/maintenance/ozon-fetcher-prod.md`).
- **Чек-лист:**
  - [ ] секрет заведён, в репозитории — только имя; деплой проверяет алфавит
  - [ ] env и cron в compose / `app.cron`; `docker compose config` OK
  - [ ] миграция `service_coverage` прошла
  - [ ] приёмка: витрина наполнена, страница отвечает, данные совпадают с `/internal/fetch-status`

### Handoff

- [ ] полные гейты site (`site-stan`, `cs-check`, `strict-types`, `test-unit`, `site-test`) и сервиса
- [ ] финальное внутреннее ревью, внешнее ревью (Codex) по всей задаче
- [ ] `handoff.md`, PR, «merge and deploy»

## Связанные документы

- `docs/architecture/service-gateway.md` — правило шлюза
- `services/AGENTS.md`, `services/go.md` — правила сервиса
- `docs/maintenance/ozon-fetcher-prod.md` — прод сервиса
- `CLAUDE.frontend.md`, `docs/design-system/` — визуал
