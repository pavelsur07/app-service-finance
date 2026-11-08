## Current checkpoint

**Phase:** Stage 2
**Status:** implementing
**Stage base commit:** bd27418fb (origin/master)

### Completed
- Stage 1: шаблон core.yaml, ридер, ApplyCashflowCoreTemplateAction, подписчик priority −10,
  описания технических статей, тесты, ARCHITECTURE.md.

### Checks and baseline
- Unit ридера — 10 тестов OK; Integration/Company + Functional/Company + системные статьи Cash — 296 OK;
  набор Cash (Unit+Integration+Functional) — 879 OK.
- PHPStan по изменённым файлам — OK; php-cs-fixer — OK после правки phpdoc.

### Exact next action
- Stage 2: ai-service `Category.description`, промпт, контракт ai-task/v1, тесты.
