### Stage 2: UI владельца — шаблоны ролей и назначение — DONE

**Risk:** MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously to Stage 3

#### Stage scope

- Stage base commit: `77325276`
- Work items completed: `2.1`, `2.2`, `2.3`, `2.4`, F1/F2

#### What was done

- `CompanyInvite.accessRole` (ManyToOne → `CompanyRole`, nullable), getter/setter, builder `withAccessRole`.
- Миграция `Version20260811130000` (перенумерована в Stage 3) — `company_invites.role_id` + индекс + FK `ON DELETE SET NULL` (идемпотентный DO-блок).
- CRUD `CompanyRoleController` (`/company/roles`) только для владельца компании:
  - `index` — системные шаблоны (read-only) + шаблоны компании;
  - `new`/`create` и `edit`/`update` — форма с матрицей прав «нет/чтение/запись» по 5 модулям;
  - `delete` — только свои шаблоны, запрещено удалять назначенные участникам или активным инвайтам.
- `CompanyRoleType` — динамическая subform `permissions[module]` через `ChoiceType expanded=true`.
- Twig-шаблоны `company/role/index.html.twig`, `company/role/form.html.twig`; ссылка на шаблоны в `company/company_member/index.html.twig`.
- `CompanyMemberController::setAccessRole` — POST `/company/users/{memberId}/access-role`:
  - владелец only, CSRF;
  - нельзя менять шаблон у OWNER-строкового участника;
  - шаблон должен принадлежать активной компании или быть системным;
  - защита последнего admin: нельзя снять admin:write с единственного активного участника (учитываются OWNER-строка, accessRole с admin:write, legacy OPERATOR, владелец компании).
- `CompanyInviteOperatorType` — селект `accessRole` (системные + шаблоны компании, default = «Полный доступ»).
- `CompanyInviteManager::inviteOperator` — сохраняет `accessRole` в инвайт (обновляет при повторном приглашении).
- `CompanyInviteManager::acceptInvite` — назначает `accessRole` из инвайта, fallback на системный «Полный доступ».
- F2: `DashboardSnapshotService` проверяет `module.finance.read`; без права возвращает пустой snapshot (context + alerts/warnings), finance-виджеты не строятся; ключ кэша включает флаг.
- Аудит-лог через `LoggerInterface` для создания/обновления/удаления шаблона и смены шаблона участника.
- Тесты: `CompanyRoleControllerTest`, `CompanyMemberAccessRoleTest`, `CompanyRoleTypeTest`, обновлены `DashboardSnapshotControllerTest`, `DashboardSnapshotServiceTest`, `CompanyInviteManagerTest`.

#### Files changed

- `site/migrations/Version20260811130000.php` — new
- `site/src/Company/Controller/CompanyRoleController.php` — new
- `site/src/Company/Form/CompanyRoleType.php` — new
- `site/templates/company/role/index.html.twig` — new
- `site/templates/company/role/form.html.twig` — new
- `site/tests/Functional/Company/CompanyRoleControllerTest.php` — new
- `site/tests/Functional/Company/CompanyMemberAccessRoleTest.php` — new
- `site/tests/Unit/Company/Form/CompanyRoleTypeTest.php` — new
- Modified:
  - `site/src/Company/Entity/CompanyInvite.php`
  - `site/src/Company/Entity/CompanyRole.php`
  - `site/src/Company/Controller/CompanyMemberController.php`
  - `site/src/Company/Form/CompanyInviteOperatorType.php`
  - `site/src/Company/Repository/CompanyInviteRepository.php`
  - `site/src/Company/Repository/CompanyMemberRepository.php`
  - `site/src/Company/Repository/CompanyRoleRepository.php`
  - `site/src/Company/Service/CompanyInviteManager.php`
  - `site/src/Analytics/Application/DashboardSnapshotService.php`
  - `site/src/Analytics/Api/Response/SnapshotResponse.php`
  - `site/templates/company/company_member/index.html.twig`
  - `site/tests/Builders/Company/CompanyInviteBuilder.php`
  - `site/tests/Functional/Analytics/DashboardSnapshotControllerTest.php`
  - `site/tests/Unit/Analytics/DashboardSnapshotServiceTest.php`
  - `site/tests/Unit/Company/CompanyInviteManagerTest.php`

#### Definition of Done

