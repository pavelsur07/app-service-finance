# Account / Company module plan

Дата анализа: 2026-06-28
Дата реализации P0: 2026-06-28

## Статус реализации

### P0 — DONE

- [x] Единое создание новой компании с обязательным owner membership вынесено в `CompanyOwnerMembershipCreator`.
- [x] `CompanyOwnerMembershipCreator` подключён к `CompanyOwnerAccountCreator`, `CompanyController::new()` и `AccountBootstrapper`.
- [x] `CompanyMember` привязан к `CompanyMemberRepository` в Doctrine metadata, чтобы `getRepository(CompanyMember::class)` возвращал проектный repository.
- [x] Disable/enable участника вынесены в Application actions: `DisableCompanyMemberAction`, `EnableCompanyMemberAction`.
- [x] Добавлены guard-правила: нельзя отключить self, `Company.user` owner и member с `ROLE_OWNER`.
- [x] Access check для invited member в `CompanyMemberController` использует только active membership.
- [x] Accept invite для существующего disabled member теперь возвращает отказ, а восстановление доступа остаётся явным owner-действием `enable`.
- [x] `CompanyRepository::getAllActiveCompanyIds()` исправлен под реальную таблицу `companies`; до появления `CompanyStatus` все rows считаются активными.
- [x] Добавлены/обновлены unit, functional и integration tests для P0-сценариев.
- [x] Stage reports сохранены в `docs/tasks/account/stages/stage-1.md` и `docs/tasks/account/stages/stage-2.md`.
- [x] Handoff сохранён в `docs/tasks/account/handoff.md`.

### Осталось вне P0

- [ ] Глобальная блокировка пользователя (`UserStatus`, block/unblock actions, login/runtime guard).
- [ ] Company suspension/status с миграцией и влиянием на `ActiveCompanyService`/workers.
- [ ] Audit trail для block/unblock/suspend/activate/disable/enable.
- [ ] Admin UI для блокировок.
- [ ] Company-scoped authorization/RBAC rollout.
- [ ] `ProjectDirection` и `ReportApiKey` не смешивались с P0 и должны идти отдельными задачами.

## Задача

Изучить модуль `Company`, сценарии создания, управления и блокировки аккаунтов, определить:

- какие базовые функции уже есть;
- какие функции отсутствуют или реализованы неполно;
- каким поэтапным планом безопасно довести модуль до полноценного account management.

Под "аккаунтом" в этом плане понимается SaaS-аккаунт клиента: `User` + `Company` + `CompanyMember`. Денежные счета `Cash/MoneyAccount` не входят в scope.

## Изученные области

- `site/src/Company/Entity/User.php`
- `site/src/Company/Entity/Company.php`
- `site/src/Company/Entity/CompanyMember.php`
- `site/src/Company/Entity/CompanyInvite.php`
- `site/src/Company/Service/CompanyOwnerAccountCreator.php`
- `site/src/Company/Service/CompanyInviteManager.php`
- `site/src/Company/Application/Service/AccountBootstrapper.php`
- `site/src/Company/Controller/RegistrationController.php`
- `site/src/Company/Controller/CompanyController.php`
- `site/src/Company/Controller/CompanyMemberController.php`
- `site/src/Company/Controller/InviteController.php`
- `site/src/Company/Facade/CompanyFacade.php`
- `site/src/Admin/Application/CreateAccountAction.php`
- `site/src/Admin/Controller/CreateAccountController.php`
- `site/src/Admin/Controller/UserController.php`
- `site/src/Shared/Service/ActiveCompanyService.php`
- `site/config/packages/security.yaml`
- релевантные шаблоны `site/templates/company/*`, `site/templates/admin/users/*`
- unit-тесты `site/tests/Unit/Company/*`, `site/tests/Unit/Admin/Application/CreateAccountActionTest.php`
- `ARCHITECTURE.md`, `PATTERNS.md`, `site/src/Billing/README.md`

## Что уже есть

### Пользователь и роли

