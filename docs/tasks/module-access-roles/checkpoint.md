# Checkpoint — module-access-roles

## Current checkpoint

**Phase:** Stage 2
**Status:** final-checks
**Stage base commit:** 77325276 (Stage 1 committed and pushed)
**Current Work item:** none (Stage 2 complete, awaiting commit)
**Owner gate:** no

### Completed
- Stage 1 — DONE:
  - модель, voter, subscriber, миграция, тесты;
  - internal/external reviews REVIEW_GREEN (4 итерации);
  - Stage Report: docs/tasks/module-access-roles/stages/stage-1.md;
  - commit `77325276`, push, Draft PR #2315 (https://github.com/pavelsur07/app-service-finance/pull/2315).
- Stage 2 — implementation complete:
  - `CompanyInvite.accessRole` entity + builder + migration `Version20260808130000`;
  - `CompanyRole` CRUD controller + form + Twig templates (index/form);
  - member access-role assignment endpoint + invite template selection;
  - F1/F2: invite fallback to Full Access, last-admin protection, dashboard snapshot gating by `module.finance.read`;
  - unit + functional tests added/adapted.
- Stage 2 — external review fixes applied:
  - `CompanyMemberController::memberHasAdminWrite()` теперь исключает участника-владельца из проверки "другой admin";
  - `CompanyInviteManager` tenant-check: отклоняет чужой шаблон при создании инвайта, fallback на Full Access при приёме;
  - `OWNER_ID` исключён из selectable-списков ролей (member invite + role assignment) и отклоняется server-side в `setAccessRole`;
  - `CompanyRole` entity-валидация имени (not empty, max 128);
  - централизован `CompanyRoleRepository::createAssignableForCompanyQueryBuilder()`;
  - XSS fix в `company/role/index.html.twig` (delete confirm через `data-role-name`);
  - `DashboardSnapshotService::getSnapshot()` с `forSystemContext` для CLI warmup;
  - regression tests для OWNER_ID assignment, system-context snapshot и name validation.

### Current diff / affected files
- New:
  - `site/migrations/Version20260808130000.php`
  - `site/src/Company/Controller/CompanyRoleController.php`
  - `site/src/Company/Form/CompanyRoleType.php`
  - `site/templates/company/role/index.html.twig`
  - `site/templates/company/role/form.html.twig`
  - `site/tests/Functional/Company/CompanyMemberAccessRoleTest.php`
  - `site/tests/Functional/Company/CompanyRoleControllerTest.php`
  - `site/tests/Unit/Company/Form/CompanyRoleTypeTest.php`
- Modified:
  - `site/src/Analytics/Api/Response/SnapshotResponse.php`
  - `site/src/Analytics/Application/DashboardSnapshotService.php`
  - `site/src/Company/Controller/CompanyMemberController.php`
  - `site/src/Company/Entity/CompanyInvite.php`
  - `site/src/Company/Entity/CompanyRole.php`
  - `site/src/Company/Form/CompanyInviteOperatorType.php`
  - `site/src/Company/Repository/CompanyInviteRepository.php`
  - `site/src/Company/Repository/CompanyMemberRepository.php`
  - `site/src/Company/Repository/CompanyRoleRepository.php`
  - `site/src/Company/Service/CompanyInviteManager.php`
  - `site/templates/company/company_member/index.html.twig`
  - `site/tests/Builders/Company/CompanyInviteBuilder.php`
  - `site/tests/Functional/Analytics/DashboardSnapshotControllerTest.php`
  - `site/tests/Unit/Analytics/DashboardSnapshotServiceTest.php`
  - `site/tests/Unit/Company/CompanyInviteManagerTest.php`

### Checks and baseline
- `php -l` on all changed/new PHP files — OK (no syntax errors).
- `make site-test-unit` — OK (1836 tests, 10617 assertions).
- `docker compose run --rm site-php-cli php bin/phpunit tests/Functional/Company` — OK (55 tests, 261 assertions).
- `docker compose run --rm site-php-cli php bin/phpunit tests/Functional/Analytics` — OK (5 tests, 101 assertions).
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:functional` — OK (447 tests, 2637 assertions).
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:integration` — 8 pre-existing failures (924 tests, 4238 assertions), reproduced on base commit `77325276` and unrelated to Stage 2.
- `make site-test-db-rebuild` — OK, migrated up to `Version20260808130000`.
- `doctrine:schema:update --dump-sql --env=test` — large pre-existing diff, no `role_id` or `company_role` related statements.

### Review status
- Stage 1: REVIEW_GREEN.
- Stage 2: REVIEW_GREEN (7 iterations).
- FOLLOW-UP зафиксированы (см. Stage 2 Report).

### Exact next action
- Commit + push Stage 2.
- Update Draft PR #2315.
- Continue autonomously to Stage 3: write-гейты finance/deals/catalog/admin.

### Files to inspect first on resume
- `site/src/Company/Controller/CompanyRoleController.php`
- `site/src/Company/Controller/CompanyMemberController.php`
- `site/src/Company/Service/CompanyInviteManager.php`
- `site/src/Company/Entity/CompanyRole.php`
- `site/src/Company/Form/CompanyRoleType.php`
