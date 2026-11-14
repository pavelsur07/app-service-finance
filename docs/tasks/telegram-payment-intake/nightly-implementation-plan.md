# PaymentIntake — план ночного контроля и границ источников

Для исполнения: executing-plans; независимые участки — subagent-driven-development.
Spec: [nightly-quality-plan.md](nightly-quality-plan.md). Владелец согласовал
реализацию, generic PaymentIntake,30RUB/night900RUB/month и workflow новых данных.
Это расширение текущей Large-задачи, одна ветка и DraftPR2985, не новый сервис.

## Phase 0 и общие ограничения

Существующий checkout feature-ветки docs/telegram-payment-intake-examples;
master не редактируем. Owner changes AGENTS.md/CLAUDE.md и прочие чужие файлы
исключаются из commits. Предыдущие4livepasses завершены, старое root-SSH
исключение не переносится на постоянный монитор. Merge/deploy/migrations
production отдельно в финальном запросе, без промежуточных approval gates.

Образцы: Python store/tasks.py+worker/worker.py (durable claim/fencing),
ServiceGateway SubmitAiTaskHandler+ImportAiResultsAction+AiServiceClient
(short command/async/pull), supercronic docker/cron/app.cron и health-gates.
Generic technical-job API нет; business ai-task с фиктивной компанией не используем.
Baseline: Python89PASS, Cash/Telegram66tests434assertionsPASS на неизменном99cf27cde6.
Полные handoff gates не повторять после каждого work item.

Стоимость — Decimal/numeric, периодыEurope/Moscow, day30/month900, max82calls/job.
Резерв до внешнего запроса; settlement после известной usage; неизвестный расход
не освобождается по lease timeout. Никаких бизнесопераций из eval. No newframework,
новых ключей, auto prompt/gold/model changes или auto-disable v1.

## Stage 6 — нейтральный source и runtime datasets

Risk HIGH-LOCAL. Stage base записать из текущего checkpoint commit до кода.
DoD: Cash принимает opaque sourceKey, Telegram adapter сохраняет старые keys/
reply receipts; тестовый второй source не зависит от Telegram IDs. Runtime pack
валидируется без checkout mounts; curated data workflow не смешивает gold с model.

### 6.1 Source boundary

- RED: generic web/email source can request/replay same draft; different source
  cannot collide; company/actor pin unchanged; malformed key rejected.
- RequestPaymentIntakeCommand: companyId,actorUserId,moneyAccountId,sourceKey,text,
  receivedAt,sourceRevision='original',timezone='Europe/Moscow'.
- PaymentIntakeAction.request uses command.sourceKey, generic getBySource(sourceKey,
  actorUserId), hasSource(sourceKey, optional replyIdempotencyKey). Generic key
  required nonempty/max255/no control characters; no financial-rule changes.
- Move exact existing Telegram key validation/build into Telegram adapter helper;
  old bot/chat/message key bytes and replyIdempotencyKey hashing preserved.
- Update CashFacade and all consumers/tests; no entity/migration needed.
- Files: Cash DTO/Action/Facade; Telegram PaymentIntakeTelegramAction,
  CreateTelegramCashTransactionAction and source helper; focused PHP tests.

### 6.2 Runtime assets и новые примеры

- RED: installed package/assets without docs/_contracts checkout loads source41,
  semantic12; tampered/missing manifest/schema/fixture rejected before any adapter.
- New src/ai_service/evals/payment_intake_assets.py; exporter/check command,
  immutable generated evals/parse_payment_message/runtime pack plus manifest.
- Reuse existing load_holdout/semantic validation/scorer, no second runner.
  Source originals remain canonical; generated snapshot has input digests and
  --check detects drift. AI_SERVICE_EVALS_DIR resolves deployed asset directory.
- payment_intake.py load/manifest paths use pack at runtime; tests keep original
  partial/source vs synthetic semantics and wrong-intent guards.
- Curated additions: JSONL template, reviewed dataset index and offline validation;
  only reviewed entries included; explicit gold keys/IDs/source anchors validated.
  Legacy synthetic12 remains reproducible. No automatic gold from model/user edits.
- Define asset API before wiring: load_pack(root:Path|None=None) returns datasets
  mapping source/semantic and verified manifest. Export/check use canonical files.
- Assets implementation owns loader/exporter + runner path/manifest adaptations;
  root edits same runner only after ownership released for Stage7 budget hooks.

Stage checks: modulePython and targetedPHP, focusedlint/Stan, fullStage diff review;
external if HIGH-LOCAL diff>500, StageReport/commit/push/DraftPR, continue.

