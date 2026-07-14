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

## STOP

Согласно классификации Stage 1 — 🟢 LOW, но так как это единственный источник изоляции для 145
файлов тестов, выполняю с обязательной верификацией через реальный прогон `test:integration` перед
закрытием этапа (a не просто «зелёный self-review на глаз»). Владелец уже дал добро на пункт 1 в
чате — Phase 0 approval получен, приступаю без дополнительной остановки.
