# План: пилот одной компании

Class Large, base ae0b812c4438495139bc94bdaecbf6817ad53b3b. Existing modules: Telegram PaymentIntake adapter; SourceFacts CSV rollout; AiQuality profiled worker/deploy wiring. Root AGENTS autonomy overrides skill manual design/plan gates: owner selected proposed pilot scope; proceed stages automatically. Worktree /tmp/vf-payment-intake-pilot; branch feat/payment-intake-tretyakova-pilot. Owner checkout untouched.

## Stage 1: Ограничение Telegram по компании (HIGH-LOCAL)

### Task 1: Ограничить Telegram pilot и recovery
Definition of Done: policy is failclosed; no new intake outside allowlist; known source/replies cannot fall through; revoked callbacks and queued polling cannot act; legacy outside pilot retained; Telegram recovery skip-cash option tested. Record stage base before code.
1.1 Tests first: strict UUID CSV policy, pilot/nonpilot/globaloff, revoke after draft, source recovery-before-persist, callback/poll/queue/recovery guards.
1.2 Implement Telegram Application PaymentIntakeAccess: membership independent of flag and effective gate. Apply in PaymentIntakeTelegramAction, QueuePaymentIntakePollAction, RefreshPaymentIntakeMessageHandler, RecoverPaymentIntakeDeliveryCommand before any scoped actions. Existing bool/env defaults remain0, add env default empty. Test fixture scope uses synthetic UUIDs, no production IDs.
1.3 Add --skip-cash-recovery option (VALUE_NONE) in Telegram command; unflagged legacy command behavior preserved. Tests skip/default and revoked scope.
Checks: targeted policy tests RED/GREEN, Telegram functional and PaymentIntake module, focused style/Stan. Internal complete Stage diff, fix, report, commit/push/update Draft PR, continue. External at Stage only if >500 lines.

## Stage 2: Production runtime и DeepSeek (HIGH-LOCAL)

### Task 2: Подготовить runtime и маршрут модели
Definition of Done: strict runtime config validated before interpolation; worker cannot start when disabled or no scope; independent scoped recovery; parse route DS only and other task routes unchanged. Stage base recorded before code.
2.1 Tests first: runtime unittest fake PHP/docker for flag/scope/off/invalid/injection and independent recovery errors; exact AI route guard.
2.2 Add flags/scope to compose PHP AND scheduler; new profiled site-messenger-worker-payment-intake, async_payment_intake only, same256M memory/logging/mount conventions.
2.3 Deploy variable validation, exports, guarded explicit-profile worker start/stop without --wait; update unconditional-worker checker. Add script site/bin/recover-payment-intake.sh and cron five-minute invocation; full CSV validated before both command calls, independent failures aggregate.
2.4 parse route single DS, preserve other task routes, add invariant tests; nightly fingerprint advances naturally, no replay.
Checks: make test-deploy-workflow, new unittest, bash -n, compose config validation, pytest route/prod/night config, Python lint/types. Review full Stage diff, fix/report/commit/push/PR, continue. External if diff>500.

## Stage 3: Приёмка и выпуск (HIGH-LOCAL)

### Task 3: Проверить и выпустить scoped pilot
Definition of Done: full gates and clean fresh internal review + external Claude; concrete PR handoff/release authorization interpreted against root AGENTS. No schema migrations in this task. Runtime activation scope only identified company.
3.1 Full PHP Stan/style/strict/unit/integration gates once at handoff; bounded local resources, exact CI authoritative if local memory cannot run. Python suite/evals and runtime gates.
3.2 Fresh independent reviewer receives only TASK/full diff/checklist, fix cycle; formal external site/bin/external-review.sh base --effort medium with infra context since collector omits root workflow/compose/cron.
3.3 Handoff with exact scope, flags, worker/recovery, DS route, old nightly fingerprint report/no replay and mandatory user rebind; Ready PR. Production activation through approved standard pipeline only; no new ad-hoc commands.
3.4 Postdeploy readonly wrappers: same images, queue/worker process, no OOM, selected scope effect via readiness metadata; provide actual user steps to rebind /set_cash / payment text / explicit Confirm. Do not write fictitious ledger entries or send Telegram test messages.

## Review focus
Cross-company legacy fallback after source recovery; sticky binding on revoked scope; async delivery/lease guard before HTTP; invalid CSV and shell injection; final compose up restarting a disabled worker; Cash failure suppressing Telegram recovery; accidental changes to other model routes/AI grants; missing actor impersonation; old nightly report falsely green.
