# Stage 4: real backend acceptance and release integration — DONE

**Risk:** HIGH-LOCAL; the named routing/CI edits were explicitly included in
the approved implementation plan. No manual production changes.
**Stage base commit:** `0a255776cb41dc77cb3c2cc6504ea6b77be34aec`
**Work items:** 4.1, 4.2. Product checkpoint: `0943d7c07`.

## Delivered and Definition of Done

- [x] Exact host+API-prefix Traefik routers preserve host and path; nginx rejects
  reserved API/internal/PHP paths instead of returning the SPA. CORS suppression
  survives Symfony internal redirects. Legacy routing remains compatible.
- [x] Actual Symfony/PostgreSQL/Redis tests in dev, preview and production nginx
  images, with a trusted ephemeral HTTPS CA. Three host-only sessions coexist in
  one browser; rotation, logout/replay, CSRF/Origin and wrong/encoded paths tested.
- [x] Required CI acceptance job, both generated API drift checks, production
  artifact smoke and backend-before-SPA release order.
- [x] Local commands and rollout/rollback in apps/README.md; no new dependencies,
  business screens, migration or financial formula changes.
- [x] Final whole-task internal/external review and local handoff gates complete.

## Files

`docker-compose.prod.yml`, both nginx configurations, `.github/workflows/deploy.yml`,
`Makefile`, `bin/frontend-auth*`, `docker-compose.frontend-auth*.yml`, test browser
Dockerfile, both Vite/auth Playwright configs and `tests/auth/` cover infrastructure.
Routing tests are in `tests/shell/frontend-auth-routing-test.py`.
Late review fixes touch the SPA boundary subscriber/context, company selection
and shared company access query, both frontend session adapters and company picker.
`ARCHITECTURE.md`, `CLAUDE.frontend.md`, `apps/README.md` describe current contracts.

## Checks and baseline delta

Baseline and Stage 1–3 checks are recorded in their reports/checkpoint.
Final PHP commands use the isolated local Compose project documented there.

| Check | Final result |
|---|---|
| `make site-test-unit` | PASS 4609 tests / 25550 assertions |
| `make site-test` | PASS 8765 tests / 49102 assertions |
| `make site-cs-check site-cs-strict-types` | PASS 4072 files |
| Full project PHPStan | PASS, full scope and unchanged baseline; serial retry below |
| Changed backend API/security/Facade suites | PASS 42 tests / 223 assertions |
| `make front-api-types-check api-types-check` | PASS both schemas |
| `make front-typecheck front-lint front-test front-build` | PASS; final Vitest account35/admin31 |
| `make front-e2e MODE=dev`, `MODE=preview` | PASS account12/admin10 each, desktop/mobile |
| `make front-auth-e2e MODE=dev`, `MODE=preview`, `MODE=production` | PASS account2/admin2 each, actual backend |
| `make front-prod-test` | PASS account14/admin12 |
| Legacy `npm run lint`, `build`, `test:unit` in Node Debian | PASS, 92 tests |
| Routing / release / deploy-shell contracts | PASS 4 / 6 / shell suite |
| Shell syntax, Compose validation, actionlint structural | PASS |

The first full `make site-stan` run left parallel workers unfinished while image
build/browser work exhausted local memory/swap. The first production browser run
also timed out, including browser page creation. No assertions or rule baseline
were relaxed: sequential execution passed. PHPStan retry used an ignored config
including phpstan.dist.neon with maximumNumberOfProcesses1, processTimeout600:
`vendor/bin/phpstan analyse -c var/stan-serial.neon --no-progress --memory-limit=2G`.
Full actionlint reports the same SC2129/SC2086 shell warnings on task base and HEAD;
structural lint passes with shellcheck disabled. Existing warnings are follow-up.

No new Vite entry or mount point; finance kit tokens/styles stay shared. Final
production JS: account 277.96 kB (gzip 87.60), admin 275.81 kB (gzip 86.99),
shared CSS 73.77 kB (gzip 13.40). Baseline JS: account 267.42 kB (gzip 84.15),
admin 267.43 kB (gzip 84.14); CSS 73.63 kB (gzip 13.36). JS growth is below 4%. Login/session/company code accounts for bundle growth; no
new runtime dependencies. Browser acceptance covers login/reload/company/logout,
keyboard navigation, loading/error/empty states and desktop/mobile overflow.

## Internal review

Complete diff reviewed against backend/frontend checklists in a fresh read-only
review session, with follow-ups after fixes. Final metrics: self-review B1; fresh
review I3, M2, all fixed. No open BLOCKER/IMPORTANT. Regression evidence:

- Encoded API prefix could enter legacy routing: RED3 → GREEN boundary tests,
  then actual HTTP wrong-host assertions. Decode once for denial detection only.
- JSON-only selection: form/XML RED200 → GREEN415.
- Ambiguous mutations must obey authoritative401/403: RED2 → GREEN stores.
- Shared count query inherited list ordering: PostgreSQL reproduction, remove
  ordering/outer DISTINCT, real selection suite GREEN42/223.
- Picker stale page: RED2 → GREEN2, reset GET pagination without POST retry.

CI also identified exact route-policy entries and legacy unit mocks during
development; both corrected and verified, documented in checkpoint.

## External review

Final whole-task Claude review: two rounds, both REVIEW_GREEN, B0/I0. A second
round followed the subsequently discovered encoded-path BLOCKER fix. Context
included the complete apps/packages/infrastructure diff omitted by the script's
default filter. Stage1/2 earlier reviews are recorded separately.

Round1 M3 fixed: preserve authenticated tree on focus, retain ambiguous-login
network error, bounded company lookup. Round2 M2: rejected selection now restores
authoritative context and keeps the error (RED3 → GREEN account35). Proposed
removal of company-list focus refetch rejected: membership can change without
user/active company IDs changing; refreshing is required by the specification.
Fresh internal reviewer confirmed these final decisions without further findings.

## Release / limitations

PR2971 remains unmerged until its own owner decision. PR2970 was docs-only and
merged separately. Live PR checks verify the final commit; local evidence above
is complete. No production login/mutation performed. No migration is added.
Read-only acceptance after deploy: SHA/health, both HTTPS public routes, anonymous
csrf/session behavior without token output, host/path rejection, legacy login.

Next: finish latest CI, Ready handoff and request merge + deploy for PR2971.
