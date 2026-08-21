# Stage 3 Report — manual lifecycle UI and release candidate

- Risk: MEDIUM
- Base commit: `35f637a03e34fbe5d35d063cf382262a59887108`
- Result: ручное удаление операций ОПиУ использует soft delete; удалённые документы доступны на отдельной tenant-scoped вкладке и могут быть восстановлены.

## Delivered

- `DocumentController::delete()` вызывает `SoftDeleteDocumentAction`, сохраняет actor/reason и больше не удаляет документ физически.
- Добавлены company-scoped GET `/documents/deleted` и защищённый `FINANCE_WRITE` + CSRF POST `/documents/{id}/restore`.
- В основном и удалённом списках добавлены tabs «Операции ОПиУ | Удалённые»; удалённая страница поддерживает 20/30/50 pagination, empty state и восстановление.
- Удаление и восстановление показывают success/error flash; доменная ошибка недостаточной ёмкости ДДС не скрывается.
- Deleted pagination получила стабильную сортировку `deleted_at DESC, id ASC`.
- Архитектурная документация фиксирует различие manual soft delete и Marketplace hard delete.

## Checks

- Stage functional test: 6 tests, 45 assertions — green.
- Post-review lifecycle/UI regression: 11 tests, 65 assertions — green.
- Final expanded Finance + security suite: 149 tests, 696 assertions — green.
- PHP syntax: green for all 16 task-owned PHP files.
- PHP CS Fixer dry run: green for all task-owned PHP files.
- Symfony container lint: green.
- Symfony Twig lint for `templates/document`: green.
- TwigCS for the new deleted template: green. Repo-wide TwigCS remains red with pre-existing violations, including the legacy document index.
- Route inspection: deleted list GET and restore/delete POST routes resolve as expected.
- Doctrine mapping: green. Task migration is current in the isolated test database (241/241); repo-wide schema validation retains unrelated pre-existing drift, and its SQL dump contains no soft-delete schema changes.
- `git diff --check`: green.

## Reviews

- Internal task-wide review: green.
- External Claude review: deterministic deleted-list pagination MINOR fixed with an ID tie-breaker.
- External repeat review: `REVIEW_GREEN`.

## Recorded follow-ups

- Cash allocation writes, including the pre-existing edit path, are not serialized across concurrent requests. A broader locking/versioning decision is outside this task.
- `CashTransaction::hasViolatedDocument` is not re-derived during document soft delete/restore; this may leave a cosmetic warning after deletion and is outside the requested lifecycle behavior.

## Operational notes

- No production/staging database, deployment, merge, or other Production Gate action was executed.
- The owner workspace changes outside `pnl-document-soft-delete` were not staged or modified by this task.
