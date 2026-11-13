# Checkpoint — Telegram Payment Intake

**Phase:** Stage5 / local integration accepted, live eval pending.
**Status:** local checks/review complete; final branch CI is verified through PR
checks before the owner handoff. Next business step requires named live approval.
**Task base:** `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`.

Branch: `docs/telegram-payment-intake-examples`.
Draft PR: https://github.com/pavelsur07/app-service-finance/pull/2985, base master.
Stage commits: dbfc6d5ff, 1b326aa9a, 0264aed23, 5fff92c58, 0984d7b62.
Final Stage5 commit is branch/PR head (self-referential SHA is not stored here).
Unrelated owner untracked workspace/design/integration/task files and
site/bin/capture-wb-inventory.sh are untouched and excluded from commits.

## Delivered

- Stage1–4 specs/checklists/reports complete: standalone v1 contract, 41 anonymized
  source cases, Python task inside existing ai-service, Cash drafts/Confirm,
  Telegram cards/corrections/durable polling and targeted ServiceGateway pull.
- Decimal/evidence/PII checks; runtime financial questions; no guessed scale,
  grouping, paid fact or compensation. Company+actor/current finance checks,
  stable item/ledger/source/reply identities and atomic idempotent Confirm.
- Durable queued retry with same key after60s, fair bounded recovery, AI budget
  admission, dedicated async_payment_intake queue. New dev worker profile only.
- Global original/reply existence guard survives Telegram binding/session loss;
  atomic UUIDv7 reply receipts + global hash lock/unique/FK cascade stop retarget
  replay. Delivery request token/CAS preserves newer demand during old HTTP.
- Final external fixes: canonical Cash category mapper handles TRANSFER as any;
  conflicting sign/verb stays null+question; NULL old link preserves actor;
  recovery skips wrong actors and sets knownSource=true even after precheck race.
- Offline-safe live-eval runner, metadata manifest41, explicit bounded execute,
  dedicated local test token only; budget-abort guard also covers schema retry.
  CLI/core/prompt/schema hashes recorded. No live calls or model accuracy claim.
- ARCHITECTURE/runbook/README/handoff reflect real directories, states and queue.

## Authoritative verification

Shared compose exec site-php-cli points to another worktree. Use task runner
vf-payment-intake-php or make run --rm (correct bind). Heavy PHP checks serialized;
host ~6GB RAM/full swap. No system/prod settings changed to work around memory.

- Full `make site-test`: **8942 tests / 50254 assertions PASS**,11:32.694,
  760.50MB; before last four PHP handoff regressions, covered subsequently below.
- Final canonical `make site-cs-check`, `make site-cs-strict-types`: **0/4153**.
- Python current full lint/test/evals: ruff/format PASS, mypy55 PASS,
  **295 passed/1 skipped**, eval9 passed/1 skipped; targeted review+runner64.
  Actual default manifest command:41 IDs,0 network calls, hashes current.
- Cash current targeted67/341 prior late mapping; TRANSFER RED→GREEN2/5;
  focused Stan includes Action/test/both builders/unit helper PASS, CS PASS.
- Telegram combined93/779, previous final minor2/13, final actor/recovery
  RED→GREEN2/16; focused Stan and canonical CS PASS. No warning hidden.
- Gateway/Admin23/81; commit-before-ack/commit-failure/identity checks covered.
- Final Telegram migration up/down/up PASS23 SQL in rolled-back throwaway schema;
  legacy row unchanged. Cash migration roundtrip PASS. Final mapping/schema dump
  only existing test_money_holder fixture, no Cash/Telegram drift.
- Earlier full test errors in old synthetic company schemas were fixed by adding
  deletion_requested_at; narrow36/111 PASS, latest full suite above PASS.
  Types::JSONB removed the mapping deprecation. Earlier exit137 was not green.
- Local full PHPStan failed incomplete worker under host OOM even unique cache/
  single worker; cgroup oom_kill3, Docker OOMKilledtrue. Never call it green.
  CI0984d7b62 full static/unit/integration/functional/Python/style/empty-migration
  gates PASS. Final current-head CI receipt is in PR checks/description and the
  owner's final message; inspect it on resume, do not equate previous SHA to head.
- Baseline only verified Webhook flush count6→4; baseline guard PASS. Do not
  delete unrelated unmatched entries: raw old/current probes show actual errors.

## Review closure

Stage2 external1 B0/I6 (five confirmed, missing-tracked-files claim rejected via
commit evidence; unused-context subfinding fixed). Stage3 external1 B0/I3 fixed.
Stage4 external1 B1/I4 fixed; mandatory external2 B0/I1 docs/local worker fixed.
Handoff external1 B0/I1/M3 all fixed/tested. No B in handoff; **fixed without
re-run**, no false REVIEW_GREEN. Artifacts: site/var/payment-intake-review/.

Fresh whole-task independent reviewers B0/I4, B0/I5, B0/I3 all closed. Final
small-delta independent review open B0/I0; its defense-in-depth knownSource MINOR
was subsequently fixed and read/verified by parent. Runner self-I1 fixed with
RED2calls→GREEN1call. No confirmed open BLOCKER/IMPORTANT.
Metrics are observations per round, not unique defects: independent internal
B0/I12 plus runner selfB0/I1; external B1/I15 across five rounds.

## Exact next action on resume

1. Read handoff.md, live-eval.md, this checkpoint; verify git status/PR head and
   current CI. Final code is committed/pushed in same Draft PR, never merged.
2. Without explicit named live approval do NOT run --execute. Owner must approve
   proposed deepseek/deepseek-flash via existing gateway,41 anonymized cases,
   max82calls, input guard65536/output16000, timeout60s, budget estimate300RUB.
   Check current tariff and provision separate local PAYMENT_INTAKE_EVAL_TOKEN
   before execution; never copy/use production AI_GATEWAY_TOKEN or print secrets.
3. Once approved, run bounded eval, review partial-gold metrics and limitations,
   measure real latency/cost; no fabricated total accuracy/analytics from partial
   labels. Stage5/pilot remains pending until agreed acceptance is actually met.
4. Pilot flag default0; global flag+existing AI grants define reach. Narrow
   independent company pilot needs a task-specific gate first. Production worker,
   scheduler/recovery commands, migrations/deploy each need applicable named
   approvals; worker command/dev profile and migration request are reviewable in
   runbook. Do NOT merge/deploy/activate on generic implementation authorization.

No live Telegram/model API, production processing, production worker/config,
production migration or deploy performed. Two additive migrations need release
manual dispatch; down drops new drafts/session/receipt metadata and is not an
automatic production rollback. Disabling flag preserves saved money and guards.
