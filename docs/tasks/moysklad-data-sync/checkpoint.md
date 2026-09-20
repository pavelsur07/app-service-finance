## Current checkpoint

**Phase:** Stage 3 / full sync and Messenger
**Status:** ready to start
**Stage base commit:** `421908c2334ad25abba86057e0ed452838a263ad`

### Completed

- Создан изолированный worktree `feat/moysklad-counterparty-sync` от `origin/master`.
- Прочитаны Stage 0, существующий модуль, паттерны и правила проекта; составлен план четырёх Stage.
- Work item 1.1: три Entity, snapshot DTO, tenant-scoped Repository и builder'ы; unit и integration тесты прошли.
- Work item 1.2: миграция трёх таблиц с FK, индексами и проверками; свежая test-БД применяет все 262 миграции; запрет удаления подключения проверен.
- Внутреннее ревью Stage 1: добавлен индекс по `connection_id` для FK истории запусков и проверка диапазона индексов builder'ов.
- Внешнее ревью Stage 1: 0 BLOCKER, 2 IMPORTANT, 4 MINOR; подтверждённые замечания исправлены, uppercase UUID подтверждён тестом. Исправления проверены 81 тестом / 346 assertions, PHPStan и CS Fixer. Stage Report закрыт.
- Stage 1 committed as `421908c2`, pushed; Draft PR #2495 targets `master`.
- Stage 2: API page reader, safe categories and parser based on sanitized fixtures. 77 unit tests / 325 assertions, PHPStan and CS Fixer PASS; internal review fixed malformed empty-object rows. Stage Report closed.

### Checks and baseline

- `make site-composer-install` — PASS.
- `docker compose run --rm site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/MoySklad` — PASS, 46 tests / 227 assertions.
- `tests/Integration/MoySklad` на baseline — PASS, 16 tests / 60 assertions.
- `make site-test-db-rebuild` с изолированным Compose override — PASS, 262 миграции.
- `tests/Unit/MoySklad tests/Integration/MoySklad` — PASS, 72 tests / 324 assertions.
- Focused PHPStan — PASS, no errors; focused PHP CS Fixer — PASS, 0 fixable files; Doctrine mapping — PASS.
- Полный `doctrine:schema:validate` — red: общий schema diff включает сотни существующих расхождений и намеренные ручные FK; SQL новых таблиц и индексов проверен отдельно.

### Review status

- internal: Stage 1 iteration 1, open BLOCKER/IMPORTANT none; 1 IMPORTANT and 1 MINOR fixed.
- external: Stage 1 fixed without re-run; handoff review pending.

### Exact next action

- Commit/push Stage 2, обновить Draft PR; затем Stage 3: сначала тесты первичной загрузки, сбоя страницы и повторного прохода, затем Action/Messenger.

### Files to inspect first on resume

- `docs/tasks/moysklad-data-sync/plan.md`
- `docs/tasks/moysklad-data-sync/checkpoint.md`
- `site/src/MoySklad/Entity/MoySkladConnection.php`
- `site/migrations/Version20260920110000.php`
