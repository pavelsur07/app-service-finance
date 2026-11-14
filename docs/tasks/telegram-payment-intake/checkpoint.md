# Checkpoint — Telegram Payment Intake

**Phase:** Stage5 / first live run completed, safe diagnostics and scorer correction.
**Status:** owner also approved a one-off root-SSH isolated evaluation. First
run41cases/45calls completed and cleaned up; credentials stayed on app-host.
Scorer false-negative bug fixed RED→GREEN; safe diagnostics prepared, next focused
subset uses≤18 of remaining37 campaign calls. No pilot acceptance or deploy.
**Task base:** `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`.

Branch: `docs/telegram-payment-intake-examples`.
Draft PR: https://github.com/pavelsur07/app-service-finance/pull/2985, base master.
Stage commits: dbfc6d5ff, 1b326aa9a, 0264aed23, 5fff92c58, 0984d7b62.
Final Stage5 code commit: `7031b52992cbf7fadc6cbb27a516c987c5dcd73c`, pushed.
Subsequent checkpoint-only commits do not change the verified runtime sources.
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
  gates PASS. Automatic pull_request CI did not appear for7031b5299. Manual dispatch
  production_action=none run43500484323 returned success but deliberately sets
  every path gate false; it ran NO tests/static checks and is NOT acceptance.
  A checkpoint-only push retriggers the standard PR event; inspect the exact
  current-head CI in PR checks/final message, never equate old SHA to head.
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

1. Read live-eval-preflight-2026-11-14.md and verify git status. Runtime source
   remains7b5e7b950 (CI43500760767 SUCCESS); this continuation changes docs only.
2. Approval ALREADY GIVEN: DeepSeek41 anonymized cases, max82 calls, timeout60s,
   estimate300RUB; owner explicitly chose existing gateway key, no second key.
   Do NOT ask to approve the same trial or to create another credential again.
3. Need authorized app-host execution entrypoint. Current dev process, checked
   local env and containers have no gateway token; gateway GET models returns403.
   Allowed vf-prod-codex/codex-console provides only Symfony allowlist, no Python
   eval. codex-docker-ps confirms healthy production ai-service; it does not grant
   Docker exec/run. Never bypass a missing wrapper with arbitrary SSH/sudo/Docker.
4. Source bundle without secrets is ready in ignored site/var/payment-intake-review/
   approved-live-eval-source.zip, SHA256
   87123e508601131a881cf0a3291808aa0ae8591215285dca38b99fe46230a1b2.
   Isolated temporary source-tree import/default CLI passed41cases/0network calls.
   Full file checksum/preflight JSON is beside archive. Fixed command and scope
   for a narrow server entrypoint are in the new preflight document.
5. Once that entrypoint exists, inherit the existing key inside the server-side
   process only, run same bounded trial, capture metadata JSON locally and review
   actual partial-gold metrics/errors/latency. No unapproved additional runs.
   Peak tariff checked: input$0.30/output$1.20 per1M; CBR82.1516 gives upward-rounded
   24.65/98.59RUB prices and261.82RUB reservation ceiling82calls. Sources in doc.
6. Pilot flag0; company gate/production worker/scheduler/migrations/deploy remain
   separate approvals. Named trial approval does not enable pilot or deploy.

No live Telegram/model API, production processing, production worker/config,
production migration or deploy performed. Two additive migrations need release
manual dispatch; down drops new drafts/session/receipt metadata and is not an
automatic production rollback. Disabling flag preserves saved money and guards.

## Continuation review / checks14November

No application/config/service contract changes. Documentation-only self-review:
B0/I0; external review not required (FastPath). Checks: deterministic64-file
source archive/digest, exact41-case offline import/network_calls0, Decimal budget
261.82<300, doc links and git diff check. Provider generation calls for this trial0.

## Root execution exception approved

Owner replied «Разрешаю» to the exact proposal: one live-eval through root-SSH,
in a separate disposable container with the existing gateway key, leaving
application/settings/existing containers unchanged. This expressly overrides
the wrappers-only access restriction solely for this bounded execution. No
new key, permanent wrapper, access-role/network/worker/config change or deploy
is authorized. Prepared source digest and82-call/300RUB bounds remain unchanged.
Root read-only inspect verified healthy ai-service, non-root image userapp,
existing gateway key present, one existing Docker network; no secret was printed.
Next: launch one isolated serial eval from verified bundle, persist metadata-only
result, compare existing container identities/start/restart state afterwards.

## Actual live run and current diagnostics work

Approved root isolated execution COMPLETED:41cases,45calls,provider errors0,
64k input/6k output tokens; conservative known-usage cost estimate2.15255083RUB,
reserved143.680608RUB. Report site/var/payment-intake-review/live-eval-2026-11-14.json
and summary JSON; own container removed, existing API/worker image/start/restarts/
health unchanged (before/after receipts saved). First report needs_review36,
rejected5(tg009,011,026,027,041), initial metric26/49 mentions and24/47amounts,
direction1/7, scale abstention2/2. Median1455ms,p953319ms,max4508ms. NOT pilot-ready.

Current work: Stage5 diagnostics spec written before code; eval-only metadata
per-case intent/counts/check codes/per-case metrics and --case-id selection. New
regressions RED4+privacy2 then GREEN13. No task/verifier/prompt changes. Lint
initially requires ruff format of one test; mypy not yet reached.

Identified potential metric bug: valid candidate can have amount_evidence=null
and amount=null but numeric text_evidence+scale question. Existing score wrongly
counts that as no mention. Parametrized test RED command running91955, log
/tmp/payment-intake-null-evidence-score-red.log. Confirm failure, then narrowly
allow normalized numeric text_evidence as fallback anchor (not arbitrary quotes),
rerun targeted tests/lint/full Python/evals. Baseline raw report stays immutable.

Cumulative call ledger site/var/payment-intake-review/live-eval-call-ledger.json:
used45/max82, remaining37, no additional paid run yet. Next focused diagnostic
subset at most18calls within remaining budget/same41IDs/provider/key and same
nonpersistent root evaluation exception; never exceed campaign82 or300RUB estimate.
Current ignored execute-approved-root-eval.py/hashes refer FIRST bundle; do NOT
reuse unchanged. Need distinct diagnostic bundle/harness hashes and command
subset, CLI max18, save distinct report; preserve first immutable archive/report.
Read actual metadata first before any prompt/parser fix; do not guess root cause.
Update provenance: first-run source7b5e7b950, diagnostic code currently uncommitted;
regenerate offline manifest after final formatting, commit/push same PR whenchecked.

Latest local proof: diagnostic14targetedPASS;301testsPASS/1skip, eval9PASS/1skip;
ruff+format+mypy55PASS after optional-outcome flow fix. Fresh targeted reviewer
B0/I0/M0. Manifest regenerated after final source changes before commit. Next:
commit/push diagnostic/scorer change, build distinct bundle, check call ledger45
then focused max18cases invocation; never overwrite initial receipts.