- `User` хранит `id`, `email`, `roles`, `password`, `createdAt`.
- Email нормализуется через `User::normalizeEmail()` и уникален на уровне ORM/БД.
- `User::getRoles()` всегда добавляет `ROLE_USER`.
- Есть CLI-команды:
  - `app:user:promote-super-admin` — выдать `ROLE_SUPER_ADMIN`;
  - `app:user:reset-password` — сбросить пароль и вывести временный пароль.
- В `security.yaml` настроены роли `ROLE_COMPANY_USER`, `ROLE_COMPANY_OWNER`, `ROLE_SUPER_ADMIN`, отдельный admin firewall и общий доступ `^/` только для `ROLE_USER`.

Ограничение: у `User` нет lifecycle/status-поля. Нельзя штатно заблокировать логин, пометить аккаунт как suspended/blocked, хранить причину блокировки, дату, автора действия.

### Создание owner-аккаунта

- Публичная регистрация `/register` без invite создаёт пользователя-владельца через `CompanyOwnerAccountCreator`.
- `CompanyOwnerAccountCreator`:
  - хеширует пароль;
  - ставит `ROLE_COMPANY_OWNER`;
  - создаёт `Company`;
  - создаёт активного `CompanyMember` с ролью `OWNER`;
  - отправляет `SendRegistrationEmailMessage`, если включено.
- Admin может создать owner-account через `/admin/users/new-account`.
- Admin-сценарий идёт через `Admin\Application\CreateAccountAction` -> `CompanyFacade::createOwnerAccount()`.
- `CompanyFacade::createOwnerAccount()` зафиксирован в `ARCHITECTURE.md` как публичный контракт Company-модуля.
- Unit-тесты покрывают создание owner-account и отсутствие `ROLE_ADMIN` у создаваемого пользователя.

P0-статус: инвариант owner membership закрыт. `CompanyOwnerMembershipCreator` используется в `CompanyOwnerAccountCreator`, `CompanyController::new()` и `AccountBootstrapper`, поэтому новая компания всегда получает `CompanyMember OWNER`.

Оставшееся ограничение: `CompanyOwnerAccountCreator` всё ещё не использует полный `AccountBootstrapper`, поэтому дефолтные cashflow/PL/money accounts могут создаваться не тем же путём, что bootstrap. Это отдельное решение о seed-policy.

### Компания

- `Company` хранит `id`, `name`, `inn`, `user` owner, `financeLockBefore`, `taxSystem`.
- Owner определяется через `Company::getUser()`.
- Есть CRUD UI для компаний:
  - список своих компаний;
  - создание;
  - редактирование;
  - удаление;
  - выбор active company через session key `active_company_id`.
- `financeLockBefore` используется как финансовая блокировка периода, но это не блокировка аккаунта.

P0-статус: технический баг в `CompanyRepository::getAllActiveCompanyIds()` закрыт — метод читает реальные ids из `companies`.

Оставшееся ограничение: у `Company` нет статуса активности/блокировки. До появления `CompanyStatus` все сохранённые companies считаются активными, поэтому полноценная company suspension остаётся P1/HIGH задачей.

### Участники компании

- `CompanyMember` связывает `Company` и `User`.
- Есть роли `OWNER` и `OPERATOR`.
- Есть статусы `ACTIVE` и `DISABLED`.
- Owner может:
  - смотреть участников;
  - приглашать operator по email;
  - отзывать invite;
  - отключать/включать участника.
- `ActiveCompanyService` учитывает только active membership при выборе company для приглашённых пользователей.
- Unit-тесты покрывают `CompanyMember::disable()` / `enable()`.

Ограничения:

- `CompanyMember::role` и `status` — строковые константы, не enum.
- Нет `disabledAt`, `disabledBy`, `disableReason`, `enabledAt`, аудита изменения.
- P0 закрыто: `DisableCompanyMemberAction` запрещает отключать self, `Company.user` owner и member с `ROLE_OWNER`.
- P0 закрыто: `CompanyMemberController::assertCompanyMemberAccess()` использует active membership для invited member access.
- P0 закрыто: если disabled member принимает invite, `CompanyInviteManager::acceptInvite()` возвращает отказ; восстановление доступа идёт через explicit enable.
- Виджет active company (`_active_company_widget.html.twig`) показывает только `app.user.companies`, то есть owned-компании, и не показывает компании, где пользователь является invited member.

