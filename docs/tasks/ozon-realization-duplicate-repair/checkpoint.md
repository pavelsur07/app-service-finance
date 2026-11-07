## Current checkpoint

**Phase:** Stage 2 / checks + handoff
**Status:** implementing
**Stage base commit:** Stage 1 `6a6f7d945`; Stage 2 `61383f407`.

### Completed
- Phase 0: локальная БД подготовлена, baseline 2 tests / 8 assertions PASS.
- Stage 1: общий PostgreSQL lock, атомарная обработка, refresh raw после ожидания,
  guards финального/финансово заблокированного периода, транзакционное переоткрытие.
- RED: обе гонки дали 6 вместо 3 строк / удвоенные суммы; четыре regression tests
  показали 250 сохранённых строк после сбоя и отсутствие защит периода/header.
- GREEN: Marketplace integration + logging unit 559 tests / 2505 assertions;
  дополнительные concurrency boundaries 4 tests / 42 assertions.
- Focused PHPStan изменённых Actions и новых integration tests — No errors.
- Focused CS исправил 2 файла; финальная проверка перед commit Stage 1.

### Review status
- Stage 1 internal: BLOCKER 0 / IMPORTANT 0; safe local findings fixed.
- External: не требуется на Stage 1 (<500 lines); обязательно на handoff Large task.
- Полные gates ещё не запускались: только на handoff.

### Rulings
- Продолжить branch/PR #2913: один task/branch/PR, без второго PR реализации.
- Сохранён no-op пустого raw без header; непустой raw требует корректного header.
- Company-scoped lookup возвращает generic not-found для чужого raw; tenant test обновлён.
- Focused PHPStan старого logging unit обнаружил pre-existing missingType.iterableValue;
  source/new integration checks чистые, full handoff gate проверит штатный scope.
- Repair execute потребует expected raw hash из dry-run: флаг --expected-raw-hash,
  чтобы изменение источника между dry-run и execute не было незаметным.

### Exact next action
- Stage 1 committed/pushed: 61383f407.
- Stage 2 реализован; focused tests 8 / 43 PASS, focused PHPStan No errors.
- Дождаться module run (session 22158), then unit/full handoff gates; fresh review.
- Handoff static gates running: stan 38928; CS 99413; strict 4964.
- Полные test gates пока не запускались.
- Production backup/wrapper/execute НЕ одобрены и не запускались.

### Files to inspect first on resume
- TASK.md и plan.md в этой директории.
- site/src/Marketplace/Ozon/Application/Action/ProcessOzonRealizationAction.php
- site/src/Marketplace/Application/ReopenMonthStageAction.php
- site/tests/Integration/Marketplace/Ozon/Application/ProcessOzonRealizationConcurrencyTest.php

### Stage 2 progress / rulings
- Default dry-run — только SELECT. Execute ограничен одним UUID компании, raw и месяцем,
  требует согласованный raw hash; все условия перечитываются под lock.
- Atomic restore + final reclose + canonical register assertion; post-commit cache invalidation.
- RED→GREEN: uncategorized document operation could have been lost (BLOCKER 1, fixed);
  no-op could hide an incorrect register (IMPORTANT 1, fixed).
- Cache/process concurrency verified with barrier immediately before outer commit:
  ordinary processing waits and then rejects final closure; old reader version invalidated.
- Added small FinanceFacade bool verification method to reuse canonical calculations,
  replacing duplicate delta arithmetic in Marketplace. Contract documented in ARCHITECTURE.md.
- `gh pr edit` failed on deprecated Projects Classic API; REST PATCH succeeded. PR #2913
  title updated, still Draft/master. Production wrapper is deliberately not modified.

### Handoff active checks (resume here)
- Stage 2 module Marketplace+Finance: 749 tests / 3614 assertions PASS.
- Focused Stage 2 tests: 8 / 43 PASS, including real process repair/ordinary
  concurrency and after-commit cache namespace invalidation (mutation RED→GREEN).
- Full gates: stan session 38928, CS 99413, strict 4964; logs
  /tmp/ozon-realization-handoff-{stan,cs,strict}.log.
- Unit gate session 88655, log /tmp/ozon-realization-handoff-unit.log.
- Full make site-test not started: wait for unit to finish (same test DB).
- Fresh internal reviewer: /root/final_code_review; complete diff base 2317ed9b8
  to staged working tree. Do not run app tests in reviewer concurrently.
