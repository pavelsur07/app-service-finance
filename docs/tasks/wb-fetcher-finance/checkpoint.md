## Current checkpoint

**Phase:** закрыта 03.11.2026 — на проде (#2861, #2862), все кабинеты WB
**Status:** done; остался замер первой ночи 04.11
**Stage base commit:** 62dda3879 (ветка), этап 5 — e2dc136ff, правки ревью — d84d68ffd

### Completed
- Phase 0: plan.md, stages/stage-0..5.md, решения Владельца В1 (S3 — тот же пользователь/бакет, префикс) и В2 (план этапа перед работой)
- Stage 0 (fetchkit): 284bc13e4 … 43846f0df; внешнее ревью — раунд 1 IMPORTANT исправлен, раунд 2 REVIEW_GREEN
- Stage 1 (Symfony, provider=wildberries): eeeceaa73; внешнее ревью REVIEW_GREEN
- Stage 2 (wb-fetcher, дневной отчёт): 04d328aa7, 2628390ae; стенд с моком пройден
- Stage 3 (недельный отчёт, сверка): реальная проба period=weekly тестовым токеном Владельца (токен нигде не сохранён); контракт service-coverage v1.1.0 (574df6063)
- Stage 4 (вкладки Ozon | Wildberries, многосервисное покрытие): визуальная проверка на стенде пройдена (21dfcb7fb)
- Stage 5 (прод за WB_FETCHER_ENABLED): e2dc136ff; замер «до» 27.10–02.11 — 447 / failed 0 / retried 0
- Ревью handoff: site — REVIEW_GREEN; services — раунд 1 BLOCKER (пагинация) + 2 IMPORTANT (общий лимит с Symfony, память) исправлены в d84d68ffd (квота wb-finance-quota v1), раунд 2 — REVIEW_GREEN, 2 MINOR закрыты

### Checks and baseline
- Go: unit -race fetchkit/ozon-fetcher зелёные; интеграционные PG15 зелёные (1 SKIP — S3, как на базе); golangci-lint 0
- PHP (02.11, handoff): полный набор 7932 — 1 падение (новые маршруты без ROUTE_POLICY), исправлено, гейт зелёный; unit 4383 OK; CS всех файлов 0; strict_types 0; PHPStan по изменённым файлам 0. Полный PHPStan локально: только «unmatched ignore» baseline в файлах вне ветки (локальное окружение; master на merge-base в CI зелёный)
- wb-fetcher: unit + интеграционный PG15 зелёные, golangci-lint 0, образ собирается

### Review status
- Stage 0: internal + Codex green; Stage 1: Codex green; handoff (этапы 2–5): Codex green со 2-го раунда

### Exact next action
- 04.11: `perf-report` за ночь (stage-5.md §4), сверка Вумджой за 14 дней; витрина — Владелец

### Окружение (снести в конце)
- снесено 03.11: vf-fetchkit-pg, asfwb-*, worktree scratchpad/base, стенд wbstand-*

### Files to inspect first on resume
- docs/tasks/wb-fetcher-finance/stages/stage-5.md