### Приглашения

- `CompanyInvite` хранит company, email, role, token hash, expiresAt, createdAt, createdBy, accepted/revoked поля.
- Invite token хранится только как hash.
- Pending invite на тот же email переиспользуется: токен обновляется и срок продлевается.
- Invite можно принять существующим пользователем или через регистрацию по invite.
- `acceptInvite()` проверяет:
  - token hash;
  - pending status;
  - email пользователя совпадает с email invite.
- Invite можно отозвать.
- Unit-тесты покрывают создание invite, renew и accept.

Ограничения:

- Invite поддерживает только `OPERATOR`; нет выбора роли.
- Нет rate limit на invite endpoints.
- Страница просмотра invite показывает expired/revoked invite без явного UX-блокера до POST.
- Нет защиты от приглашения уже активного участника как отдельного доменного результата.

### Admin management

- Admin видит список пользователей, дату регистрации, id, роли.
- Admin может создать owner-account.
- Admin может изменить глобальную роль пользователя.
- Удаление пользователя есть как `UserDeletionService`, но route и кнопка закомментированы.

Ограничения:

- Нет admin block/unblock пользователя.
- Нет admin suspend/activate компании.
- Нет фильтрации/индикации account status.
- `UserDeletionService` не участвует в UI и выглядит как устаревший destructive-path; включать его без отдельной ревизии нельзя.

### Billing

- В Billing уже есть `SubscriptionStatus::{TRIAL, ACTIVE, GRACE, SUSPENDED, CANCELED}`.
- `Billing\AccessManagerInterface` задуман как единая точка доступа/лимитов.

Ограничение: текущий `Billing\Service\AccessManager` — stub, все проверки возвращают allow. Billing-статусы пока не ограничивают доступ к Company/account.

## Главные пробелы базового account management

1. Нет глобальной блокировки пользователя.
   Нужен `User` lifecycle: active/blocked, причина, actor, timestamp, запрет логина и запрет дальнейшей работы для уже авторизованных сессий.

2. Нет блокировки/suspension компании.
   Нужен company-level lifecycle, который влияет на `ActiveCompanyService`, UI, фоновые jobs и `CompanyFacade::getAllActiveCompanyIds()`.

3. Глобальные Symfony-роли не равны ролям в активной компании.
   `ROLE_COMPANY_OWNER` хранится на `User`, а ownership в продукте company-scoped. Пользователь может быть owner одной компании и operator другой, но `#[IsGranted('ROLE_COMPANY_OWNER')]` даст owner-доступ глобально. Нужна company-scoped authorization policy/voter или обязательная проверка active company role.

4. Создание компании не единообразно.
   Разные пути создают разный набор связанных данных: owner-account создаёт `CompanyMember OWNER`, а `/company/new` и `AccountBootstrapper` нет.

5. Управление участниками не защищает важные инварианты.
   Нужно запретить отключение owner/self без явно согласованного сценария передачи владельца, корректно обрабатывать повторный invite disabled member, использовать active membership в access checks.

6. Нет audit trail для блокировок и управления доступом.
   Для финансового SaaS нужны минимум actor, timestamp, reason для block/unblock/suspend/activate/disable/enable.

7. Нет единого Application-слоя для account operations.
   Часть логики находится прямо в контроллерах (`CompanyController`, `CompanyMemberController`), хотя проектный паттерн — Controller -> Action -> Domain/Repository.

## Что необходимо добавить

### Обязательный минимум

- `UserStatus` или эквивалентное lifecycle-поле для `User`.
- Block/unblock user actions с причиной и actor id.
- Symfony `UserCheckerInterface` или эквивалентная security-проверка, чтобы blocked user не мог войти.
- Runtime guard для уже активных blocked-сессий.
- `CompanyStatus` или явный `isActive/isSuspended` для `Company`.
- Suspend/activate company actions.
- Исправить `CompanyRepository::getAllActiveCompanyIds()` под реальную таблицу и новый company status.
- Единый `CreateCompanyAction` / `CompanyCreator`, который всегда создаёт owner-member и запускает нужный bootstrap.
- Company-scoped authorization для owner/operator прав.
- Admin UI/actions для block/unblock user и suspend/activate company.
- Тесты на запрет доступа blocked user / suspended company / disabled member.

