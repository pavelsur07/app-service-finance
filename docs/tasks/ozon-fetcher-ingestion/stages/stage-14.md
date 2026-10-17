### Stage 14: секреты и вывод на прод — DONE (ждёт секреты и решение Владельца)

**Risk:** HIGH-EXTERNAL (CI/CD, прод, секреты) — merge и включение только по решению Владельца
**Stage base commit:** `b7e4ec391`
**Branch:** `infra/ozon-fetcher-deploy` (отдельная ветка, решение Владельца 2026-10-17; поверх #2710)
**Внешнее ревью:** да — на PR ветки

#### Цель
Сервис можно включить на проде без ручной правки серверов: сначала на одном
кабинете, затем на всех; до включения деплой ничего не меняет.

#### Как устроено включение (главное решение этапа)
Последний шаг деплоя — `docker compose up -d --remove-orphans`: он поднимает
**все** сервисы compose. Поэтому сервис — за compose-профилем `ozon-fetcher`
и включается переменной GitHub `OZON_FETCHER_ENABLED=true`. Пока она не
задана: образ собирается, но контейнер не стартует, миграции сервиса не
идут, `/internal/*` Symfony закрыт пустым токеном. Merge этого PR сам по себе
прод не меняет.

#### Результат
**Код сервиса**
- `CONNECTION_ALLOWLIST` (через запятую, `connection_ref` или `company_id`):
  непустой — грузятся только перечисленные кабинеты; пустой — все
- Dockerfile: сборка с `internal/` и зависимостями (`go.sum`), рантайм alpine,
  non-root, healthcheck-совместимый (`wget`)

**CI (`deploy.yml`)**
- фильтр `services` (`services/ozon-fetcher/**`); job гейтов: vet, golangci-lint, `go test -race`
- образ `ghcr.io/pavelsur07/ozon-fetcher:<sha>` в матрице: на PR — при правке
  `services/`, на master — всегда вместе с остальными (деплой тянет образы по SHA)

**Прод (`docker-compose.prod.yml`, `deploy.yml`)**
- Symfony `x-php-env`: `INTERNAL_AUTH_TOKEN: ${INTERNAL_AUTH_TOKEN:-}`,
  `INTERNAL_ALLOWED_IPS: 172.16.0.0/12` (docker-сети; внешний клиент через
  traefik приходит с публичным IP и с `X-Forwarded-For` → 404 в nginx)
- сервис `ozon-fetcher`: профиль `ozon-fetcher`, сеть `default` без traefik,
  `restart: always`, лимит памяти 384M, healthcheck `/health`,
  `SYMFONY_INTERNAL_BASE_URL=http://site-nginx`, S3 Timeweb
- деплой: экспорт новых секретов; при `OZON_FETCHER_ENABLED=true` — pull,
  миграции сервиса `ozon-fetcher -migrate`, `up --wait`

**Разовая подготовка (Владелец)**
- `docs/maintenance/ozon-fetcher-prod.md`: секреты, БД и роль, пользователь
  S3, включение по шагам, проверка, откат
- `docs/maintenance/ozon-fetcher-db.sql`: БД `ozon_fetcher` и роль
  `svc_ozon_fetcher` на общем инстансе

#### Секреты и переменные GitHub

| Имя | Тип | Значение |
|---|---|---|
| `INTERNAL_AUTH_TOKEN` | secret | агент генерирует (48 символов); Symfony и сервис |
| `OZON_FETCHER_DB_PASSWORD` | secret | агент генерирует; роль `svc_ozon_fetcher` |
| `OZON_FETCHER_S3_ACCESS_KEY_ID`, `OZON_FETCHER_S3_SECRET_ACCESS_KEY` | secret | Владелец: доп. пользователь Timeweb только на бакет |
| `OZON_FETCHER_ENABLED` | variable | `true` — запуск сервиса |
| `OZON_FETCHER_CONNECTION_ALLOWLIST` | variable | кабинет «Вумджой» → пусто (все) |

#### Решения
- БД сервиса — без SSL (`sslmode=disable`): общий инстанс в той же docker-сети,
  Symfony подключается так же; правило «`sslmode=require` в prod» в
  `services/ozon-fetcher/README.md` уточняется под это.
- `INTERNAL_ALLOWED_IPS` — не секрет, задаётся в compose.
- Лимиты — дефолты по замеру 17.10 (by-day 0,3 запроса/с); `MAX_PARALLEL_CONNECTIONS=2` на старте.

#### Work items
- **14.1** — `CONNECTION_ALLOWLIST` + тесты.
- **14.2** — Dockerfile, локальная сборка образа и запуск (`/health`).
- **14.3** — compose: env Symfony, сервис с профилем; `docker compose config` с профилем и без.
- **14.4** — `deploy.yml`: фильтр, job гейтов, матрица образов, экспорт секретов, шаг сервиса.
- **14.5** — SQL и инструкция Владельцу; генерация значений токена и пароля.
- **14.6** — проверка YAML (`actionlint`, если доступен), внутреннее и внешнее ревью, PR.

#### Definition of Done / чек-лист готовности
- [x] без `OZON_FETCHER_ENABLED` деплой не запускает сервис и не трогает его БД
- [x] `/internal/*` Symfony закрыт, пока `INTERNAL_AUTH_TOKEN` не задан
- [x] образ собирается из `services/ozon-fetcher` и проходит `/health`
- [x] на master образ сервиса собирается вместе с остальными (деплой не падает на `manifest unknown`)
- [x] гейты сервиса в CI
- [x] `CONNECTION_ALLOWLIST` ограничивает кабинеты
- [x] секреты — только имена в репозитории
- [x] инструкция Владельцу: секреты, БД, S3, включение, проверка, откат
- [x] внешнее ревью — green

#### What was done
- 14.1 — `CONNECTION_ALLOWLIST` (`connection_ref` или `company_id`) + тесты.
- 14.2 — Dockerfile: `go.sum`, `internal/`, `CGO_ENABLED=0`, alpine non-root,
  `HEALTHCHECK`; `.dockerignore`. Образ 32 МБ, healthy, `/ready` в scaffold.
- 14.3 — compose: `INTERNAL_AUTH_TOKEN`/`INTERNAL_ALLOWED_IPS` в `x-php-env`;
  сервис `ozon-fetcher` за профилем; `docker compose config` — без профиля
  сервиса нет.
- 14.4 — `deploy.yml`: фильтр `services`, job `services-go`, образ в матрице
  (на master — всегда), экспорт секретов, шаг 4b/5 (bootstrap БД → pull →
  миграции → `up --wait`) только при `OZON_FETCHER_ENABLED=true`, иначе `stop`.
- 14.5 — `docker/ozon-fetcher/db-bootstrap.sql` (идемпотентно, проверено
  дважды подряд; роль не читает таблицы Symfony и не создаёт объекты в её
  схеме), `docs/maintenance/ozon-fetcher-prod.md`. Кабинет «Вумджой» для
  allowlist: seller-подключение `90b72323-784a-4268-88aa-10778c3da78b`
  (read-only запрос к проду).

#### Checks
- Go: vet, golangci-lint, `-race` — OK; YAML — OK; `actionlint` — новых
  замечаний нет (2 прежних стилевых)

#### External review
- round 1 (Codex, medium), 3 IMPORTANT — все про секреты в деплое:
  подстановка в двойных кавычках исполнялась бы удалённым shell; пароль в
  аргументах процессов; пароль в DSN без URL-кодирования →
  одинарные кавычки + проверка алфавита `A-Za-z0-9._~-` (проверено в `dash`:
  `$(…)` не исполняется, деплой падает с сообщением), `\getenv` в psql.
  **Fixed without re-run** (§7.2)

#### Осталось Владельцу
- Timeweb: пользователь S3 только на бакет; GitHub secrets и variables по
  `docs/maintenance/ozon-fetcher-prod.md`; merge цепочки PR и деплой
