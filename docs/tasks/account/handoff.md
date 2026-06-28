# Account P0 implementation handoff

## Summary

- Implemented P0 creation invariant: every new company created through public/admin owner-account creation, `/company/new`, and `AccountBootstrapper` now gets an owner `CompanyMember`.
- Implemented P0 member-management hardening: disable/enable actions, owner/self disable guards, active membership access check, disabled-member invite accept rejection.
- Fixed `CompanyRepository::getAllActiveCompanyIds()` to query the real `companies` table until a future CompanyStatus exists.
- Did not implement P1/HIGH items: user block/unblock, company suspension, migrations, `security.yaml`, voters/RBAC rollout, admin blocking UI.

## Files changed

- `site/src/Company/Application/Service/CompanyOwnerMembershipCreator.php` — new
- `site/src/Company/Application/DisableCompanyMemberAction.php` — new
- `site/src/Company/Application/EnableCompanyMemberAction.php` — new
- `site/src/Company/**` — focused wiring/repository/controller updates
- `site/tests/**/Company/**` — unit/functional/integration coverage
- `docs/tasks/account/stages/stage-1.md` — new
- `docs/tasks/account/stages/stage-2.md` — new

## Migrations

- None.
- No destructive DB changes.

## Public API / contracts

- No new public endpoint.
- Existing member disable/enable routes now enforce owner/self guards.
- Existing invite accept now rejects disabled existing membership.

## Checks

- `make site-test-unit` — OK, 1213 tests, 7451 assertions; existing PHPUnit output includes 1 warning and 1 deprecation.
- Targeted DB functional/integration PHPUnit filter — OK, 17 tests, 96 assertions; existing Symfony validator deprecations remain.
- Targeted unit PHPUnit filter — OK, 23 tests, 139 assertions.
- `git diff --check -- <changed paths>` — OK.

## Notes

- `make codex-test-unit-filter ...` could not run in this environment because `codex-prepare` expects host `php` on PATH. Docker-based checks were used instead.
- DB tests needed a local `DATABASE_URL` host override from `site-postgres` to the running container name because one-off `site-php-cli` containers did not resolve the Compose service alias here.
- Existing unrelated working-tree changes under `docs/tasks/ingestion/*` and other untracked docs were not touched.

## Owner review focus

- Confirm the chosen disabled invite behavior: disabled member cannot regain access via invite; owner must use explicit enable.
- Confirm P1 ordering before any migration/security work: user lifecycle, company suspension, audit trail, scoped authorization.
