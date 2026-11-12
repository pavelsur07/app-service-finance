# Account/admin Stage 1 — handoff

**Branch:** `feat/account-admin-auth`
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2971
**Product checkpoint:** `0943d7c07` (subsequent commit closes task documents only).
**Status:** CLOSED — owner acceptance received 2026-11-12.
**CI/deploy:** [workflow43152801528](https://github.com/pavelsur07/app-service-finance/actions/runs/43152801528) SUCCESS.
**Merge:** `4b965aaf7dfd0813548e55e765ea3c752db23f26` in master.

## Delivered

- Stage1: existing Symfony users/passwords, independent account/admin host-only
  Redis sessions, JSON CSRF/login/logout, throttling and host/Origin boundary.
- Stage2: account identity, current company, existing module permissions,
  paginated accessible companies and guarded selection. Admin requires system
  ROLE_ADMIN and works without a company. Generated account OpenAPI contract.
- Stage3: login and protected React shells, company picker, error/retry/forbidden/
  no-company states, cancellation and stale-response guards. Public about/404
  remain independent of authentication. Finance kit and legacy adapters retained.
- Stage4: exact same-origin routing, actual Symfony HTTP/HTTPS acceptance,
  production image smoke, required CI gate and documented local/release workflow.

## Files and public contracts

Backend: `site/src/Shared/{Security/Spa,Controller/Spa,Infrastructure/Http}`,
Company/Admin API controllers/DTOs, existing Company Facade/repository, security
configuration and API schemas. Frontend: `apps/{account,admin}`, shared
`site/packages/api` session transport and generated types. Infra: Compose/nginx,
`bin/frontend-auth*`, Makefile, deploy workflow. Usage: [apps README](../../../apps/README.md).

New same-origin `/api/account/v1/` and `/api/admin/v1/` auth/session contracts;
account includes companies/selection. Existing API/imports/login remain supported.
No migrations, new dependencies, financial formulas or business screens.

## Verification

All local acceptance passed; detailed commands and failure recovery are in
[Stage4 report](stages/stage-4.md).

- Full PHP suite8765/49102 and unit4609/25550; CS/strict-types; full-scope PHPStan.
- Late focused backend42/223, account+legacy API drift checks.
- New apps typecheck/lint/format/build; Vitest account35/admin31.
- UI dev and preview account12/admin10 each; image smoke14/12.
- Real backend dev, preview and trusted HTTPS account2/admin2 each.
- Legacy frontend lint/build/test:unit92; routing/release/deploy shell contracts.
- Structural actionlint passes; base and HEAD share existing SC2129/SC2086.

Initial parallel PHPStan and production browser attempts suffered local memory
contention; serial full-scope analysis and sequential browser runs passed. No
analysis exclusions or test assertions weakened.

## Reviews

Final internal: B1/I3 found and fixed; no open B/I. Fresh reviewer confirmed all
late changes. Final external: two rounds REVIEW_GREEN, B0/I0, safe minors fixed
or technically rejected with reason in Stage4 report. Earlier Stage1 external
B0/I1 fixed without rerun; Stage2 REVIEW_GREEN B0/I0.

## Release and follow-ups

PR2970 merged as34ef67560; its docs-only deploy was skipped. Owner approved
`merge and deploy #2971`; implementation merged and production deploy succeeded.
Read-only wrapper confirmed account-nginx, admin-nginx, site-nginx and site-php-fpm
healthy on merge SHA4b965aaf7.

Production browser acceptance passed with normal TLS verification: both login
forms/reload, desktop/mobile width, public about/404 without API bootstrap,
JSON401 session, CSRF200, Secure/HttpOnly/SameSite=Lax host-only cookies, reserved
paths404 and no sibling CORS. Legacy login and encoded wrong-host API boundaries
passed; no JavaScript errors. No credentials submitted or token values logged.
The first browser attempt timed out on networkidle; narrow HTTP and DOM/login
diagnostics passed, then full acceptance passed with DOM readiness and explicit
UI assertions. Product code did not change during acceptance.

Owner confirmed: «Подтверждаю все работает». Task complete.

Rollback through a standard revert, no Redis purge; SPA re-login may be required.
Remaining product follow-up: business screens; no SSO is included. Existing
actionlint shell warnings remain outside this change.

## Owner decision

Received: `merge and deploy #2971`. Merge, deploy and acceptance completed.
Subsequent instruction: close the task, remove merged branches and return to master.
