## Current checkpoint

**Phase:** handoff — owner merge/deploy decision pending
**Status:** done (implementation)
**Stage base commit:** 0e14475c9

### Completed
- Phase 0: изолированный worktree feat/retire-feature-flags от актуального master.
- Прочитаны правила, план, ServiceGateway/SourceFacts/Telegram архитектура.
- Pre-flight: Stage 1 grant API используется Stage 2; сигнатуры Facade сохраняются.
- Pre-flight: Stage 2 --all используется Stage 3 cron; UUID mode сохраняется.
- Ruling: текущий запрос реализации покрывает предусмотренные изменения CI/CD и Compose; production и Variables только после отдельного handoff-разрешения.

### Checks and baseline
- make test-deploy-workflow — PASS (shell + 10 Python).
- ServiceGateway Unit — PASS (184 tests, 884 assertions).
- SourceFacts/Telegram baseline — running.

### Review status
- internal: pending
- external: pending

### Exact next action
- Регрессионные тесты AiServiceAccess без rollout и SourceFacts без scope: RED → GREEN.

### Stage 1 complete
- 263 tests / 1186 assertions PASS. RED→GREEN подтверждён.
- SourceFacts/Telegram baseline: 74 tests / 564 assertions PASS.
- Internal: BLOCKER 0, IMPORTANT 0. External: handoff pending.
- Next: Stage 2 — tests for admin-granted nonpilot and recovery --all.

### Stage 2 progress
- 2.1: pilot class/guards removed; AI checks retained for new parse/edit/retry.
- 2.2: recovery UUID/--all, sorted union of grants/pending sessions, isolated channels.
- DI bindings removed in Stage 2 because the class is removed here (Stage 3 remainder unchanged).
- Telegram functional: 58 tests PASS; all-company integration: 3 tests / 19 assertions PASS.
- Focused PHPStan: PASS. Focused CS: PASS after formatting test files.
- Next: module result + complete Stage diff review/external review (>500 lines).

### Stage 2 complete
- 182 tests / 1278 assertions PASS.
- External 1 round: BLOCKER 0, IMPORTANT 1 fixed without re-run (RED→GREEN), MINOR 2 fixed.
- Ruling: recovery includes event-only Cash deliveries without AI grant/session.
  Reason: Confirm must preserve financial invalidation after a transport failure.
  Cost if wrong: extra read-only enumeration/event retry; no new payment or LLM.
- Next: Stage 3 — apply deployment changes to prepared RED runtime tests.

### Stage 3 / handoff checking
- Stage base: ed99b3518.
- Six Variables removed from active config/DI/workflow/env/templates.
- Business services/payment worker default production; nightly ai-service-eval explicit profile.
- Cron PHP recovery --all; legacy shell removed; dev profiles retained.
- Deployment RED: no-start without flags/profile mismatch. GREEN: shell + 6 runtime tests,
  14 service config pytest tests; fake secret failures/nightly false/true/stale flags covered.
- Synthetic Compose config PASS; business default, nightly explicit profile only.
- Twig narrow lint/twigcs PASS (copy only).
- Python: ruff/format/mypy PASS; pytest 540 passed, 61 skipped (no AI_TEST_DB_DSN
  and pre-existing moved categorization action path), 10 evals deselected, 2 warnings.
- Full PHPStan, CS/strict/unit and full tests running via project Make targets with a
  local compose adapter reusing verified worktree-mounted PHP CLI. Full tests use
  separate local app_retire_flags_test DB; production credentials never used.
- Fresh independent internal reviewer running on complete diff. External handoff pending.

### Final review corrections and environment limits
- Fresh internal review: BLOCKER 0 / IMPORTANT 1 (Cash swallowed transport errors).
  Fixed opt-in failOnError for automatic recovery, default Confirm unchanged.
  Real AI-dispatch + Cash-event listener failures RED→GREEN; 5 integration tests /29 assertions.
- Final module suite after correction: 183 tests /1284 assertions PASS.
- Mypy non-incremental: 68 source files PASS.
- Full PHPStan first attempt incomplete: parallel worker jobs timed out (1 infrastructure error).
- Initial CS: Composer 300s timeout; adapter changed to COMPOSER_PROCESS_TIMEOUT=0.
- Initial full tests: exit 137 after 6771/9145, no assertion failures printed before kill.
  Host swap exhausted. Next approach: sequential bounded suites/shards as CI to cap PHP memory.

### Verification update
- Final external round 1: REVIEW_GREEN (Claude, medium, complete 1923-line code diff
  plus complementary deployment/config diff in review context).
- Full CS and strict-types PASS: 0/4272 files need fixes.
- Full unit PASS: 4804 tests /26738 assertions.
- Final independent internal IMPORTANT fixed; no open BLOCKER/IMPORTANT.
- Full tests restarted through same make site-test target with an isolated local adapter
  splitting unit/integration and 3 existing functional CI shards sequentially, no filters/omissions.