## Stage 7 — durable technical eval job и бюджет

Risk HIGH-LOCAL. DoD: authenticated API enqueues unique daily technical run,
separate process sameimage executes it, all real calls have atomic reserve/settle,
metadata-only result and honest terminal status. Tests before implementation.

### 7.1 Contract и store

- services/_contracts/ai-eval-run/v1: POST /internal/eval-runs (suite payment_intake,
  run_id/run_date/trace_id/idempotency),202; GET byrunid and latestsafe status.
  No company/business payload, request-provided models/prices/paths/limits forbidden.
- Migration0002_eval_runs.sql: technical run, periodbudget, callreservation tables.
- store/evals.py: enqueue/hash/unique(suite,run_date), claim SKIPLOCKED/lease/
  attemptfencing, finalreport immutable. Stale previous-day run expires withoutcalls.
- MonththenDay locks in one transaction; beforecall check spent+reserved+new<=limit,
  max82 durablecalls/run. Idempotent settlement. Leaseexpiry retains unknown cost;
  incomplete run never automatically restarts previouslypaid cases.
- RED integration: duplicate/conflictingenqueue, concurrentclaim/reserve, month
  boundaryMSK, day30/month900, unknown/cancelledsettlement, fenceafterlease,
  stalejob, incomplete/no fakegreen. No network inside DB transaction.

### 7.2 Executor и worker

- evals/payment_intake_nightly.py + evals/worker.py, command eval-worker in __main__.
  Dedicatedprocess same existingimage; businessworker unchanged/unblocked.
- Existing evaluate/Pipeline/scorer; source41/semantic12+approvedcurated pack.
  BoundedAdapter opt-in reserve/settle known usage keeps legacy conservative default.
  Durable adapter enforces bothdatasets/day/month together and heartbeat/fencing.
- Run includes deployedcode/prompt/schema/asset/scorer hashes, safe perfield counts,
  latency/cost/providererrors; never raw inputs/outputs/financial values in logs.
- Terminal PASSED only complete coverage and expected metrics; FAIL quality,
  UNAVAILABLE provider, INCOMPLETE crash/lease/assets, SKIPPED_BUDGET distinct.
- RED tests fakeadapter: correct/goldmiss/wrongintent/provider failure, unknownusage,
  budgetstop after successfulcalls, deadline/no retryuntilgreen, missing pack.
- Stage checks Python module/integration/lint then review/report/commit/push.

## Stage 8 — Symfony scheduler/pull/health и выпуск

Risk HIGH-LOCAL; namednightjob/config scope owner-approved, actualproductionrelease
still awaits single finalmerge/migration/deploy decision. DoD: shortscheduled
commands enqueue/pull, no longHTTP/LLM in scheduler; nightlylastcomplete visible,
freshness/quality/provider/budget states distinct, existingGlitchTipaggregatedalerts.

- Symfony ServiceGateway: typed client/parser/DTO, technicalrun journal/readmodel,
  short request/pull/health commands. New Symfony additive migration if needed.
- Schedule proposal03:00MSK and bounded statuspull through existing supercronic;
  existing transport/handler only if needed (shortPOST fits commandtimeout).
  Keep technicalscope independent of companyAI grants/absence of companies.
- docker-compose dev/prod: separate eval-worker sameimage; env daily30/month900,
  flag defaultoff until releaseexplicitconfig; no additionalrootcron/SSH.
- Contract tests, schedulerrequestreplay/pullupsert/absence/FAIL/UNAVAILABLE/
  SKIPPED_BUDGET/freshness, oneaggregatedalert/recovery, no Cash sideeffects.
- Runtimeimageoffline smoke, stagedadaptersstub, realnightcall only through
  approved deployednightmechanism, not a fifthrootbenchmark.
- Fullhandoffgates once, freshfulltaskinternalreview+external, fixcycles,
  reports/runbook/PRReady and one combinedreleasequestion with all migrations/
  worker/scheduler actions named. No financialthresholdguessing or productionwrites.

## Review focus

1. Paid call after processdeath/leasehandover — unknownreservation persists, no replay.
2. MSK month boundary + twojobs — sharedmonth/day lock order and budget acrossdatasets.
3. Partial/missingruntimepack — no adaptercalls and no falsePASS.
4. LegacyTelegram source bytes and replyguards — sourcegenericity must not defeat pin.
5. Newgold contamination — reviewed/immutable versions and separate unseenholdout;
   incomplete annotations never credited as correctnull.
