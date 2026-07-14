## Stage 1: убрать избыточный TRUNCATE между интеграционными тестами — DONE

**Риск:** 🟢 LOW
**Следующее действие:** continue autonomously (по согласованию с Владельцем — п.1 из анализа)

### Что сделано
- `IntegrationTestCase::resetDb()` по умолчанию стал no-op — изоляция между тестами теперь
  полностью полагается на транзакцию DAMA\DoctrineTestBundle (rollback после каждого теста),
  которая и так была активна (`enable_static_connection: true`), а прежний `TRUNCATE ~140 таблиц`
  выполнялся внутри той же транзакции и терялся при откате — то есть был чистым оверхедом на
  каждый из 145 тестовых файлов.
- Обнаружена и исправлена реальная зависимость от старого поведения: 3 теста-исключения
  (`AdScheduledBatchRepositoryTest`, `AdBatchSchedulerCommandTest`, `InventorySchemaTest`),
  унаследованные от `PostgresResetTestCase` с `#[SkipDatabaseRollback]`, коммитят данные с
  фиксированными UUID напрямую (без rollback) — раньше их за них подчищал общий TRUNCATE в
  setUp() *следующего* теста. После удаления общего TRUNCATE это давало `UniqueConstraintViolationException`
  (`user_pkey` дубликат) в 64 тестах ниже по порядку выполнения.
  Исправлено: `PostgresResetTestCase` теперь чистит БД сам в своём `tearDown()` (до закрытия
  соединения родительским `tearDown()`), не полагаясь на соседей.

### Затронутые файлы
- `site/tests/Support/Kernel/IntegrationTestCase.php` — modified (resetDb() → no-op по умолчанию)
- `site/tests/Support/Kernel/PostgresResetTestCase.php` — modified (добавлен tearDown() с self-cleanup)
- `docs/tasks/integration-tests-speedup/plan.md` — new

### Self-review
- [x] Scope compliance — только tests/Support/Kernel, без правок src/ и миграций
- [x] Patterns / naming — соответствует существующему стилю файлов
- [x] Forbidden actions — none (не legacy-зона, не миграция, не публичный API, не auth, без новых зависимостей)
- [x] Security (companyId, IDOR) — N/A, тестовая инфраструктура
- [x] CS-Fixer (dry-run на изменённых файлах) — чисто, 0 из 2 файлов требуют правок
- [x] PHPStan — недоступен в окружении ([[phpnstan-not-installed]] из памяти), пропущено осознанно
- [x] `composer test:integration` — зелёный, дважды подряд: 695/695, 3334 assertions
- [x] ARCHITECTURE.md — N/A (не Facade/Enum/Entity)

### Измеренный эффект
- До изменения (baseline, `git stash` + прогон): **12m 07.882s**
- После изменения: **1m 38.991s** (повторный прогон: 1m 39.349s — стабильно)
- Ускорение ≈ **7.3×** на `test:integration` (695 тестов)

### Команды для проверки
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:integration`

### Риски / на что обратить внимание ревьюеру
- Если в будущем появится ещё один тест с `#[SkipDatabaseRollback]` вне `PostgresResetTestCase`
  (напрямую на `IntegrationTestCase`), он не получит авто-очистку — self-cleanup специфичен для
  `PostgresResetTestCase`. Стоит держать это в уме при код-ревью новых тестов такого рода.
- `make stan` из CLAUDE.md не существует в этом окружении (см. память `phpstan-not-installed`) —
  self-review по PHPStan пропущен, как и в предыдущих этапах.

### Открытые вопросы
- нет