### Желательно добавить рядом

- `CompanyMemberRole` и `CompanyMemberStatus` enum вместо строковых констант.
- Audit fields для `CompanyMember` disable/enable.
- Отдельный `AccountAuditLog` или использование существующего Shared audit-подхода, если он подходит.
- Список доступных компаний: owned + active memberships.
- Корректный UI для invited member company switcher.
- Invite result types для already-member / disabled-member / email-mismatch / expired.
- Защита от self-block/self-demote в admin, если нет второго admin/super-admin.

### Отложить до отдельного решения

- Жёсткое удаление пользователей/компаний.
- Связку Billing `SubscriptionStatus::SUSPENDED` -> Company suspension.
- Перенос всех legacy company-owned entity с `Company $company` на `string $companyId`.
- Массовую замену всех `#[IsGranted('ROLE_COMPANY_OWNER')]` без предварительной инвентаризации affected routes.

## Best practices и use cases по приоритету

| Приоритет | Use case | Что добавить | Best practice | Почему важно | Risk | Acceptance criteria |
|---|---|---|---|---|---|---|
| P0 — DONE | Единообразно создать owner-account из public registration и admin | Единый `CreateOwnerAccountAction` / `CreateCompanyAction`, создание `Company`, `CompanyMember OWNER`, обязательный bootstrap данных | Один write-use-case = один Application Action; контроллеры только собирают input и вызывают action; все внешние модули идут через `CompanyFacade` | Сейчас разные пути создания дают разный набор связанных данных | MEDIUM | Любой owner-account имеет owner membership; public/admin tests проходят; нет дублирования создания company в контроллерах |
| P0 — DONE | Пользователь не может работать после отключения в компании | Усилить `CompanyMember` access checks: только `ACTIVE` membership даёт доступ к invited company | Проверять доступ по active company + active membership, не по `find($id)` и не по глобальной роли | Disabled member сейчас частично может оставаться видимым в проверках | MEDIUM | Disabled member не может выбрать company и получить данные; owner по `Company.user` остаётся доступен до отдельной company/user блокировки |
| P0 — DONE | Запретить неконсистентное отключение владельца | Guard в disable-member action: нельзя отключить owner/self без отдельного transfer-owner сценария | Доменные инварианты держать в Action/Policy, не в Twig/UI | Отключение owner-member создаёт противоречие между `Company.user` и `CompanyMember` | MEDIUM | POST disable owner/self возвращает доменную ошибку/403; тест покрывает запрет |
| P0 — DONE | Понять, какие компании активны для workers/CLI | Исправить/спланировать `CompanyRepository::getAllActiveCompanyIds()` под `companies` и будущий status | Репозиторий Company должен быть единственным источником списка active company ids | Сейчас метод ссылается на несуществующие `company.is_active` | HIGH при миграции, LOW для фикса SQL без нового поля | Метод возвращает реальные company ids; есть тест на active list |
| P1 | Заблокировать пользователя глобально | `UserStatus`, `blockedAt`, `blockedBy`, `blockReason`, `BlockUserAction`, `UnblockUserAction` | Не удалять пользователя; использовать explicit lifecycle state + audit; blocked user denied на login и runtime | Это базовая account-blocking функция | HIGH | Blocked user не может войти; активная сессия получает deny/logout; admin не может заблокировать себя |
| P1 | Заблокировать компанию целиком | `CompanyStatus` или `isActive/isSuspended`, `SuspendCompanyAction`, `ActivateCompanyAction` | Company lifecycle отдельно от user lifecycle; не смешивать manual suspension и billing suspension без policy | Нужно отключать весь tenant, включая owner и фоновые задачи | HIGH | Suspended company не выбирается active; не попадает в `getAllActiveCompanyIds()`; UI/API возвращают controlled deny |
| P1 | Админ управляет блокировками безопасно | Admin UI/API: block/unblock user, suspend/activate company, reason field, CSRF | Все state-changing admin actions только POST + CSRF + audit; focused controllers per action | Без UI операции останутся ручными и неаудируемыми | HIGH | Admin видит status; действия защищены CSRF; audit содержит actor/reason/time |
| P1 | Хранить историю действий по доступу | `AccountAuditLog` или интеграция с существующим Shared audit | Append-only audit для block/unblock/suspend/activate/disable/enable; не перетирать историю полями entity | Для финансового SaaS нужны разбор инцидентов и ответственность | MEDIUM | Каждое access-management действие пишет audit event без секретов/PII сверх необходимого |
| P1 | Разделить глобальные роли и роли в компании | Company-scoped permission checker/voter для owner/operator | Глобальные Symfony-роли использовать только для coarse access; бизнес-права считать в контексте active company | `ROLE_COMPANY_OWNER` сейчас даёт owner-доступ глобально | HIGH | User owner компании A и operator компании B не имеет owner-доступа в B |
| P2 | Переключать все доступные компании | Read-model доступных компаний: owned + active memberships; обновить company switcher | UI должен использовать Application/Query read-model, а не `app.user.companies` напрямую | Invited companies сейчас не попадают в виджет выбора | MEDIUM | Пользователь видит owned и invited active companies; disabled/suspended скрыты или помечены согласно policy |
| P2 | Корректно обработать повторный invite | Явные result types: already active member, disabled member, renewed invite, created invite | Use case возвращает доменный результат, контроллер переводит его в flash/response | Сейчас disabled existing member может принять invite без восстановления доступа | MEDIUM | Повторный invite active member не создаёт мусор; disabled-member case имеет согласованное поведение |
| P2 | Типизировать роли и статусы участников | `CompanyMemberRole` и `CompanyMemberStatus` backed enums | Enum вместо строковых констант для всех finite state/status fields | Меньше ошибок статусов и проще мигрировать authorization policy | MEDIUM | Entity и формы используют enum; старые значения мигрированы безопасно |
| P2 | Управлять ролями участников | Use cases: change member role, transfer ownership | Роль меняется только через policy/action с guard against no-owner state | Без transfer-owner нельзя безопасно отключать/передавать владельца | HIGH | В компании всегда есть ровно один или минимум один owner согласно выбранному правилу |
| P3 | Связать billing suspension с доступом | Policy mapping Billing status -> access mode | Billing status не должен напрямую менять Company status без явного precedence rule | Сейчас Billing `AccessManager` stub и не enforcement layer | HIGH | Есть документированная матрица `TRIAL/ACTIVE/GRACE/SUSPENDED/CANCELED`; tests на write/read deny |
| P3 | Удаление аккаунта/компании | Soft delete/deactivation вместо hard delete; hard delete только отдельной задачей | Для финансовых данных предпочтителен reversible disable/archive, не каскадное удаление | `UserDeletionService` destructive и route закомментирован | HIGH | Нет hard delete в обычном admin flow; archive/delete policy утверждена отдельно |

