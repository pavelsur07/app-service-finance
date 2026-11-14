# Stage3 — ТЗ и чек-лист приёмки/выпуска
Risk HIGH-LOCAL. stage_base_commit: set after Stage2 commit, before checks/final changes.

- [ ] PHP changed-module and focused gates green; full style/strict/unit/Stan/integration via exact-head CI. Local native resource blockers reported as such, notgreen.
- [ ] Python full suite/evals and route/prod/night tests green; runtime shell/unittest/Compose proof green.
- [ ] Complete branch diff reviewed in fresh context with TASK only, tests observe actual boundaries, no duplicate abstractions/crosscompany/manual actor assignment.
- [ ] Internal B/I fixed; formal external Claude medium exact marker or fixed-without-rerun evidence, infra workflow/compose/cron diff provided as context because collector omits them. No runtime/privateUUID/envsecrets/rawpayload to reviewers.
- [ ] Task-owned commits only, branch feat/payment-intake-tretyakova-pilot, PR3001 master, latesthead requiredchecks verified, Ready.
- [ ] Confirm owner's current pilot instruction against release approval contract; do not ask routine intermediate approvals. If merge approval still required, ask once at concrete handoff naming pilot flag/scope/worker+cron explicitly (no migrations).
- [ ] First code release with flag0 + selectedscope; no operations/Telegram messages from agent; postdeploy newAIimage DS-only known from immutable routeSHA.
- [ ] Then flag1 standarddeploy sameSHA; verify worker/API/scheduler images, scoped config, HTTP200, noOOM, queue counts with readonlywrappers. Do not mutate via rawSSH/SQL.
- [ ] Current-day old nightlyreport retained, no forcedrerun/falsegreen; nextautomaticday checks actualDS route under30/900.
- [ ] Actual actorlessbinding rebind remains user action via /integrations/telegram. Live first realpayment confirmation remains user action; no fixture money written onprod.
- [ ] Final report distinguishes pilot configured vs user-connected and known-regression vs independentholdout; state missingheartbeat/notificationreceipt asfollowups.
