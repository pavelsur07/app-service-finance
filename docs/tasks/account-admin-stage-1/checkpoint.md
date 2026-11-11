# Current checkpoint

**Phase:** Stage 4 / Work item 4.1
**Status:** implementing
**Branch:** `feat/account-admin-auth`
**Stage base commit:** `ce991770b0c74d3ec7cbdc8b47c81eceeb174436`
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2971 (Draft, master)

## Authorization and rulings

- Owner: `merge and deploy #2970 - приступай` after the complete specification
  and recommended option A. Proceed with option A (independent host-only sessions)
  and the named API routing/CI integration in that plan; this interpretation was
  stated in chat before implementation. No manual production mutations.
- #2970 merged as `34ef67560`; workflow 43062783564 success, deploy skipped for
  documentation-only change. Both public SPA origins HTTPS 200.
- Future implementation PR still requires its own merge + deploy decision.
- Ruling: repository checkpoint/Stage workflow takes precedence over skill
  per-task ledger/commit scripts; this document is the execution ledger.

## Completed and checks

- Clean isolated worktree; master fetched and new implementation branch created.
- New frontends baseline typecheck/lint/test/build: PASS, 13 Vitest tests each.
- Legacy frontend lint/build/test:unit PASS on Node Debian, 92 tests. Initial Alpine run failed because existing node_modules contain glibc Rollup binary; used matching Debian image, no dependency/code change.
- Isolated local test Compose project `vf-finance-auth`, source worktree/site,
  PostgreSQL 15 + Redis 7 + existing PHP image; no production credentials.
  307 migrations applied locally; baseline auth/permissions 26 tests / 70 assertions PASS.

## Pre-flight interfaces

- Stage 1 JSON/session/CSRF → Stage 2 context → Stage 3 application adapters:
  TASK §6 is authoritative; global transport normalizes 401, login adapter
  supplies invalid-credentials UX locally.
- Stage 4 host routing preserves Stage 1 host+path and original Origin;
  local tests require different hosts, not merely ports.
- Stage 2 owner/ROLE_OWNER full module permissions retained; ordinary missing
  role template remains fail-closed. Company ADMIN is not system ROLE_ADMIN.

## Review status

- Documentation internal fresh review B0/I0, two MINOR fixed; external not
  required for documentation. Implementation reviews pending.

## Exact next action

- Implement real backend HTTP tests/routing and CI; Stage 4 base is the Stage 3 commit in git log.

## Inspect first on resume

- `TASK.md`, `plan.md`, this checkpoint, git status/log.
- `/tmp/finance-auth-compose.yml`, baseline logs listed above.

## Stage 1 Work items

- 1.1 complete: host/path matcher, early deny, JSON handlers/exception mapping,
  CSRF bootstrap. RED 12/12 boundary tests (missing routes/HTML), GREEN thereafter.
- 1.2 complete: Passport credentials, CSRF badge, role checker, throttling, Symfony
  logout event validation. RED 7/7 auth tests, GREEN. Regression + coverage +
  permission suite 35 tests / 152 assertions PASS. Focused PHPStan 0 errors;
  fixer 0/18; diff check PASS.
- Internal review: B0/I0; fixed priority ordering before review (Security exception
  listener must handle 401/403 before SPA error renderer; preserve error logging).
- External round 1 B0/I1/M1; logging IMPORTANT reproduced RED and fixed; role-revocation MINOR test added. Final 37 tests /165 assertions, PHPStan and CS clean. Fixed without re-run. Stage 1 closed; see stages/stage-1.md.

## Stage 2 progress

- 2.1: account/admin session and company discovery implemented. Before code,
  eight functional tests RED (404); now account/member/no-company, admin identity,
  pagination and removal behavior GREEN.
- 2.2: company selection requires current owner/active membership, Origin/CSRF;
  foreign/deleted/disabled/random IDs preserve current context. Account OpenAPI
  area + generated shared types and legacy schema; both generation/check targets PASS.
- Combined module tests: 48 /227 assertions PASS; focused PHPStan clean; both
  apps typecheck/lint clean. New generated types excluded only from formatting/lint,
  verified by reproducible generator check.
- CI of Stage 1 revealed ModuleWriteGateCoverage route-policy entries missing for
  logout (and now selection). Targeted check RED, added exact route/controller and
  authenticated-self policy with functional test pointers, GREEN 2 tests/15 assertions.
- One combined run overlapped PHPStan kernel compilation and throttle test failed;
  narrowed throttle check PASS, sequential module run PASS. Symfony limiter keys
  use container.build_hash; avoid concurrent kernel rebuilds during auth tests.
- Next: Stage 2 complete diff review/external review, then report/commit and Stage 3.

- Stage 2 external review: REVIEW_GREEN, one round B0/I0/M1. Fixed schema permissions to explicit required properties; both generators/checks pass. Stage 2 closed.

## Stage 3 progress

- 3.1 session adapters/store and generation+Abort guards implemented. Unit RED
  absent module; UI RED expected /login but initial placeholder remained.
  Initial typecheck and 27 tests per app GREEN; company reconciliation test added.
- Session orchestration remains per app, pure cookie/CSRF transport shared;
  password is never persisted. Old adapter unchanged.
- 3.2 login/protected shell/company pagination implemented. UI mock glob initially
  intercepted Vite source /packages/api, narrowed to exact realm API prefix.
  Shared EmptyState container changed to div to allow valid block content.
- Next: finish dev/preview UI checks, review Stage 3, then actual HTTP Stage 4.

- Stage 3 closed: both typecheck/lint/build; Vitest 28 account/27 admin; dev and preview E2E 12 account/10 admin each. Internal B0/I0; external at handoff.
