# Handoff: ускорение site/tests/Integration

## Задача

Бриф от Владельца: проанализировать `site/tests/Integration` и предложить best practices по
ускорению прохождения тестов, затем реализовать одобренные пункты. Владелец остановил работу
после Stage 2, финальный handoff — по его запросу.

## Итог

| Момент | Время `test:integration` (695 тестов) |
|---|---|
| Baseline (до вмешательства) | 12m 07.9s |
| После Stage 1 | 1m 38–39s |
| После Stage 2 (текущее состояние) | ~50–53s |

**Суммарное ускорение ≈ ×14**, без новых зависимостей, без изменений в `src/`, без миграций,
без изменений публичного API.

## Что сделано по этапам

### Stage 1 (🟢 LOW) — убран избыточный TRUNCATE между тестами

- `phpunit.xml` уже подключает `DAMA\DoctrineTestBundle` (`enable_static_connection: true`) —
  каждый тест оборачивается в транзакцию с rollback. Но `IntegrationTestCase::setUp()`
  дополнительно гонял `DbReset::reset()`: интроспекция `information_schema` + `TRUNCATE ~140 таблиц
  RESTART IDENTITY CASCADE` на **каждый** тест — работа выполнялась впустую внутри той же
  DAMA-транзакции.
- `IntegrationTestCase::resetDb()` стал no-op по умолчанию.
- Побочно обнаружена и исправлена реальная зависимость: 3 теста-исключения
  (`PostgresResetTestCase`, `#[SkipDatabaseRollback]`) коммитят данные с фиксированными UUID
  напрямую (без rollback) — раньше их подчищал общий TRUNCATE в setUp() *следующего* теста. После
  удаления общего TRUNCATE это дало 64 упавших теста (`UniqueConstraintViolationException` на
  `user_pkey`). Исправлено: `PostgresResetTestCase` теперь чистит за собой сам, в своём
  `tearDown()`.
- Эффект: 12m07.9s → 1m38-39s (×7.3).
- Коммиты: `7d237cd0` (код), `14aff0be` (Stage Report).

### Stage 2 (🟡 MEDIUM) — Postgres tuning для локального test-инстанса

- Проверено окружение перед стартом: 2 vCPU, ~1.2Gi свободного RAM. При таких ресурсах paratest
  (изначальный п.2 из анализа) дал бы реалистично ×1.5–2, а не линейный рост, плюс требовал бы
  новую dev-зависимость (`composer require` → обязательный STOP по CLAUDE.md) и отдельные тестовые
  БД на воркер. Владелец через `AskUserQuestion` выбрал начать с более дешёвого и безопасного
  пункта — тюнинга Postgres.
- В `docker-compose.yml` для `site-postgres` (единственный Postgres-контейнер, используется и для
  dev-БД `app`, и для test-БД `app_test`) добавлен
  `command: postgres -c fsync=off -c synchronous_commit=off -c full_page_writes=off`.
- Эффект: 1m38-39s → ~50-53s (доп. ×1.9).
- Коммиты: `e29dbf2a` (код), `0e984995` (Stage Report).

## Список миграций БД

Нет. Ни одна из правок не затрагивает схему БД.

## Изменённые публичные контракты

Нет. Изменения ограничены тестовой инфраструктурой (`site/tests/Support/Kernel/*`) и локальной
docker-инфраструктурой (`docker-compose.yml`, только `site-postgres.command`). API, маршруты,
структура ответов, Facade/Enum — не затронуты. `ARCHITECTURE.md` обновлять не требовалось.

## Список изменённых файлов (весь диапазон работы)

```
docker-compose.yml                                       | +5
docs/tasks/integration-tests-speedup/plan.md             | new
docs/tasks/integration-tests-speedup/stages/stage-1.md   | new
docs/tasks/integration-tests-speedup/stages/stage-2.md   | new
site/tests/Support/Kernel/IntegrationTestCase.php        | modified (resetDb() → no-op по умолчанию)
site/tests/Support/Kernel/PostgresResetTestCase.php       | modified (self-cleanup в tearDown)
```

## Риски

1. **Durability локального Postgres.** `fsync=off` действует на весь `site-postgres`-контейнер
   (общий для dev и test БД) — при аварийном отключении питания/сбое ОС хоста локальная БД `app`
   может повредиться, потребуется пересоздание через `doctrine:migrations:migrate` +
   `doctrine:fixtures:load`. Прод не затронут — правки только в `docker-compose.yml`, не в
   `docker-compose.prod.yml`.
2. **Изоляция для будущих `#[SkipDatabaseRollback]`-тестов.** Если появится новый тест с этим
   атрибутом не через `PostgresResetTestCase` (а напрямую на `IntegrationTestCase`), он не получит
   авто-очистку commit-мусора — self-cleanup специфичен для `PostgresResetTestCase`. Стоит держать
   в уме на код-ревью новых тестов такого рода.
3. **CI не ускорен.** GitHub Actions (`.github/workflows/deploy.yml`) поднимает Postgres отдельным
   service-контейнером напрямую из образа, никак не связанным с `docker-compose.yml` — Stage 2 на
   CI не действует. Ускорение CI — отдельная задача при желании (нужен другой механизм: кастомный
   образ Postgres с встроенным `command`, либо принятие текущего CI-времени как есть).

## Follow-ups (сознательно вынесены за scope, не сделаны)

1. **Paratest.** На этой машине (2 vCPU) даст реалистично ×1.5–2, требует новую dev-зависимость
   (STOP по CLAUDE.md) и отдельные тестовые БД/схемы на воркер. Учитывая уже достигнутый ×14,
   Владелец решил остановиться и не продолжать на этом этапе.
2. **`$connection->close()` / `ensureKernelShutdown()` в `IntegrationTestCase::tearDown()`** —
   мелкая косметика (дублирование того, что и так делает `parent::tearDown()`), не тронуто,
   отдельный LOW-этап при желании.
3. **CI-тюнинг Postgres** — не сделан, см. «Риски», п.3.

## Проверки перед handoff

- `composer test:integration` — 695/695 зелёные (дважды подряд после Stage 1, дважды подряд после
  Stage 2).
- `composer test:unit` — 1427/1427 зелёные, регрессий не внесено.
- `composer test` (полный набор: unit + integration + functional) — прогнан перед финальным
  handoff: **OK (2338 tests, 13314 assertions)**, Time: 02:33.632. Регрессий нет.
- CS-Fixer (dry-run) на изменённых PHP-файлах — чисто.
- PHPStan (`make stan` из CLAUDE.md) — недоступен в этом окружении (см. память проекта
  `phpstan-not-installed`), self-review по этому пункту пропущен, как и в предыдущих задачах
  этого проекта.

## 🛑 Final Owner review

Работа остановлена по явному запросу Владельца после Stage 2. Merge в основную ветку не
требуется отдельно — коммиты уже сделаны напрямую в `master` (текущий рабочий процесс проекта,
без отдельных feature-веток/PR для этой задачи). Требуется только финальное одобрение содержания
Владельцем.