Рекомендуемый порядок реализации: сначала P0, затем P1. P2 улучшает UX и типобезопасность, но не должен блокировать security foundation. P3 требует отдельных бизнес-решений и owner review.

## Предлагаемый staged plan

### Stage 1: Зафиксировать текущие инварианты создания компании — DONE

**Risk:** MEDIUM
**Stage report:** `docs/tasks/account/stages/stage-1.md`

Цель: сделать создание компании единообразным без изменения auth/RBAC.

Работы:

- [x] Добавить/выделить `CreateCompanyAction` или `CompanyCreator` в `Company/Application` — реализовано как `CompanyOwnerMembershipCreator`.
- [x] Использовать его в `CompanyOwnerAccountCreator`, `CompanyController::new()` и при необходимости `AccountBootstrapper`.
- [x] Всегда создавать `CompanyMember OWNER` для owner.
- [ ] Явно решить, какие seed-операции обязательны при создании компании: balance, cashflow, PL, money accounts — оставлено отдельным решением, текущие seed-пути не расширялись.
- [x] Добавить unit/functional тесты на оба пути: public/admin owner-account и `/company/new`.

Ожидаемые файлы:

- `site/src/Company/Application/...`
- `site/src/Company/Service/CompanyOwnerAccountCreator.php`
- `site/src/Company/Controller/CompanyController.php`
- `site/src/Company/Application/Service/AccountBootstrapper.php`
- `site/tests/Unit/Company/*`
- `site/tests/Functional/Company/*` или ближайший существующий test namespace