- [x] CompanyInvite.accessRole + миграция
- [x] CRUD CompanyRole (системные + свои) с матрицей прав
- [x] Назначение шаблона участнику с защитами OWNER/last-admin
- [x] Выбор шаблона в инвайте + fallback Full Access при accept
- [x] F2: dashboard snapshot gated по finance-read
- [x] Unit/functional тесты
- [x] `REVIEW_GREEN` внешней ревизии

#### Baseline

- Stage 1 final: `make site-test-unit` OK (1827 tests)

#### Checks

- `make site-test-unit` — OK (1836 tests, 10617 assertions)
- `docker compose run --rm site-php-cli php bin/phpunit tests/Functional/Company tests/Functional/Analytics` — OK (63 tests, 406 assertions)
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:functional` — OK (447 tests, 2637 assertions)
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:integration` — 8 pre-existing failures (Verified on base `77325276`; unrelated to Stage 2)
- `make site-test-db-rebuild` — OK, миграции до `Version20260811130000` (перенумерована в Stage 3)
- `doctrine:schema:update --dump-sql --env=test` — по новым объектам чисто

#### Internal automatic review

- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: dead code `CompanyRoleController::defaultPermissions()`, inline FQCN `FormInterface`, unused imports
- FOLLOW-UP:
  - Unique index `company_role(company_id, name)` — перенесён из Stage 1, сделать в Stage 3/4 или отдельным follow-up.
  - Debug*-контроллеры marketplace — Stage 4.
  - Repository `save()/remove()` с `flush()` — consistent with surrounding `Company/Controller` legacy pattern; refactor if module gains Action layer.
  - N+1 in `hasAnotherAdminAfterChange` — negligible given typical member counts.
  - Unvalidated UUID in `find()` on write paths — same class as pre-existing `revokeInvite`; harden in follow-up.

#### External Claude Code review

- Iterations: 7
- Result: REVIEW_GREEN
- Confirmed findings fixed:
  - `OWNER_ID` assignable via direct `POST /company/users/{memberId}/access-role` — added explicit server-side rejection + regression test.
  - Stored XSS in delete-confirm `onsubmit` via `role.name` — moved name to `data-role-name` attribute.
  - Duplicated assignable-role query logic — extracted `CompanyRoleRepository::createAssignableForCompanyQueryBuilder()` / `findAssignableForCompany()`.
  - `EntityType query_builder` returned array instead of `QueryBuilder` — fixed with QB-returning repository method.
  - Dashboard warmup command silently losing finance data — added `bool $forSystemContext` to `DashboardSnapshotService::getSnapshot()` and passed `forSystemContext: true` from `AnalyticsDashboardWarmupCommand`; regression test added.
- Rejected findings with reason: none

#### Review fixes applied

- `CompanyMemberController::setAccessRole` rejects `SystemCompanyRoles::OWNER_ID` server-side.
- `site/templates/company/role/index.html.twig` delete confirm uses `data-role-name` to avoid JS-context XSS.
- `CompanyRoleRepository::createAssignableForCompanyQueryBuilder()` centralizes assignable-role query; reused by `CompanyInviteOperatorType` and `CompanyMemberController::resolveAvailableRoles()`.
- `DashboardSnapshotService::getSnapshot()` accepts `bool $forSystemContext = false`; CLI warmup passes `true` to bypass per-viewer gating.
- Added regression tests: `CompanyMemberAccessRoleTest::testOwnerCannotAssignOwnerRoleTemplateToMember`, `DashboardSnapshotServiceTest::testSystemContextBuildsFullSnapshotRegardlessOfUserPermissions`.
- Cleaned imports and dead code in `CompanyRoleController`.

#### Risks / reviewer focus

- `CompanyInviteManager::assertOwner` всё ещё использует object-identity (`$company->getUser() !== $actor`) — pre-existing, вне scope Stage 2; возможен отказ владельцу при разных инстансах User в identity map. Рекомендуется отдельный фикс.
- Legacy-fallback для OPERATOR без accessRole остаётся открытым до Stage 3/4 write-гейтов.
- `doctrine:schema:update --dump-sql` имеет большой pre-existing drift по другим таблицам — не связан с Stage 2.

#### Checkpoint

- `docs/tasks/module-access-roles/checkpoint.md` updated
- exact next action: Stage 3 — write-гейты finance/deals/catalog/admin

#### Open questions

- none

#### Expected owner response

- not required; continuing autonomously
