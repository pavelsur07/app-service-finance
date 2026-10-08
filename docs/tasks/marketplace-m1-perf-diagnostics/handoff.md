# marketplace-m1-perf-diagnostics — handoff

**Branch:** `feat/marketplace-m1-perf-diagnostics` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2589 · **CI:** см. PR

## Summary of stages
- Stage 1 (HIGH-LOCAL) — `PerformanceRecorder` и точки замеров 7 этапов (+ `processor_total`, `handler`),
  канал `performance` → `var/log/performance-<дата>.jsonl`, флаг `MARKETPLACE_PERF_DIAGNOSTICS`,
  read-only `app:marketplace:perf-report` со снимком очередей; тесты.
- Stage 2 (LOW/MEDIUM) — документы 08/09/10, строка M1 в 06, ARCHITECTURE.md 1.98, флаг в prod compose,
  `perf-report` в allowlist `codex-console` (репозиторная копия).

## Files changed
51 файл, см. `git diff --stat 5a8c36d9..HEAD`. Код — `site/src/Shared/Infrastructure/Performance/*`,
`site/src/Marketplace/Infrastructure/Performance/*`, `site/src/Marketplace/Command/PerformanceReportCommand.php`,
`site/src/Shared/Infrastructure/Messenger/{MessageCompanyId,MessengerQueueSnapshotQuery}.php`,
`site/src/Shared/Service/Storage/PerformanceObjectStorage.php`; замеры в 12 существующих классах Marketplace
(клиенты Ozon/WB, pipeline-обработчики, `ProcessMarketplaceRawDocumentAction`, Ozon by-day процессоры,
резолверы категорий и себестоимости, `ProcessOzonRealizationHandler`). Конфиг: `monolog.yaml`, `services.yaml`,
`docker-compose.prod.yml`, `site/.env`, `phpstan-baseline.neon` (перенос записи resolve→doResolve).

## Migrations
- нет

## Public API / contract changes
- нет: сообщения, routing, транспорты, raw, схема БД, HTTP API не менялись. Новая read-only команда
  `app:marketplace:perf-report`; новый env-флаг.

## Checks
- baseline `make site-test-unit` — OK (3223) до изменений.
- `make site-stan` — OK; `make site-cs-check` — OK; `make site-cs-strict-types` — OK.
- `make site-test-unit` — OK (3264 tests, 18285 assertions).
- `make site-test` — OK (5680 tests, 32598 assertions) на финальном коммите правок ревью.
- Существующие 266 тестов инструментированных классов — OK с `MARKETPLACE_PERF_DIAGNOSTICS=1` (internal review).
- Локальный e2e: `messenger:consume async_pipeline --limit=1` с флагом → `queue_wait` + `handler` в файле (0666), отчёт.
- Overhead (локально): выкл. ≈107 нс/замер, вкл. ≈670 нс/замер, ≈22 мкс/событие; WB 1500 строк вкл/выкл — в шуме.

## Reviews
- internal (fresh session): 1 итерация; BLOCKER 0, IMPORTANT 2 — исправлены (wrapper allowlist; уведомление о
  сбое записи в stderr вместо warning в буфере fingers_crossed); MINOR исправлены: lag повторов в документах,
  bytes Ozon/WB, `bytes(mixed)`, консольная область, тесты rate-limit/WB-клиента, лимит 300/мин, edge cases flush.
- external (Codex): 1 раунд; BLOCKER 0, IMPORTANT 4: исправлены 3 (замер только сообщений Marketplace/Ingestion;
  парность `transport|trace` со счётчиком попыток; отдельные знаменатели rows/s и bytes/s) — fixed without re-run;
  отклонён 1: `site/bin/capture-wb-inventory.sh` — неотслеживаемый файл Владельца, в коммиты ветки не входит.

## Risks, limitations, follow-ups
- **Флаг включён по умолчанию после деплоя** (`${MARKETPLACE_PERF_DIAGNOSTICS:-1}`). Выключение без релиза —
  host-env `=0` + пересоздание PHP-контейнеров (§3.3); следующий деплой вернёт `1`. FOLLOW-UP: проброс
  `vars.MARKETPLACE_PERF_DIAGNOSTICS` в `deploy.yml` (CI/CD, отдельное согласование).
- **Wrapper `codex-console` на проде** нужно обновить, иначе отчёт и baseline не снять (расширение доступа — §3.3).
- Права на `site_var_log`: если `app` не может писать — замеры не пишутся (строка в stderr), бизнес не страдает;
  проверяется sanity-шагом 3.
- Ограничения замеров — `09-m1-diagnostics.md` §«Ограничения» (Ozon parse ⊂ fetch, WB normalize ⊂ processor_total,
  lag повторов занижен, scheduler не замеряется).
- Наблюдение (гипотеза): локально `financial_mapping` (себестоимость на строку) ≈ 60% шага продаж WB.
- Политика закрытых периодов различается по путям (WB forceRefresh, Ozon realization без `financeLockBefore`) —
  зафиксировано в 08, не менялось.

## Owner decision
Ready: PR #2589 "feat(marketplace): M1 lightweight performance diagnostics" — merge into master with automatic production deploy?
Reply: "merge and deploy #2589"