### Stage 2: Укрепить управление участниками и invite-flow — DONE

**Risk:** MEDIUM
**Stage report:** `docs/tasks/account/stages/stage-2.md`

Цель: сделать текущий per-company disable реальной и непротиворечивой функцией управления доступом.

Работы:

- [x] Вынести enable/disable member в Application action.
- [x] Запретить отключение owner-member/self без отдельного сценария передачи владельца.
- [x] В `assertCompanyMemberAccess()` использовать active membership там, где нужен доступ активного участника.
- [x] Для disabled existing member при accept invite: вернуть доменную ошибку; re-enable остаётся явным owner-действием.
- [ ] Обновить UI/flash messages для already-member/disabled-member cases — не делалось в P0, потому что текущий scope ограничен use case guards.
- [x] Добавить тесты на disabled member access и invite edge cases.

Ожидаемые файлы:

- `site/src/Company/Application/...`
- `site/src/Company/Service/CompanyInviteManager.php`
- `site/src/Company/Controller/CompanyMemberController.php`
- `site/tests/Unit/Company/CompanyInviteManagerTest.php`
- `site/tests/Functional/Company/*`

### Stage 3: Добавить глобальную блокировку пользователя

**Risk:** HIGH

Причина HIGH: миграция БД + изменение auth/security поведения.

Работы:

- Добавить `UserStatus` enum или эквивалентные поля в `User`.
- Добавить поля аудита блокировки: `blockedAt`, `blockedBy`, `blockReason` или отдельную audit-сущность.
- Создать Doctrine migration.
- Добавить `BlockUserAction` / `UnblockUserAction`.
- Подключить Symfony user checker к `main` и `admin` firewall или согласованный runtime guard.
- Обеспечить logout/deny для уже заблокированных сессий.
- Добавить admin self-protection: нельзя заблокировать текущего пользователя; возможно нельзя оставить систему без admin.
- Добавить unit/functional tests для логина и доступа blocked user.

Ожидаемые файлы:

- `site/src/Company/Entity/User.php`
- `site/src/Company/Enum/UserStatus.php`
- `site/src/Company/Application/...`
- `site/src/Company/Security/...` или `site/src/Shared/Security/...`
- `site/config/packages/security.yaml`
- `site/migrations/*`
- `site/tests/Unit/Company/*`
- `site/tests/Functional/Security/*`

STOP: owner review required before migration/security changes.

### Stage 4: Добавить блокировку/suspension компании

**Risk:** HIGH

Причина HIGH: миграция БД + изменение tenant access + влияние на workers/CLI.

Работы:

- Добавить company status (`ACTIVE`, `SUSPENDED`, возможно `ARCHIVED`) или явное `isActive`.
- Добавить audit поля suspension.
- Исправить `CompanyRepository::getAllActiveCompanyIds()` на `companies` и новый status.
- Обновить `ActiveCompanyService`: suspended company нельзя выбрать как active.
- Обновить company switcher: показывать только доступные active companies, отдельно сообщать о suspended при необходимости.
- Проверить CLI/worker места, где берутся все active company ids.
- Добавить тесты на suspended company: UI access, active company fallback, worker list.

Ожидаемые файлы:

- `site/src/Company/Entity/Company.php`
- `site/src/Company/Enum/CompanyStatus.php`
- `site/src/Company/Infrastructure/Repository/CompanyRepository.php`
- `site/src/Shared/Service/ActiveCompanyService.php`
- `site/templates/partials/_active_company_widget.html.twig`
- `site/migrations/*`
- `site/tests/Unit/Company/*`
- `site/tests/Functional/Company/*`

