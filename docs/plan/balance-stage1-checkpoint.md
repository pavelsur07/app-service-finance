# Checkpoint: Balance Compliance

**Phase:** Stage 1 + Stage 2 + Stage 3 complete, handoff владельцу для push/PR  
**Status:** done  
**Stage base commit:** `5297dcf4f4a7a0f333e0efb424e89a24337cee3a`  
**Current Work item:** none  
**Owner gate:** yes (push / Draft PR) — действие берёт на себя владелец

### Completed
- Анализ модуля Balance и правил проекта.
- Сохранён аналитический план: `docs/plan/balance-compliance-plan.md`.
- Stage 1: Entity и Repository на `string $companyId`, Application/Action слой, тонкие Controller, миграция снятия FK.
- Stage 2: decimal strings, timestamps, `BalanceStructurePolicy`, `BalanceEquationPolicy`, кастомные исключения.
- Stage 3: ReadModel, Facade, Form, Providers, Twig, тесты (Unit/Integration/Functional).
- Исправления по результатам проверок: интерфейс репозитория, in-memory репозиторий для тестов, корректировка теста timestamps, миграция восстановления FK и длин enum-колонок, trailing slash в functional URL.
- Все Balance-проверки зелёные; полный `make site-test-unit` зелёный (кроме flaky pre-existing теста Marketplace).
- Коммиты `15dabd37`, `286e9800` созданы в ветке `balance-compliance`.

### Current diff / affected files
Все task-owned изменения закоммичены. В рабочей копии остались несвязанные файлы:
- `docs/plan/my_paln_app.md` — изменён до работы
- `docs/plan/skills.md` — не task-owned
- `App\Marketplace\Wildberries\Message\WbFinanceReportImportMessage,` — pre-existing untracked
- `Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport` — pre-existing untracked

### Checks and baseline
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter Balance` — OK (16 tests, 37 assertions)
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite integration --filter Balance` — OK (21 tests, 113 assertions)
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite functional --filter Balance` — OK (8 tests, 61 assertions)
- `make site-test-unit` — OK (1888 tests, 10778 assertions), 1 flaky pre-existing failure `WbFinanceSalesReportClientTest`
- `doctrine:schema:validate` — Balance-таблицы синхронны; оставшийся diff pre-existing
- `doctrine:migrations:migrate --no-interaction` — успешно применены все миграции

### Review status
- iteration: 1
- unresolved findings: none
- External Claude Code review заменён самостоятельным review по инструкции владельца.

### Exact next action
- Владелец запушит ветку `balance-compliance` и создаст Draft PR с base `master`.

### Handoff notes
- Не включать в коммит/PR: `docs/plan/my_paln_app.md`, `docs/plan/skills.md`, `App\...`, `Symfony\...`.
- При создании PR через `gh pr create` явно указать `--base master`.

### Files to inspect first on resume
- `docs/plan/balance-stage-report.md`
- `site/src/Balance/Entity/BalanceCategory.php`
- `site/src/Balance/Domain/Policy/BalanceStructurePolicy.php`
- `site/migrations/Version20260814083741.php`
