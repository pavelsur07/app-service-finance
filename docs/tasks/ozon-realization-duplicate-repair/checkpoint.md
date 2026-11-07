## Current checkpoint

**Phase:** planning only
**Status:** planned
**Stage base commit:** не назначен; код ещё не менялся.

### Completed
- Выполнена read-only диагностика; исходные замеры записаны в TASK.md.
- Подготовлен plan.md: две Stage, локальные регрессии и отдельный production-runbook.
- Производственные изменения и реализация плана не запускались.

### Checks and baseline
- `git diff --cached --check` — PASS.
- Проверка 7 существующих source/test paths, 3 task docs и 5 денежных равенств — PASS.
- Baseline PHP/интеграций запустить в Phase 0 будущей реализации.

### Review status
- internal: самопроверка и независимая проверка плана в свежем контексте;
  BLOCKER 0 / IMPORTANT 0. Уточнена инвалидация кэша после внешнего commit.
  Review реализации неприменим: код не менялся.
- external: не требуется для текущего изменения документации, 0 раундов.

### Exact next action
- По отдельному поручению на реализацию: прочитать TASK.md/plan.md,
  обновить замеры при необходимости, выполнить Phase 0 на ветке реализации.
- Production execute и расширение wrapper требуют отдельных named approvals.

### Files to inspect first on resume
- `docs/tasks/ozon-realization-duplicate-repair/TASK.md`
- `docs/tasks/ozon-realization-duplicate-repair/plan.md`
- `site/src/Marketplace/Ozon/Application/Action/ProcessOzonRealizationAction.php`
- `site/src/Marketplace/Infrastructure/Query/MonthCloseAdvisoryLockQuery.php`
- `site/src/Marketplace/Application/ReopenMonthStageAction.php`