STOP: owner review required before migration/tenant-access changes.

### Stage 5: Admin UI для управления блокировками

**Risk:** HIGH

Причина HIGH: новые admin actions/endpoints + RBAC-sensitive behavior.

Работы:

- Добавить в admin user list статус пользователя и действия block/unblock.
- Добавить company status в admin context: список компаний пользователя, suspend/activate.
- Все POST actions с CSRF.
- Логи/audit для всех действий.
- Запретить destructive delete в рамках этой стадии.
- Добавить functional tests для admin permissions, CSRF, self-block guard.

Ожидаемые файлы:

- `site/src/Admin/Controller/UserController.php` или новые focused controllers
- `site/src/Admin/Application/...`
- `site/templates/admin/users/index.html.twig`
- `site/tests/Functional/Admin/*`

STOP: owner review required before continuing after this stage.

### Stage 6: Company-scoped authorization

**Risk:** HIGH

Причина HIGH: изменение auth/RBAC/Voter и большое влияние на продуктовые routes.

Работы:

- Инвентаризировать все `#[IsGranted('ROLE_COMPANY_OWNER')]` и места с owner-only логикой.
- Ввести company-scoped permission layer:
  - `CompanyAccessPolicy`, или
  - Symfony Voter для active company, или
  - явный `ActiveCompanyPermissionChecker`.
- Owner/operator права проверять по active company membership/ownership, а не только по глобальной роли пользователя.
- Сохранить глобальные роли только для coarse access/login, если они нужны.
- Постепенно заменить sensitive owner-only routes на company-scoped проверку.
- Добавить regression tests: пользователь owner компании A и operator компании B не получает owner-доступ в B.

Ожидаемые файлы:

- `site/src/Company/Application/...`
- `site/src/Company/Security/...` или `site/src/Shared/Security/...`
- affected controllers with `ROLE_COMPANY_OWNER`
- `site/tests/Functional/*`

STOP: owner review required before RBAC rollout.

### Stage 7: Billing integration decision

**Risk:** MEDIUM/HIGH

Цель: решить, должен ли `SubscriptionStatus::SUSPENDED` автоматически блокировать запись/доступ компании.

Работы:

- Реализовать реальный `Billing\AccessManager`, если задача включает billing enforcement.
- Определить mapping:
  - `TRIAL/ACTIVE` -> allow;
  - `GRACE` -> read + ограниченная write policy;
  - `SUSPENDED/CANCELED` -> deny write или deny all кроме billing page.
- Не смешивать manual admin suspension и billing suspension без явного правила приоритета.

STOP: требуется отдельное бизнес-решение перед реализацией.

## Проверки для будущей реализации

Минимум для каждого stage:

- `git status --short`
- `git diff --stat`
- self-review diff
- targeted PHPUnit по изменённому модулю, если есть удобный command
- `make site-test-unit` или `make codex-test-unit` перед handoff

Для security/RBAC stages:

- functional login/access tests;
- проверки self-block/self-demote;
- тесты disabled member и suspended company;
- регрессия company-scoped owner/operator прав.

## Что не менять без отдельного owner approval

- Не включать hard delete пользователей/компаний.
- Не менять финансовые формулы, `financeLockBefore` semantics, периоды закрытия.
- Не подключать Billing suspension к production access без отдельного решения.
- Не менять Messenger transports/workers/cron.
- Не делать массовую замену RBAC по всему проекту в одном stage.
- Не устанавливать новые зависимости.
- Не выполнять миграции на staging/production.

## Acceptance criteria для всей задачи account management

- Owner-account создаётся единым путём и всегда имеет owner membership.
- Invited user имеет доступ только к active companies, где membership active.
- Disabled member не может работать в компании.
- Blocked user не может войти и не может продолжать работу в активной сессии.
- Suspended company не выбирается как active и не попадает в worker/CLI списки active companies.
- Admin может block/unblock user и suspend/activate company через CSRF-protected actions.
- Owner/operator права проверяются в контексте active company.
- Все блокировки имеют audit trail: actor, timestamp, reason.
- Есть unit/functional tests на основные happy-path и denial-path сценарии.
