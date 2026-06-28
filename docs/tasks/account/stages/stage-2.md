### Stage 2: Company member access hardening — DONE

**Risk:** MEDIUM
**Next action:** STOP, owner review required

#### What was done
- Added Application actions for enabling/disabling company members.
- Kept controllers thin and delegated state changes to actions.
- Added guards against disabling self, company owner user, or `ROLE_OWNER` membership.
- Changed member access checks to require active membership for invited users.
- Made invite accept reject an existing disabled member instead of silently accepting and keeping inconsistent access.
- Fixed `CompanyRepository::getAllActiveCompanyIds()` to read from the real `companies` table until CompanyStatus exists.
- Updated stale Finance command/test comments that referenced the old broken active-company SQL.

#### Files changed
- `site/src/Company/Application/DisableCompanyMemberAction.php` — new
- `site/src/Company/Application/EnableCompanyMemberAction.php` — new
- `site/src/Company/Controller/CompanyMemberController.php` — modified
- `site/src/Company/Repository/CompanyMemberRepository.php` — modified
- `site/src/Company/Service/CompanyInviteManager.php` — modified
- `site/src/Company/Infrastructure/Repository/CompanyRepository.php` — modified
- `site/src/Finance/Command/RecalcPlRegisterCommand.php` — modified comments only
- `site/tests/Unit/Company/DisableCompanyMemberActionTest.php` — new
- `site/tests/Unit/Company/EnableCompanyMemberActionTest.php` — new
- `site/tests/Unit/Company/CompanyInviteManagerTest.php` — modified
- `site/tests/Functional/Company/CompanyMemberAccessTest.php` — new
- `site/tests/Integration/Company/CompanyPersistenceTest.php` — modified
- `site/tests/Integration/Finance/RecalcPlRegisterCommandTest.php` — modified comments only
- `site/tests/Builders/Company/CompanyInviteBuilder.php` — modified test default expiry

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `make site-test-unit` — OK, 1213 tests, 7451 assertions; existing PHPUnit output includes 1 warning and 1 deprecation
- `docker compose run --rm site-php-cli sh -lc 'export DATABASE_URL=...; php bin/phpunit --filter "CompanyCreateFlowTest|CompanyMemberAccessTest|AdminUserCreateAccountTest|UserCreateAccountControllerTest|PublicRegistrationFlowTest|InviteRegistrationFlowTest|CompanyPersistenceTest|RecalcPlRegisterCommandTest"'` — OK, 17 tests, 96 assertions; existing form deprecations remain
- `git diff --check -- <changed paths>` — OK

#### Risks / reviewer focus
- No audit fields were added for disable/enable because that requires schema work and belongs to P1.
- `getAllActiveCompanyIds()` currently treats all rows in `companies` as active until CompanyStatus/suspension is introduced.
- One-off Docker CLI containers do not resolve the `site-postgres` service alias in this environment; DB checks used the existing container name as a local test workaround.

#### Open questions
- none
