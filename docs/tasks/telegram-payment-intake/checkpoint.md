# Checkpoint — Telegram Payment Intake

## State

Autonomous implementation authorized. Stage 1–3 committed; Stage 4 implemented,
final defect fixes/checks in progress; Stage 5 local acceptance in progress.
No merge, deploy, live LLM/Telegram calls, production worker/config changes or
production processing commands were performed. Pilot flag remains default 0.

Branch: `docs/telegram-payment-intake-examples`.
Draft PR: https://github.com/pavelsur07/app-service-finance/pull/2985 (base master).
Task base: `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`.
Commits: `dbfc6d5ff`, `1b326aa9a`, `0264aed23`, `5fff92c58`; pushed.
Unrelated owner untracked files are untouched and excluded from staging.

## Delivered

- Stage-specific specs and acceptance checklists precede implementation.
- Standalone v1 contract, 41 anonymized source examples, Python task inside
  existing ai-service, exact decimal/evidence checks and offline verifier eval.
- Cash durable drafts, revision/attempt/stable row IDs, atomic idempotent Confirm,
  runtime financial questions, duplicate hints, budget admission and recovery.
- Telegram actor binding, fail-closed source routing, cards, corrections,
  durable polling/delivery, dedicated async_payment_intake logical queue.
- ServiceGateway targeted pull reuses consumer/usage transaction; ack after commit.
- Company deletion/access integration and local optional worker profile.
- Latest review fixes: global original/reply ownership boolean, atomic reply
  receipts, source edits invalidate paid confirmation immediately, delivery
  request token prevents overwriting a newer notification request.

## Authoritative verification

Shared compose exec runner points to another worktree. Use persistent local
`vf-payment-intake-php`, mounted to this checkout, or make run --rm commands.
Host has approximately 6 GB RAM and full swap: heavy gates MUST run serially.

- Python: 284 passed, 1 skipped; lint/mypy 54 files; offline eval 9 passed,
  1 skipped (not live model accuracy). A safe live-eval action is being prepared.
- Cash final frozen code: 67 tests / 341 assertions, focused PHPStan and CS green.
- Telegram frozen: combined 93 tests / 779 assertions plus final two MINOR
  regressions 2 / 13 green; focused PHPStan green.
- Source edit confirmation regression: RED before fix, then 21 tests / 37
  assertions green in Cash draft suite.
- Gateway/Admin unit: 23 tests / 81 assertions green; focused PHPStan green.
- Unit full gate before last regressions: 4683 tests / 26111 assertions green.
- Strict types full gate green before last receipt files; rerun required.
- First full integration gate: 8911 tests / 50078 assertions, two errors in
  synthetic old company fixtures missing deletion_requested_at and one JSONB
  mapping deprecation. Fixtures/mapping fixed; targeted 36 / 111 green.
- Second full integration gate was OOM-killed (exit137), NOT GREEN.
- Full static gates produced unstable unmatched baseline diagnostics under
  parallel analysis; raw old/current probes prove affected errors still exist.
  Never delete those legitimate baseline entries. Only verified task reduction
  TelegramWebhookController flush count 6→4 is changed; baseline guard green.
- Parent test stamp type fixed. Next full static run uses ignored local config
  var/payment-intake-review/phpstan-low-memory.neon, full scope/baseline,
  one worker, COMPOSER_PROCESS_TIMEOUT=0. Not a committed gate change.
- Last canonical CS full gate identified three task files; author/root fixes
  and full rerun pending. Canonical config .php-cs-fixer.php.

## Review

- Stage2 external: B0/I6; confirmed I1–I5 fixed plus valid fixture subfinding;
  missing tracked corpus/context allegation rejected with commit evidence.
  Stage2 report records fixed without re-run and offline limitations.
- Stage3 external: B0/I3, all confirmed/fixed with tests, fixed without re-run.
- Stage4 external round1: B1/I4; all confirmed/fixed. Mandatory round2:
  B0/I1 (canonical queue/worker documentation), fixed including local opt-in
  worker profile; safe minors completed with tests. Reports preserved under ignored
  site/var/payment-intake-review/stage4-round{1,2}.txt.
- Fresh whole-task reviewer: B0/I5 fixed. Fresh final independent reviewer:
  B0/I3 global source/null actor, reply replay, newer delivery demand;
  all three fixes independently verified closed; open B0/I0/M0.
- Full handoff external review still required after local final checks.

## Exact next actions

1. Telegram final freeze and fresh reviewer verification received; no open B/I.
   Final Telegram migration including token up/down/up PASS23 SQL; schema dump
   only existing test_money_holder. Full CS + strict types 0/4151 green.
2. Full static serial low-memory gate running session42300, log
   /tmp/payment-intake-stan-serial.log; then full tests serial. Inspect every
   result, no false green claims.
3. Complete Stage4 report, commit/push task-owned files; update same Draft PR.
4. Full handoff external review with Python diff in context (script default
   only covers site), fix confirmed findings and verify.
5. Finish local Stage5/handoff/checkpoint and prepare concrete limited live eval
   request. Stage5 cannot fully close or pilot enable without separately approved
   provider/model/budget/live calls. Production worker and migrations require
   named approvals under AGENTS; do all authorized local work first.
