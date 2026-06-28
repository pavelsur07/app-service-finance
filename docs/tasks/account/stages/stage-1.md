### Stage 1: Company owner creation invariant — DONE

**Risk:** MEDIUM
**Next action:** continue autonomously

#### What was done
- Added a shared `CompanyOwnerMembershipCreator` for new company creation with mandatory `CompanyMember::ROLE_OWNER`.
- Reused it in public/admin owner-account creation, `/company/new`, and `AccountBootstrapper`.
- Bound `CompanyMember` to its Doctrine repository so `getRepository(CompanyMember::class)` exposes project repository methods.
- Fixed registration functional tests to submit real forms with CSRF-backed sessions.

#### Files changed
- `site/src/Company/Application/Service/CompanyOwnerMembershipCreator.php` — new
- `site/src/Company/Service/CompanyOwnerAccountCreator.php` — modified
- `site/src/Company/Controller/CompanyController.php` — modified
- `site/src/Company/Application/Service/AccountBootstrapper.php` — modified
- `site/src/Company/Entity/CompanyMember.php` — modified
- `site/tests/Unit/Company/CompanyOwnerMembershipCreatorTest.php` — new
- `site/tests/Unit/Company/CompanyOwnerAccountCreatorTest.php` — modified
- `site/tests/Unit/Admin/Application/CreateAccountActionTest.php` — modified
- `site/tests/Functional/Company/CompanyCreateFlowTest.php` — new
- `site/tests/Functional/Company/PublicRegistrationFlowTest.php` — modified
- `site/tests/Functional/Company/InviteRegistrationFlowTest.php` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'CompanyOwnerMembershipCreatorTest|DisableCompanyMemberActionTest|EnableCompanyMemberActionTest|CompanyOwnerAccountCreatorTest|CreateAccountActionTest|CompanyInviteManagerTest|CompanyInviteEntityTest'` — OK, 23 tests, 139 assertions
- `docker compose run --rm site-php-cli sh -lc '... php -l ...'` — OK, no syntax errors in changed files

#### Risks / reviewer focus
- `CompanyOwnerMembershipCreator::persistCompanyWithOwnerMembership()` is intended for newly-created companies; owner transfer remains out of scope.
- Full bootstrap consistency for cashflow/PL/money accounts remains a separate decision.

#### Open questions
- none
