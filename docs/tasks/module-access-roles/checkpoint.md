# Checkpoint — module-access-roles

## Current checkpoint

**Phase:** Stage 1, final external review
**Status:** reviewing (все MINOR-фиксы итерации 3 внесены, проверки зелёные; финальная внешняя ревизия в процессе)
**Stage base commit:** a5e5a4d4 (branch `codex/module-access-roles` от master)
**Current Work item:** none (1.1–1.7 реализованы)
**Owner gate:** no

### Completed
- Phase 0: разведка инфраструктуры, план (docs/tasks/module-access-roles/plan.md).
- Baseline: `make site-test-unit` — OK (1798 tests) до изменений.
- 1.1 — Module/AccessLevel enums, ModuleAccess, ModuleAccessMap, атрибут PublicAccess.
- 1.2 — CompanyRole entity + CompanyRoleRepository + миграция Version20260808120000
  (таблица company_role, company_members.role_id FK ON DELETE SET NULL, 5 системных шаблонов,
  бэкфилл OWNER→«Владелец», OPERATOR→«Полный доступ»; expand-only).
- 1.3 — CompanyMember.accessRole (role сохранён как fallback) + ActiveCompanyService::getActiveMembership()
  (+ мемоизация на запрос с инвалидацией по session-значению, ResetInterface).
- 1.4 — ModuleAccessVoter (только resolver в конструкторе).
- 1.5 — ModuleAccessSubscriber (kernel.controller, fail-closed) + #[PublicAccess] на public-контроллерах.
- 1.6 — ControllerAccessCoverageTest (src/**/Controller + *Controller.php, только классы с Route).
- 1.7 — unit/functional-тесты; CompanyOwnerMembershipCreator проставляет шаблон «Владелец».
- Review-fixes (external #1): см. Review status ниже; HomeRedirectController для «/».

### Current diff / affected files
- Новые: site/src/Company/Security/{Module,AccessLevel,ModuleAccess,ModuleAccessMap,ModuleAccessResolver,
  ModuleAccessSubscriber,ModuleAccessVoter,PublicAccess,SystemCompanyRoles}.php,
  site/src/Company/Entity/CompanyRole.php, site/src/Company/Repository/CompanyRoleRepository.php,
  site/migrations/Version20260808120000.php,
  site/src/Shared/Controller/HomeRedirectController.php,
  site/tests/Unit/Company/Security/{AccessLevelTest,ModuleAccessTest,ModuleAccessMapTest,
  ModuleAccessResolverTest,ModuleAccessVoterTest,ControllerAccessCoverageTest}.php,
  site/tests/Functional/Company/{ModuleAccessTest,SystemCompanyRolesTest}.php,
  site/tests/Support/Db/SystemCompanyRolesSeeder.php.
- Изменённые: CompanyMember.php, ActiveCompanyService.php, CompanyOwnerMembershipCreator.php,
  CompanyMemberBuilder.php, Finance/Controller/HomeController.php (finance home переехал на /dashboard),
  tests/Functional/Finance/HomeUiModeTest.php, tests/Functional/Company/Controller/LoginControllerTest.php,
  8 контроллеров (+#[PublicAccess]), 3 unit-теста под новый конструктор creator'а.
- Удалённые: site/templates/home/dashboard.html.twig (stub, заменён переносом finance home на /dashboard).

### Checks and baseline (после всех review-fixes, перед финальной внешней ревизией)
- `make site-test-unit` — OK (1827 tests, 10562 assertions; baseline 1798).
- `docker compose run --rm site-php-cli php bin/phpunit tests/Functional/Company` — OK (38 tests, 204 assertions).
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:functional` — OK (431 tests, 2573 assertions).
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:integration` — OK (924 tests, 4251 assertions).
- `doctrine:schema:update --dump-sql --env=test` — по company_role и company_members.role_id расхождений нет.
- `php-cs-fixer --dry-run` по новому CompanyRoleMigrationParityTest — 0 правок.
- `php -l` по всем изменённым файлам — OK.

### Review status
- external review #1: 6 IMPORTANT + MINOR — все подтверждены и исправлены (см. историю ниже).
- external review #2: I1 + M2–M8 — все исправлены:
  I1/M5) ControllerAccessCoverageTest: IS_INSTANCEOF для Route/PublicAccess (76 контроллеров
    на Routing\Annotation\Route-алиасе раньше молча пропускались), факт routed-классов = 200,
    нижняя граница assertGreaterThan(180); логика method-level PublicAccess согласована с subscriber'ом
    (класс классифицирован, только если class-level PublicAccess ИЛИ exempt ИЛИ map ИЛИ все routed-методы помечены);
  M2) docblock SystemCompanyRoles → Version20260808120000;
  M3) _sidebar.html.twig: подсветка «Главная» — current_route in ['app_home_index','app_dashboard_index'];
  M4) комментарий в CompanyOwnerMembershipCreator переформулирован (отсутствие seed-строки, не миграции);
  M6) ModuleAccessResolver: инвалидация мемоизации парой (user, companyId) — тот же ключ, что у ActiveCompanyService;
  M7) write-assertion тест разделён: прямые checker-assertions с push/pop в finally, HTTP — отдельным тестом;
  M8) allows($module, NONE) → false всегда + unit-тест.
- unresolved findings: none

### Exact next action
- Internal review обновлённого diff от a5e5a4d4, external Claude Code review #2 до REVIEW_GREEN,
  Stage Report (docs/tasks/module-access-roles/stages/stage-1.md), коммит/push/Draft PR (owner_gate: no → Stage 2).

### Files to inspect first on resume
- docs/tasks/module-access-roles/plan.md
- site/src/Company/Security/ (все 9 файлов)
- site/src/Shared/Controller/HomeRedirectController.php
- site/migrations/Version20260808120000.php
- site/tests/Functional/Company/ModuleAccessTest.php
