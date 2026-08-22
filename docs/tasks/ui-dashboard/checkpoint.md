## Current checkpoint

**Phase:** Stage 2
**Status:** done
**Stage base commit:** `db4e36b491b735dd1b6d63f7f903cfd21764bd8b`
**Current Work item:** 2.5
**Owner gate:** no

### Completed

- Stage 1 committed as `db4e36b4`; Draft PR #2361 targets `master`.
- Добавлен `Company.minimumBalance` как неотрицательный shared `Money` с default `0 RUB`.
- Добавлены миграция, редактирование в форме компании и пользовательские ошибки валидации.
- Миграция проверена циклом up/down/up; mapping column names закреплены integration-тестом.
- Основной dirty worktree и пользовательские файлы не затронуты.
- Stage 2 request/response DTO, invokable endpoint, DBAL series query and provider implemented.
- Balance carry-forward/opening fallback, split directions and dashboard ДДС exclusions covered.

### Current diff / affected files

- `site/src/Finance/{Controller/Api/BalanceDynamics,Application/Service,Infrastructure/Query}`.
- New unit/integration/functional balance-dynamics tests and architecture/task docs.

### Checks and baseline

- Baseline `CompanyEntityTest`: 8 tests, 12 assertions — green.
- Targeted Stage 1 suite after review fixes: 183 tests, 611 assertions — green; 1 pre-existing deprecation.
- Twig lint and Doctrine mapping validation — green.
- Migration up/down/up — green; task columns absent from schema update diff.
- PHP CS Fixer for changed PHP files and `git diff --check` — green after review fixes.
- Stage 2 targeted plus existing dashboard reconciliation: 14 tests, 127 assertions — green.
- Stage 2 PHP CS Fixer, route discovery and container lint — green.
- Full relevant Finance/ДДС/module-access set: 122 tests, 1573 assertions — green; 1 pre-existing deprecation.

### Review status

- iteration: 3 for Stage 2
- Stage 1 external review: `REVIEW_GREEN`.
- internal Stage 2 review: aggregate overflow conversion and pre-opening false threshold signal removed.
- external Stage 2 review iteration 1: no green marker; one confirmed IMPORTANT fixed.
- external Stage 2 review iteration 2: `REVIEW_GREEN`; safe inactive-account coverage/documentation MINOR fixed afterwards.
- external Stage 2 review iteration 3: `REVIEW_GREEN` on the final Stage diff.
- rejected conditional findings: both source columns are PostgreSQL `date NOT NULL`, verified through `information_schema`.
- unresolved findings: none.

### Exact next action

- Commit/push Stage 2, update Draft PR #2361, then start Stage 3.

### Files to inspect first on resume

- `site/src/Finance/Infrastructure/Query/BalanceDynamicsQuery.php`
- `site/src/Finance/Application/Service/FinanceBalanceDynamicsProvider.php`
- `site/src/Finance/Controller/Api/BalanceDynamics/GetBalanceDynamicsController.php`
- `site/tests/Integration/Finance/Application/Service/FinanceBalanceDynamicsProviderTest.php`
- `docs/tasks/ui-dashboard/stages/stage-2.md`
