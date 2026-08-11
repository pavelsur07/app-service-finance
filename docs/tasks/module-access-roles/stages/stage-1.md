### Stage 1: Модель и ядро модульных ролей — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously to Stage 2

#### Stage scope

- Stage base commit: `a5e5a4d4`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`, `1.6`, `1.7`

#### What was done

- Введена матрица доступа «модуль × уровень» (Module/AccessLevel enums) и атрибуты `module.<group>.(read|write)`.
- Создана сущность `CompanyRole` (шаблон прав) + `CompanyRoleRepository`, миграция `Version20260811120000` (перенумерована в Stage 3).
- Добавлена связь `CompanyMember.accessRole → CompanyRole` (`role_id` nullable, ON DELETE SET NULL); строковая роль `company_members.role` сохранена как BC-fallback.
- Системные шаблоны: «Владелец», «Полный доступ», «Финансист», «Менеджер маркетплейсов», «Менеджер по продажам» (UUID фиксированы, совпадают в SQL и `SystemCompanyRoles`).
- `ActiveCompanyService` получил `getActiveMembership()` + per-request мемоизацию с инвалидацией по ключу (пользователь, active_company_id) и `ResetInterface`.
- `ModuleAccessResolver` — мемоизированное разрешение прав; владелец компании → полный доступ; чужой шаблон/неизвестный уровень → deny; legacy-fallback для участников без accessRole.
- `ModuleAccessVoter` + `ModuleAccessSubscriber` (kernel.controller, fail-closed): любой не классифицированный контроллер без `#[PublicAccess]` получает 403 + warning.
- `ModuleAccessMap` — namespace/exact-классификация всех 200 routed-контроллеров по модулям; `App\Analytics\`, `App\Admin\`, `App\Mcp\` и др. exempt.
- Public-роуты (`/login`, `/register`, `/invite`, `/api/public/*`, `/api/health/*`, `/_health`, `/telegram/webhook`) помечены `#[PublicAccess]`.
- `/` заменён на `HomeRedirectController` — редирект на первый доступный модуль; финансовый home переехал на `/dashboard`.
- Тесты: 6 unit-файлов в `tests/Unit/Company/Security/` (resolver, voter, map, access level, coverage, migration parity), 2 functional-файла в `tests/Functional/Company/`, seeder `SystemCompanyRolesSeeder`.

#### Files changed

- `site/src/Company/Security/{Module,AccessLevel,ModuleAccess,ModuleAccessMap,ModuleAccessResolver,ModuleAccessSubscriber,ModuleAccessVoter,PublicAccess,SystemCompanyRoles}.php` — new
- `site/src/Company/Entity/CompanyRole.php` — new
- `site/src/Company/Repository/CompanyRoleRepository.php` — new
- `site/src/Shared/Controller/HomeRedirectController.php` — new
- `site/migrations/Version20260811120000.php` — new
- `site/tests/Unit/Company/Security/{AccessLevelTest,ModuleAccessTest,ModuleAccessMapTest,ModuleAccessResolverTest,ModuleAccessVoterTest,ControllerAccessCoverageTest,CompanyRoleMigrationParityTest}.php` — new
- `site/tests/Functional/Company/{ModuleAccessTest,SystemCompanyRolesTest}.php` — new
- `site/tests/Support/Db/SystemCompanyRolesSeeder.php` — new
- `site/src/Company/Entity/CompanyMember.php` — modified (accessRole)
- `site/src/Shared/Service/ActiveCompanyService.php` — modified (getActiveMembership, memoization, ResetInterface)
- `site/src/Company/Application/Service/CompanyOwnerMembershipCreator.php` — modified (sets owner template)
- `site/tests/Builders/Company/CompanyMemberBuilder.php` — modified (`withAccessRole`)
- `site/src/Finance/Controller/HomeController.php` — modified (`/` → `/dashboard`)
- `site/tests/Functional/Finance/HomeUiModeTest.php`, `site/tests/Functional/Company/Controller/LoginControllerTest.php` — modified
- `site/templates/home/dashboard.html.twig` — deleted
- 8 controllers marked `#[PublicAccess]` (`SecurityController`, `RegistrationController`, `InviteController`, `HealthController`, `Analytics\Api\V1\HealthController`, `TelegramWebhookController`, `PublicCashflowReportController`, `PublicPlReportController`)
- 3 unit tests updated for new constructor of `CompanyOwnerMembershipCreator`

#### Definition of Done

- [x] Module enum, карта неймспейсов, CompanyRole + миграция
- [x] Voter + fail-closed subscriber + PublicAccess
- [x] Backward compatibility: OWNER/OPERATOR → полный доступ, строковая роль не удалена
- [x] Тесты покрытия, resolver/voter, functional 403/200
- [x] Миграция применяется на чистой БД без schema-drift для новых объектов
- [x] `REVIEW_GREEN` внешней ревизии

#### Baseline

- `make site-test-unit` — OK (1798 tests, 10439 assertions)

#### Checks

- `make site-test-unit` — OK (1827 tests, 10562 assertions)
- `docker compose run --rm site-php-cli php bin/phpunit tests/Functional/Company` — OK (38 tests, 204 assertions)
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:functional` — OK (431 tests, 2573 assertions)
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:integration` — OK (924 tests, 4251 assertions)
- `doctrine:migrations:migrate` на чистой тестовой БД — OK, seed на месте
- `doctrine:schema:update --dump-sql --env=test` — по `company_role`/`company_members.role_id` расхождений нет
- `php-cs-fixer --dry-run` по изменённым файлам — 0 правок
- `php -l` по новым/изменённым файлам — OK

#### Internal automatic review

- Iterations: 2
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed:
  - `ControllerAccessCoverageTest`: `IS_INSTANCEOF` для `Route`/`PublicAccess`, нижняя граница 180 (факт 200).
  - `ModuleAccessMap`: `ProjectDirectionController` → FINANCE, `CounterpartySearchController` → EXEMPT_EXACT.
  - `_sidebar.html.twig`: подсветка «Главная» по обоим роутам.
  - `ActiveCompanyService`: мемоизация с ключом пользователя, повторное использование членства.
  - `ModuleAccessResolver`: owner-проверка по id, guard `NONE` как required-уровня, инвалидация кэша по (user, companyId).
  - `CompanyRoleMigrationParityTest`: защита от дрейфа SQL-сидера vs `SystemCompanyRoles::definitions()`.
- FOLLOW-UP:
  - Unique index `company_role(company_id, name)` — Stage 2 UI.
  - Debug*-контроллеры marketplace — Stage 4 оценить/вынести под ROLE_ADMIN.
  - `App\Analytics\` dashboard snapshot — Stage 2 (F2), не Stage 5.
  - Pre-existing проектный schema drift (иные таблицы) — отдельная задача.
  - UI Stage 2: скрывать выбор шаблона у OWNER-участников.

#### External Claude Code review

- Iterations: 4
- Result: REVIEW_GREEN
- Confirmed findings fixed: все 6 IMPORTANT итерации 1, I1 + M2–M8 итерации 2, M1–M8 итерации 3.
- Rejected findings with reason: none

#### Review fixes applied

- См. разделы Internal review и External review выше; все фиксы внутри scope Stage 1.

#### Risks / reviewer focus

- Fail-closed subscriber: любой новый контроллер без классификации получит 403 — by design; coverage-test ловит это на CI.
- Legacy-fallback по строковой роли: сохраняет доступ существующих участников, но должен быть закрыт в Stage 2 (F1): invite-accept должен назначать шаблон, удаление назначенного шаблона запрещено, очистка шаблона = нет доступа.
- Deploy ordering: код Stage 1 использует новую колонку `company_members.role_id`; миграция должна выполниться до rolling update приложения (Stage `independently_deployable: no`).
- Sub-requests не гейтятся (нет `render(controller())`); задокументировано.

#### Checkpoint

- `docs/tasks/module-access-roles/checkpoint.md` updated
- exact next action: Stage 2 — UI владельца (шаблоны ролей и назначение участникам)

#### Open questions

- none

#### Expected owner response

- not required; continuing autonomously
