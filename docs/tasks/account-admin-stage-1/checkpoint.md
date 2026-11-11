# Current checkpoint

**Phase:** Stage 4 / Work item 4.1
**Status:** implementing
**Branch:** `feat/account-admin-auth`
**Stage base commit:** `0a255776cb41dc77cb3c2cc6504ea6b77be34aec`
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

## Stage 4 progress

- Routing contract RED 4 failures; exact Traefik routers, reserved nginx paths,
  request_uri-based CORS mapping and mandatory CI auth E2E gate implemented.
- Real HTTP stack uses isolated spa_e2e environment/database/Redis, test-only
  dotenv path and trusted ephemeral CA; no production values. Production router
  rules are read from the actual Compose labels into test Traefik configuration.
- First actual dev run: login/cookie/selection passed, legacy login expectation
  was wrong (existing /login always renders200), now checks protected / redirect
  to finance. Unknown Vite API paths initially fell through to HTML (RED); exact
  middleware boundary added to dev and preview.
- CI Stage2 found old CompanyFacade unit mocks still referenced old two-query
  implementation (5 errors). Targeted RED; repository now offers array accessor
  over the same query and unit tests cover mapping/access by current user;
  deduplication stays covered against real DB. Targeted 6 tests/44 assertions PASS.
- Next: finish real dev/preview/HTTPS, full handoff gates, fresh and external review.

- Real dev and preview suites GREEN for both apps (2 tests each/mode), with
  Redis cookies, session rotation, owner company selection, independent sibling
  and legacy sessions, logout/replay, CSRF/Origin and host/path rejection.
- Fresh internal reviewer: B0/I2/M2. Both IMPORTANT reproduced RED and fixed:
  selection requires JSON (form/XML415), reconciliation obeys definitive401/403.
  Targeted PHP2/21, Vitest30 account/29 admin GREEN. Minors: CI account types
  drift check and ARCHITECTURE context docs added. Follow-up review clean.
- Full handoff gates launched once, sequential (session27625): site-stan,
  site-cs-check, site-cs-strict-types, site-test-unit, site-test. Logs
  /tmp/finance-auth-final-site-*.log. Do not run another full suite concurrently.
- Legacy lint/build/test:unit GREEN92 tests. New frontend final lint/tests green
  before reconciliation edit; format/lint/build to repeat for that change.
- Production HTTP suite session12452 /tmp/finance-auth-stage4-production-fixed.log.
  First run stopped before build because nested frontend.sh rejected MODE=production;
  pass MODE=dev only to the image-build helper, keep auth test mode production.

- Handoff full CS and strict-types PASS4072 files. PHPStan full run incomplete:
  parallel worker jobs did not finish; host swap full during overlapping image/
  browser workload. Production browser timed out including newPage creation.
  Use full-scope serial PHPStan via ignored var/stan-serial.neon after current
  suite ends, then repeat HTTPS without concurrent heavy work; no baseline changes.
- Actionlint current and base both show same pre-existing SC2129/SC2086 shell
  findings. New routing/release tests all pass (4+6 and deploy shell contract).

- Final external round1 REVIEW_GREEN B0/I0/M3. Safe minors being fixed: preserve
  authenticated tree on focus refresh; retain network message after ambiguous
  login+401; bounded company access lookup reuses shared query instead of loading
  all entities. UI minors reproduced RED2 then targeted verification pending.
- Additional self-review found encoded SPA paths were not detected before legacy
  firewall whereas Symfony router rawurldecodes paths. Classified BLOCKER (host
  boundary contract). Unit request-event regression RED3; detect one decoded
  path for deny decision, keeping canonical realm matching; GREEN4/10 assertions
  including server500 JSON sanitization. Real HTTP encoded-path assertions added.
  Fresh review follow-up and external round2 required for this boundary fix.

- Final reviewer delta confirmed encoded-path fix. New bounded access query
  initially retained ORDER BY from list query; PostgreSQL read-only diagnostic
  reproduced aggregate failure. Cleared ordering and outer DISTINCT; bounded
  COUNT(DISTINCT id) remains on shared access predicate. Real selection suite
  to rerun after full tests complete (avoid concurrent DB tests).
- UI minor fixes GREEN32 account/31 admin. Additional encoded-path unit GREEN4/10.
- Full site-test is still running (~80% of8765); wait session27625, then run
  changed backend targeted tests, full serial PHPStan, real production HTTPS and
  image smoke sequentially. External final round1 REVIEW_GREEN; round2 required
  because the subsequently discovered boundary BLOCKER was fixed.

- Full handoff site-test PASS8765 tests/49102 assertions. Final targeted backend
  after encoded-path/count-query fixes PASS42/223. Fresh reviewer confirms no
  open B/I, including count SQL correction and picker recovery.
- Picker page2 becoming invalid after revocation: browser RED2 → GREEN2;
  resets GET pagination to1 only, never retries POST.
- Full-scope serial PHPStan running session21153, log
  /tmp/finance-auth-final-stan-serial.log. External final round2 running19308,
  log /tmp/finance-auth-review-round2.log; previous round1 report saved
  /tmp/finance-auth-external-round1.txt.
- Next after serial analysis: both API schema drift checks, production HTTPS
  repeat, production image smoke, frontend lint/build after latest formatting;
  then Stage4 report/handoff commit+push and CI verification before Ready.

- External final round2 REVIEW_GREEN B0/I0/M2. MINOR1 rejected selection context
  recovery: RED3 cases403/404/422 → restore authoritative session without POST
  retry, Vitest account35 GREEN. MINOR2 (avoid company list focus refetch) rejected:
  membership/list may change while user and active company IDs stay the same;
  TASK focus revalidation and revocation behavior require refreshing that list.
  Brief loading state is intentional; no additional cache abstraction introduced.
- External final total2 rounds, all BLOCKER/IMPORTANT clear; safe minors fixed
  or technically rejected as recorded. Stage1/2 external reviews remain separately
  documented. Last internal follow-up clean through picker page fallback.

- Ruling: push a task-owned checkpoint commit to the existing Draft PR to execute
  the newly added CI workflow. This is required CI verification, not Stage4
  closure or Ready/handoff; local serial PHPStan/HTTPS are still pending.
  Production deploy is impossible on this feature-branch pull_request event.
