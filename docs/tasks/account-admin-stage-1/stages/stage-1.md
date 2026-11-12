# Stage 1: Symfony SPA authentication — DONE

Risk: HIGH-LOCAL. Stage base: `34ef67560afe0675e082d0c607cfb553ca2b6a17`.
Work items: 1.1, 1.2. Company/session data endpoints belong to Stage 2.

## Result

- Exact host/path boundary before legacy firewalls; independent spa_account and
  spa_admin contexts using existing provider/password hasher.
- Anonymous CSRF bootstrap, Passport login, Symfony logout with CSRF/Origin
  validation before invalidation; uniform JSON and no-store responses.
- Role hierarchy checked before admin login succeeds; revoked role invalidates
  the session. Existing limiter: five attempts per 15 minutes.
- Existing main/admin form logins and module permission logic preserved.

## Files

New Shared/Security/Spa classes, Shared/Controller/Spa endpoints, Shared API
request/response DTOs, Shared/Infrastructure/Http/SpaHttpSubscriber and functional
tests. Config security/services/framework and exact ModuleAccessMap logout entry.
No production routing, migrations or frontend changes in this Stage.

## Verification

- Phase 0: new apps typecheck/lint/test/build PASS (13 tests each); legacy
  lint/build/test:unit PASS (92 tests). Initial Alpine runner could not load the
  existing glibc Rollup module; rerun with matching Node Debian passed, no lock change.
- Isolated local PostgreSQL/Redis; 307 migrations applied; baseline existing
  authentication/permissions 26 tests / 70 assertions PASS.
- RED: boundary 12 failures and authentication 7 failures before implementation.
- GREEN: new SPA + legacy login/admin throttling + controller access coverage +
  permission resolver: 37 tests / 165 assertions PASS.
- Focused PHPStan clean; scoped CS check clean, diff check clean. Full gates
  deliberately reserved for handoff.
- BrowserKit automatically adds Referer: missing-origin test now clears browser
  history to exercise absence of both headers, not a valid same-origin fallback.

## Review

Internal complete Stage diff: B0/I0 open; exception listener ordered after
Symfony security handling and error logging, before default HTML rendering.
External Claude medium, one round: B0/I1/M1. IMPORTANT confirmed: expected 4xx
generated ERROR logs; regression proved RED, exception severity mapping fixed,
test verifies no ERROR-or-higher record. SpaHttpException restricted to 4xx so
future server failures cannot be silently downgraded. MINOR fixed: explicit
test for revoked ROLE_ADMIN on an existing session. Suite and PHPStan/CS green.
Result: **fixed without re-run** per AGENTS §7.2. Open B/I: zero.

## Boundaries and next

Functional tests use Symfony mock sessions. Real Secure/host-only cookies and
cross-origin browser isolation remain Stage 4 acceptance; no production login
performed. OpenAPI account area/types are integrated with context endpoints in
Stage 2. Continue Stage 2 automatically.
