## Current checkpoint

**Phase:** Stage 1
**Status:** done
**Stage base commit:** `2520183b41244058644642461aa93ffeffb92737`
**Current Work item:** 1.4
**Owner gate:** no

### Completed

- Добавлен `Company.minimumBalance` как неотрицательный shared `Money` с default `0 RUB`.
- Добавлены миграция, редактирование в форме компании и пользовательские ошибки валидации.
- Миграция проверена циклом up/down/up; mapping column names закреплены integration-тестом.
- Основной dirty worktree и пользовательские файлы не затронуты.

### Current diff / affected files

- `site/src/Company`, `site/src/Shared/Form/Type/MoneyValueType.php`.
- `site/templates/company`, `site/migrations/Version20260822121000.php`.
- Targeted unit/integration/functional tests and task/architecture docs.

### Checks and baseline

- Baseline `CompanyEntityTest`: 8 tests, 12 assertions — green.
- Targeted Stage 1 suite after review fixes: 183 tests, 611 assertions — green; 1 pre-existing deprecation.
- Twig lint and Doctrine mapping validation — green.
- Migration up/down/up — green; task columns absent from schema update diff.
- PHP CS Fixer for changed PHP files and `git diff --check` — green after review fixes.

### Review status

- iteration: 3
- internal review: overflow exception handling fixed; no unresolved BLOCKER/IMPORTANT.
- external review iteration 1: two IMPORTANT findings; both fixed (visible form errors and mapping guard).
- external review iteration 2: `REVIEW_GREEN`; one safe input-robustness MINOR fixed afterwards.
- external review iteration 3: `REVIEW_GREEN` on the final Stage diff.
- unresolved findings: none.

### Exact next action

- Commit/push Stage 1, create or update the Draft PR, then start Stage 2.

### Files to inspect first on resume

- `site/src/Shared/Form/Type/MoneyValueType.php`
- `site/tests/Functional/Company/CompanyCreateFlowTest.php`
- `site/tests/Integration/Company/CompanyMinimumBalanceMappingTest.php`
- `docs/tasks/ui-dashboard/stages/stage-1.md`
