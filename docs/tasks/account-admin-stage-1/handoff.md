# Handoff — account/admin authentication (in verification)

PR #2971, branch feat/account-admin-auth, base34ef67560. Documentation PR2970
merged separately; owner approval2970 does not authorize merging this code PR.

## Delivered scope

Existing Symfony User/provider/passwords, independent host-only Redis sessions
for account/admin, JSON CSRF/login/logout/session APIs. Account company discovery
and selection reuse existing access policy and module rights. Admin requires
system ROLE_ADMIN. React shells add login, retry/forbidden/no-company and company
pagination. Public about/404 remain free of data requests. Legacy API/UI imports
and login continue unchanged. No migrations or new financial rules.

Same-origin exact Traefik routers and nginx CORS/fallback boundary; isolated
HTTP fixture stack plus required CI job (dev, preview, production HTTPS with
trusted CA). Account OpenAPI types drift checked in CI. Local commands and
rollout/rollback documented in apps/README.md.

## Verification status

- New app typecheck/lint/build and Vitest30 account/29 admin PASS.
- Legacy frontend lint/build/test:unit PASS92 tests.
- UI desktop/mobile dev+preview: account12/admin10 each PASS before final
  reconciliation-only fix; pure regression suites pass after that fix.
- Real HTTP dev+preview: 2 tests each app/mode PASS, actual DB/Redis and three
  independent host sessions, including legacy main login and logout isolation.
- Full PHP CS/strict-types4072 files PASS; unit4609 tests/25550 assertions PASS.
- Full site-test in progress. Full PHPStan parallel workers incomplete on local
  resource pressure; serial full-scope retry pending. No baseline changes.
- Production HTTPS run timed out under concurrent resource pressure; rerun pending.
- Routing4/release6/deploy-shell contracts PASS. Actionlint structure PASS;
  full actionlint has same pre-existing SC2129 and SC2086 as task base.

## Review

Stage1 external one round B0/I1/M1, fixed without re-run; Stage2 external one
round REVIEW_GREEN B0/I0/M1, minor schema fixed. Fresh final internal reviewer
B0/I2/M2, all fixed, follow-up clean. IMPORTANT fixes proved RED→GREEN: JSON-only
selection (form/XML415) and definitive401/403 after ambiguous mutations.
Full final external review in progress. CI found two gaps during development:
exact route-policy entries and legacy facade unit mocks; both fixed and verified.

## Remaining

Complete outstanding checks, final external review/fixes, Stage4 report and
commit/push/Ready. Merge/deploy only after new owner decision. No production
login/mutation performed. Post-deploy acceptance is read-only.
