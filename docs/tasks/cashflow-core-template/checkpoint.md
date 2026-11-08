## Current checkpoint

**Phase:** handoff PR 1 (#2934)
**Status:** done — ждёт «merge and deploy #2934»
**Stage base commit:** bd27418fb (origin/master)

### Completed
- Stage 1: шаблон core.yaml, ридер, ApplyCashflowCoreTemplateAction, подписчик priority −10,
  описания технических статей, тесты, ARCHITECTURE.md.

### Checks and baseline
- Unit ридера — 10 тестов OK; Integration/Company + Functional/Company + системные статьи Cash — 296 OK;
  набор Cash (Unit+Integration+Functional) — 879 OK.
- PHPStan по изменённым файлам — OK; php-cs-fixer — OK после правки phpdoc.

- Stage 2: ai-service принимает description (schemas, контракт v1.2.0), ruff/mypy/unit 178 OK.

- Гейты: CS/strict-types 0 of 3923, PHPStan OK, PHPUnit 8566 OK; ревью — внутреннее исправлено, Codex REVIEW_GREEN.

### Exact next action
- После деплоя #2934: проверить чек-лист приёмки, затем PR 2 (Stage 3) от master.
