# Account/admin external placeholders — handoff

Branch: `feat/account-admin-public-access`.
PR: https://github.com/pavelsur07/app-service-finance/pull/2967 (base master).
CI: live required results in PR checks; local checks below are verified.

## Changes
Stage 1: Node24→nginx images с общим Dockerfile/config, allowlisted root context,
read-only runtime, home/about/fallback/assets/health; Traefik Host/TLS/HTTP redirect
для account.vashfindir.ru и admin.vashfindir.ru, без host ports или backend network.
Stage 2: app checks, real-image browser smoke before GHCR push, selective PR and
full master/manual matrices, explicit pre-pull/up --wait late in deploy.
Runner bin/frontend-production-test.sh и Make front-prod-build/test. Docs:
apps/README.md, stages/stage-1.md, stages/stage-2.md.

## Verification
- Baseline new apps typecheck/lint/test/build passed, MSW13 per app.
- Baseline deploy guard had one pre-existing stale sync-in-step4 failure;
  updated consumer-first guard and remaining worker invariants pass.
- Both Docker builds + actual nginx browser suites: 4/4 each (desktop/mobile).
  Wrong-app image test failed as expected; correct identity checks passed.
- Release contracts 6/6 with actual workflow matrix Bash, RED→GREEN for
  publication order and late SPA rollout. Actionlint, bash -n, diff-check passed.
- Handoff PHP stan/cs/strict/unit all passed; unit 4611 tests/25547 assertions.
  No new PHP integration paths; make site-test not required.
- Public DNS answers for both names match app.vashfindir.ru; owner added records.

## Review
Internal fresh complete diff B0/I0/M0/F0, fixes self-review clean.
External Claude Code medium round1, B0/I1/M2 confirmed and fixed (rollout order,
runner location, registry-pull prerequisite). IMPORTANT verified RED→GREEN +
relevant suites. Fixed without re-run; open B/I 0. Supplemental full new-path
context supplied to standard reviewer script. Metrics: internal B0/I0;
external B0/I1 fixed, M2 fixed.

## Release boundaries and acceptance
Production Docker/CI/labels are explicitly part of this owner-requested task;
no manual production changes, permission expansion, new secrets or migrations.
Initial pre-pull must confirm production access to the two new GHCR packages;
visibility/stored credentials cannot be verified from repository source.
After merge: await all gates/build/deploy, check image SHA + healthy services
through read-only wrappers and verify public HTTPS, HTTP redirects, /about/reload,
correct account/admin identity and client 404/assets from an external browser.
Current placeholders have no auth, API or company/financial data. Existing
app.vashfindir.ru routing and services remain on the standard pipeline.
Rollback: revert PR through standard release; no data operations required.

## Owner decision
Ready: PR #2967 "feat(frontend): publish account and admin placeholders over HTTPS" — merge into master with automatic production deploy?
Reply: "merge and deploy #2967"