### Final review findings disposition
- External handoff: BLOCKER 0 / IMPORTANT 0 / MINOR 5; REVIEW_GREEN.
- Four local MINOR groups fixed: stale architecture/cron/Compose/workflow prose,
  misplaced recover docblock and duplicate automatic recovery warning logs.
- FOLLOW-UP (external MINOR): fail-fast inside one company's Cash channel can defer
  later Cash intents until next cron while delivery and other companies continue.
  Isolation required by task is channel/company, not per-draft. No deterministic
  per-draft failure identified; broader retry-loop behavior deferred to avoid changing
  existing Cash semantics beyond this task.
- Focused final PHPStan caught 4 test-only typing issues (nullable owner, obsolete extra
  fixture arg, two unknown message properties); fixed with assertions/argument cleanup.

### Database isolation retry
- Bounded integration run: 2892 tests, 13776 assertions, 22 errors — all the same
  pre-existing API fixture email uniqueness conflict in ApiKeyActionsTest and
  SaveApiKeyPermissionsActionTest, untouched by this task. DB had been reused after
  killed monolithic run. Recreated ONLY task-owned app_retire_flags_test before retry.
- Unit 4804 PASS already complete; retry runs all integration +3 functional shards,
  with no excludes. No application logic changed for these fixture errors.
- Real local Docker Compose profile smoke PASS using existing local test network:
  enabled nightly survives default final up; stopped nightly stays stopped.

### Revocation crash-window regression
- Additional internal IMPORTANT: after ambiguous dispatch (task may already be accepted),
  interactive recovery after grant revoke marked queued draft failed and rejected later result.
- RED observed queued → failed. Minimal fix: recoverOne checks grant before dispatch,
  retaining queued/pending identity; events/results can still be delivered. New requests unchanged.
- Telegram AI stub now mirrors production null response without grant. No LLM/network.
- Internal metrics now BLOCKER 0 / IMPORTANT 2 fixed; targeted module verification pending.

### Full tests PASS
- Clean isolated DB resolved all 22 stale fixture uniqueness errors.
- Unit 4804 /26738 assertions PASS; integration 2892 /13857 PASS;
  functional shards 517 /3542, 532 /3029, 402 /4171 PASS.
- Complete coverage: 9147 tests /51337 assertions PASS, no skipped PHP tests.
- Full sequential PHPStan still running; final CS/strict/unit confirmation running.

### PHPStan resource diagnosis / CI fallback
- Single-worker make site-stan also incomplete: parallel worker did not finish.
- Docker confirms OOMKilled=true for the task PHP container (memoryLimit=0: host pressure).
- Running full analysis with --debug --memory-limit=1536M, avoiding parallel job transport.
- Full local tests 9147 /51337 assertions PASS; final CS/strict/unit PASS on latest code.
- Commit/push Draft for standard CI full PHPStan on its own runner; Ready remains pending.

### CI verification
- CI full PHPStan success for code commit 87b4dd615:
  https://github.com/pavelsur07/app-service-finance/actions/runs/43679594480/job/132707202964
- CI Python/CS/unit/API types/SPA checks green; full local 9147 tests green.
- CI functional shard 2/3 failed one untouched wall-clock-sensitive test:
  WbFinanceSalesReportsQuotaControllerTest::testRemote429SetsSharedCooldown,
  expected cooldown_seconds=120, actual119 (test elapsed second).
- No quota/rate-limit code changed. Retry failed job on a fresh runner to verify timing hypothesis.
- Rerun initially refused with HTTP403 while original workflow was still running.
  Original run has now completed; corrected retry of the single failed functional job requested.
  All other required source gates including full PHPStan passed in CI.

### Handoff preparation
- Full PHPStan CI PASS; in-process local retry also ended exit137 (OOM), no running local analyzer remains.
- Final source code 87b4dd615; 9147 PHP tests /51337 assertions PASS, CS/strict/unit PASS.
- External REVIEW_GREEN, internal findings fixed, one Cash-channel MINOR deferred.
- CI failed WB timing test rerun accepted; attempt2 in progress, no source changes.
- Next: observe rerun result; finalize reports, commit docs, Ready PR, single release question.

### Final handoff
- CI WB timing-flake retry PASS without source changes. All source gates green.
- Source commit: 87b4dd615; final commit only records verification in task reports.
- Internal: BLOCKER0/IMPORTANT2 fixed; external: BLOCKER0/IMPORTANT1 fixed,
  2 points ×1 round, handoff REVIEW_GREEN; Cash per-draft retry MINOR deferred.
- Branch feat/retire-feature-flags, PR3006 base master; Ready at handoff.
- Production and six GitHub Variables unchanged; one named merge/deploy + post-acceptance
  variable-removal decision pending. Rollback requires restoring saved variables first.
