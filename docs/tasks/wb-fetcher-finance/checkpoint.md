## Current checkpoint

**Phase:** Stage 3 / шаг 0 — проверка period=weekly
**Status:** blocked on real WB token check
**Stage base commit:** 2628390ae

### Completed
- Phase 0: plan.md, stages/stage-0..5.md, решения Владельца В1 (S3 — тот же пользователь/бакет, префикс) и В2 (план этапа перед работой)
- Stage 0 (fetchkit): 284bc13e4 … 43846f0df; внешнее ревью — раунд 1 IMPORTANT исправлен, раунд 2 REVIEW_GREEN
- Stage 1 (Symfony, provider=wildberries): eeeceaa73; внешнее ревью REVIEW_GREEN
- Stage 2 (wb-fetcher, дневной отчёт): 04d328aa7, 2628390ae; стенд с моком пройден

### Checks and baseline
- Go: unit -race fetchkit/ozon-fetcher зелёные; интеграционные PG15 зелёные (1 SKIP — S3, как на базе); golangci-lint 0
- PHP: Marketplace unit 1040 / functional 267 зелёные; PHPStan/CS по изменённым файлам — ок
- wb-fetcher: unit + интеграционный PG15 зелёные, golangci-lint 0, образ собирается

### Review status
- Stage 0: internal + Codex green; Stage 1: Codex green

### Exact next action
- Stage 3: детальный план stage-3.md; шаг 0 — один запрос period=weekly реальным токеном WB (прод, read-only), до кода

### Окружение (снести в конце)
- контейнеры vf-fetchkit-pg (сеть vf-fetchkit-test), asfwb-pg + asfwb-redis (сеть asfwb-net); worktree scratchpad/base; стенд wbstand-* снесён

### Files to inspect first on resume
- docs/tasks/wb-fetcher-finance/stages/stage-3.md