- External Claude auth preflight passes. External review not started; run once
  after internal findings fixed, via site/bin/external-review.sh 2317ed9b8 --effort medium.
- Stage 2 not closed/committed yet. All code staged; current PR #2913 Draft/master.
- Production unchanged. Before final handoff: finish gates/review, Stage 2 report,
  handoff doc, commit/push/update PR/Ready, then request named approvals.

### Fresh review fixes and test-environment diagnosis
- Fresh review IMPORTANT 2: no-op broken PL links; stale finance lock on cached Company.
  Both reproduced RED, fixed, GREEN 2 tests / 10 assertions. MINOR dry-run output expanded.
- Added batch-preservation fixture: 501 raw rows, by-day sales, WB/cost docs,
  non-null CFO on other docs, plus safe refusal of losing repaired document's CFO.
  Both extra preservation tests PASS (2 / 12).
- Overall internal findings so far BLOCKER 1 / IMPORTANT 3, all fixed.
- Full make unit PASS4547/25384; strict PASS0/3908.
- Full CS failed only on FinanceFacade docblock alignment: focused formatter used dist
  instead of project .php-cs-fixer.php. Corrected actual project config; recheck pending.
- Full stan parallel failed unfinished workers, serial composer retry hit300s default.
  Next full analysis uses isolated container + COMPOSER_PROCESS_TIMEOUT=0 + --debug.
- Full make site-test exit137 at6832/8517 under resource contention; fail-fast diagnostic
  found duplicate fixed user ID in shared app_test. Do not claim full green.
- Isolated DB app_test_ozon_realization_repair now created; migration pending.
  Container vf-ozon-realization-isolated-tests inherits dedicated test DATABASE_URL.
  /tmp/ozon-realization-local-gate.sh runs existing Make targets through an ignored
  compose override; no repository config/CI/production permission changes.
- External review running session58267; log /tmp/ozon-realization-external-review.log,
  artifacts site/var/external-review/2317ed9b/. Production remains untouched.
- Next: migrate isolated DB, then full tests in isolation and serial full stan,
  finish code review findings/gates, reports, commit/push/Ready. Keep PR #2913.

### Latest final verification (current resume point)
- Isolated canonical make site-test PASS:8521 tests /47893 assertions, 7m20s /838.5MB.
- External Claude review completed1 round:0 BLOCKER /2 IMPORTANT /1 MINOR.
  Both IMPORTANT reproduced RED and fixed: connection-owned Reopen transaction
  preserves EM on validation refusal; shared OzonRealizationReport header parser
  supports ISO date-time calendar dates. MINOR dead ownership branch removed.
  All11 focused external regressions/header cases PASS15 assertions.
  External outcome: fixed without re-run (policy only re-run after fixed BLOCKER).
- Fresh independent final fix verification:0 BLOCKER /0 IMPORTANT /0 MINOR remaining.
- Overall internal findings: BLOCKER1 /IMPORTANT3, all fixed. External:0 /2, all fixed.
- CI Stage1 static failures diagnosed: narrowed raw types and logging array docblock
  make7 old baseline patterns/8 occurrences obsolete. Removed42 lines, added0.
- Final source gates after these fixes:
  full session71509 /tmp/ozon-realization-final-full.log (8532 tests total);
  source-wide stan serial1G session24245 hit memory limit; retry same full scope2G
  running (new session ID is in tool result), /tmp/ozon-realization-final-stan-2g.log.
  CS session91711 PASS0/3909; strict session62055 PASS0/3909.
- CLI help for one-off command PASS. Production read-only confirms header date-only,
  target revenue CFO null, document project equals connection project. No mutations.
- Latest code is NOT yet committed/pushed (Stage1 HEAD61383f407). Need wait final
  tests/stan; update docs/checkpoint; commit Stage2, push, update PR#2913, verify
  CI/Ready, request merge+deploy plus separately named production approvals.

### Stage 2 closed; handoff checks finishing
- Stage 2 local DoD DONE; report stages/stage-2.md.
- Final full PHPUnit PASS8532/47908; focused PHPStan11 files PASS; header8/8 PASS.
- Full CS/strict PASS0/3909. Serial full PHPStan2G session20179 still running.
- Fresh internal review remaining B0/I0/M0; external1 round B0/I2 fixed without re-run.
- Next: commit/push Stage2, finish global static, write handoff, update PR/Ready.
- Production unchanged; merge/deploy and named backup/wrapper/execute not approved.
