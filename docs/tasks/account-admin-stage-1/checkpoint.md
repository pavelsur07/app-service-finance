# Current checkpoint

**Phase:** Stage 2 / Work item 2.1
**Status:** implementing
**Branch:** `feat/account-admin-auth`
**Stage base commit:** `34ef67560afe0675e082d0c607cfb553ca2b6a17`

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

- Implement account/admin session and paginated company discovery, starting with functional RED tests. Stage 2 base is the Stage 1 commit recorded in git log.

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
