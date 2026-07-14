# Plan: ускорение site/tests/Integration

## Источник задачи

Бриф от Владельца в чате: проанализировать `site/tests/Integration` и предложить best practices
по ускорению прохождения тестов (без запуска тестов на этапе анализа). По итогам анализа
Владелец одобрил переход к реализации пункта 1 из предложенного списка.

## Контекст анализа

- 155 тестовых файлов, 691 тестовый метод в `site/tests/Integration`.
- 145 файлов наследуют `IntegrationTestCase` (`site/tests/Support/Kernel/IntegrationTestCase.php`).
- `phpunit.xml` подключает `DAMA\DoctrineTestBundle` с `enable_static_connection: true` —
  каждый тест уже оборачивается в транзакцию, откатываемую в конце (изоляция «из коробки»).
- `IntegrationTestCase::setUp()` дополнительно вызывает `DbReset::reset()`, который на **каждый**
  тест делает `listTableNames()` (information_schema) + `TRUNCATE ~140 таблиц RESTART IDENTITY CASCADE`
  внутри уже открытой DAMA-транзакции — эффект теряется при rollback, работа выполняется впустую.
- Есть отдельный `PostgresResetTestCase` (3 файла: `AdScheduledBatchRepositoryTest`,
  `AdBatchSchedulerCommandTest`, `InventorySchemaTest`) с `#[SkipDatabaseRollback]` — там rollback
  DAMA осознанно отключён (нужна видимость закоммиченных данных из другого соединения), и явный
  TRUNCATE там действительно необходим.
- Грепом ассертов на PK (`assertSame(1, ...)`) — все найденные завязаны на `countBy(companyId, ...)`,
  не на сырые id/RESTART IDENTITY. Прямой зависимости от сброса identity не обнаружено, но это
  не 100% гарантия — требуется реальный прогон.

## Похожие модули / паттерны, на которые опираемся

- `tests/Support/Kernel/PostgresResetTestCase.php` — образец, как явно и осознанно отключать DAMA
  rollback через `#[SkipDatabaseRollback]`, когда он не подходит.
- `tests/Support/Db/DbReset.php` — существующий хелпер, логика TRUNCATE не трогается, просто
  перестаёт вызываться по умолчанию.

## Этапы

### Stage 1 — убрать избыточный TRUNCATE из общего пути (🟢 LOW)

Изменения только в `site/tests/Support/Kernel/IntegrationTestCase.php` (test-инфраструктура,
не `src/`, не миграции, не публичный API, не auth). Поведение теста для внешнего кода не меняется —
меняется только внутренний механизм очистки БД между тестами, с TRUNCATE-based на transaction-rollback
(который и так уже активен через DAMA). Обратимо одной строкой, риск — тестовая инфраструктура,
не прод-код.

Классификация: 🟢 LOW — «чистый внутренний рефакторинг внутри одного сервиса», затрагивает только
`tests/Support/`, не публичный контракт.

Карта изменений:
- `site/tests/Support/Kernel/IntegrationTestCase.php` — `resetDb()` перестаёт вызываться в `setUp()`
  по умолчанию (полагаемся на DAMA rollback). Метод `resetDb()` оставляем как есть (используется
  через override в `PostgresResetTestCase`, который явно отключает DAMA rollback и обязан делать
  реальный TRUNCATE).
- `site/tests/Support/Kernel/PostgresResetTestCase.php` — без изменений (уже переопределяет
  поведение сброса под свои нужды).

Тесты: это изменение тестовой инфраструктуры — «тестом» для него является зелёный прогон всего
`test:integration` (691 метод). Если какой-то тест окажется завязан на `RESTART IDENTITY` (сырой
PK вместо company-scoped данных), он покраснеет и будет видно точку, которую нужно чинить отдельно
(добавить точечный reset в конкретном тесте, а не возвращать глобальный TRUNCATE).

Риски / что смотреть ревьюеру:
- Если после изменения часть тестов начнёт падать из-за накопленных auto-increment ID между
  тестами (legacy-сущности без UUID) — это ожидаемо и чинится точечно в упавших тестах, а не
  откатом всего Stage 1.

### Дальнейшие пункты (не в этом Stage, из исходного анализа)

2. Параллелизация (`paratest`) — отдельная MEDIUM/HIGH задача (новая dev-зависимость → STOP по
   правилам CLAUDE.md), не в этом Stage.
3. Тюнинг Postgres (`fsync=off` и т.д.) для test-инстанса — инфраструктурное изменение
   docker-compose, отдельная задача.
4. `$connection->close()` / `ensureKernelShutdown()` в `tearDown()` — низкий приоритет, косметика,
   можно взять отдельным LOW-этапом при желании.

## Stage 2 — Postgres tuning для локального test-инстанса (🟡 MEDIUM)

### Почему не paratest сразу

Проверил окружение перед стартом: на этой машине 2 vCPU, ~1.2Gi свободного RAM (часть уже в swap).
Стандартные GitHub Actions `ubuntu-latest` раннеры — тоже обычно 2 vCPU. При 2 ядрах paratest даёт
реалистично ×1.5–2, а не линейный рост, плюс требует: новую dev-зависимость (`composer require`,
обязательный STOP по CLAUDE.md), отдельные тестовые БД/схемы на воркер (сейчас все интеграционные
тесты шарят одну `app_test` через DAMA static connection), правки Makefile/CI под провижининг.
Владелец подтвердил (через AskUserQuestion) начать с более дешёвого и безопасного пункта —
тюнинга Postgres, без новых зависимостей.

### Что меняем

`site-postgres` — один и тот же контейнер и для dev-БД (`app`), и для test-БД (`app_test`);
отдельного test-only Postgres в `docker-compose.yml` нет. CI (`.github/workflows/deploy.yml`)
поднимает Postgres как отдельный GitHub Actions service-контейнер напрямую из `postgres:15-alpine`,
никак не связанный с этим `docker-compose.yml` — тюнинг здесь на CI не повлияет, это отдельная
задача при желании.

Добавляем в `docker-compose.yml` для `site-postgres` флаги, отключающие лишнюю fsync/WAL-дисциплину,
допустимые для эфемерной локальной БД: `fsync=off`, `synchronous_commit=off`, `full_page_writes=off`.

Классификация: 🟡 MEDIUM — не прод (правим только `docker-compose.yml`, не `docker-compose.prod.yml`),
не миграция, не публичный API, но меняем поведение shared-инстанса Postgres, используемого и для
dev, и для test → нужен явный прогон и явное указание риска в отчёте (durability trade-off для
локальной dev-БД на случай грубого сбоя хоста).

Риски / что смотреть ревьюеру:
- `fsync=off` отключает гарантию сохранности данных при неожиданном отключении питания/сбое ОС —
  приемлемо для эфемерной dev/test БД в docker-volume на локальной машине, неприемлемо для прода
  (там `docker-compose.prod.yml`, не трогаем).
- Изменение требует пересоздания контейнера `site-postgres` (`docker compose up -d site-postgres`)
  для применения `command:` — кратковременный обрыв соединений для всего, что сейчас подключено
  к этому Postgres (на момент запуска — только сам контейнер и site-redis, воркеров messenger не
  поднято).

## STOP

Согласно классификации Stage 1 — 🟢 LOW, но так как это единственный источник изоляции для 145
файлов тестов, выполняю с обязательной верификацией через реальный прогон `test:integration` перед
закрытием этапа (a не просто «зелёный self-review на глаз»). Владелец уже дал добро на пункт 1 в
чате — Phase 0 approval получен, приступаю без дополнительной остановки.
