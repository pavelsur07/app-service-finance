## Current checkpoint

**Phase:** handoff PR 1
**Status:** reviewing
**Stage base commit:** bd27418fb (origin/master)

### Completed
- Stage 1: шаблон core.yaml, ридер, ApplyCashflowCoreTemplateAction, подписчик priority −10,
  описания технических статей, тесты, ARCHITECTURE.md.

### Checks and baseline
- Unit ридера — 10 тестов OK; Integration/Company + Functional/Company + системные статьи Cash — 296 OK;
  набор Cash (Unit+Integration+Functional) — 879 OK.
- PHPStan по изменённым файлам — OK; php-cs-fixer — OK после правки phpdoc.

- Stage 2: ai-service принимает description (schemas, контракт v1.2.0), ruff/mypy/unit 178 OK.

### Exact next action
- Финальное ревью PR 1, гейты, Draft PR → Ready.
