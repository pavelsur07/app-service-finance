# План: смена пароля залогиненным пользователем

Задача: реализовать смену пароля для аутентифицированного пользователя по best practices,
на существующих паттернах проекта `app-service-finance` (`site/`, Symfony 7.4).

## Контекст (что уже есть)

- `App\Company\Entity\User` — `PasswordAuthenticatedUserInterface`, hasher `auto` (`site/config/packages/security.yaml`).
- Логин: `form_login`, firewall `main`; `access_control`: `^/` требует `ROLE_USER` — новый маршрут покрывается автоматически.
- Смены пароля нет. Есть только консольный `app:user:reset-password` (`site/src/Company/Command/ResetUserPasswordCommand.php`).
- Rate limiter: `site/config/packages/rate_limiter.yaml` + обёртки `App\Shared\Service\RateLimiter\*RateLimiter` (`consume(identifier): bool`).
- Почта: Messenger `Message` + `#[AsMessageHandler]` + `NotificationRouter` + Twig-шаблоны `site/templates/notifications/email/*` (образец — `SendRegistrationEmailMessage` / `SendRegistrationEmailHandler`).
- Формы: `site/src/Company/Form/RegistrationFormType.php` (образец: `mapped => false` для пароля, honeypot-поле).
- Тесты: `site/tests/Unit/Company/*`, `site/tests/Functional/Company/*` (образцы: `PublicRegistrationFlowTest.php`, `CompanyMemberAccessTest.php`).
- Команды проверок: `make site-test-unit`, `make site-test` (integration), baseline — `make site-test-unit`.

## Решения по открытым вопросам

- **Мин. длина нового пароля: 8** (`Length(min: 8)` в новой форме). Регистрацию (сейчас `min: 6`) в этой задаче не трогаем — FOLLOW-UP «выровнять минимальную длину пароля при регистрации».
- **Ветка:** новая `task/change-password` от текущего HEAD. В рабочем дереве есть незакоммиченные изменения чужой задачи `pl-category-import` — **не трогаем, не коммитим, не stash'им**; на коммите stage'им только файлы этой задачи.

## Git / доставка

1 task = 1 branch `task/change-password` = 1 Draft PR. `owner_gate: no`, `release_candidate: no`, `independently_deployable: no`.

## Stage 1: смена пароля в профиле (бэкенд + шаблон + письмо + тесты)

Risk: HIGH-LOCAL (изменение auth-кода, только в task-ветке, без production-действий)
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: 658a93ef7523f57d6685f2a4cadab307cb450738

Definition of Done:
- Залогиненный пользователь может сменить пароль через `GET|POST /profile/password` (route `app_profile_password`).
- Форма: текущий пароль, новый пароль (RepeatedType), honeypot; CSRF из Form component.
- Неверный текущий пароль → ошибка формы без раскрытия деталей; rate limit 5 попыток / 15 мин на аккаунт (ключ — user id, без IP, иначе лимит обходится сменой IP).
- Новый пароль: `NotBlank`, `Length(min: 8, max: 4096)`; новый != старый.
- После успеха: `flush()`, `Session::migrate(true)` (текущий пользователь остаётся залогинен; сессии на других устройствах инвалидируются автоматически за счёт entity provider + смены хеша), flash «Пароль изменён», redirect на `app_profile_password`.
- Отправляется email-уведомление через `NotificationRouter` (через Messenger).
- В логах/исключениях/трейсах нет паролей; `logger->info` события без PII сверх user id.
- Unit-тесты сервиса + функциональный тест контроллера зелёные; `make site-test-unit` зелёный.
- Вне scope: flow «забыл пароль» по email, аудит-лог в БД, изменение регистрации, remember-me.

### Work items

- **1.1 — Rate limiter.** `site/config/packages/rate_limiter.yaml`: добавить `password_change` (fixed_window, limit 5, interval '15 minutes'). Новый `site/src/Shared/Service/RateLimiter/PasswordChangeRateLimiter.php` по образцу `RegistrationRateLimiter`. Регистрация в `site/config/services.yaml`: `$factory: '@?limiter.password_change'`.
- **1.2 — Сервис `PasswordChanger`.** `site/src/Company/Service/PasswordChanger.php`:
  `change(User $user, string $currentPlainPassword, string $newPlainPassword): void`.
  Проверки: `isPasswordValid()` текущего (иначе `InvalidCurrentPasswordException` — доменное исключение в `App\Company\Exception`), `new !== current` (иначе `SamePasswordException`), хеширование через `UserPasswordHasherInterface`, `setPassword()`, `EntityManagerInterface::flush()`, `logger->info` (user id, без паролей), `MessageBusInterface::dispatch(SendPasswordChangedEmailMessage)`.
- **1.3 — Email-уведомление.** `site/src/Company/Message/SendPasswordChangedEmailMessage.php` (`userId`, `changedAt`), `site/src/Company/MessageHandler/SendPasswordChangedEmailHandler.php` (образец `SendRegistrationEmailHandler`: не найден user → warning и return), шаблоны `site/templates/notifications/email/password_changed.html.twig` и `.txt.twig` («Пароль был изменён. Если это были не вы — свяжитесь с поддержкой»).
- **1.4 — Форма.** `site/src/Company/Form/ChangePasswordFormType.php`: `currentPassword` (PasswordType, `mapped: false`, `autocomplete: current-password`, `NotBlank`), `plainPassword` (RepeatedType + PasswordType, `mapped: false`, `autocomplete: new-password`, `NotBlank` + `Length(8..4096)`), honeypot `website` как в регистрации. `data_class: null` (форму не мапим на User).
- **1.5 — Контроллер.** `site/src/Company/Controller/ProfileController.php`: `#[Route('/profile/password', name: 'app_profile_password', methods: ['GET','POST'])]` + `#[IsGranted('ROLE_USER')]`. Порядок в POST: honeypot (generic-ошибка) → `isValid()` → rate limit (`consume(userId)`, при срабатывании HTTP 429) → `PasswordChanger::change()` (ловит доменные исключения → `FormError` на соответствующее поле) → `migrate(true)` + flash + redirect. Шаблон `site/templates/security/change_password.html.twig` (extends `base.html.twig`).
- **1.6 — Тесты.**
  - Unit `site/tests/Unit/Company/Service/PasswordChangerTest.php`: неверный текущий пароль → исключение, без flush/dispatch; new == current → исключение; успех → хеш установлен, flush вызван, сообщение отправлено. Моки hasher/EM/bus по образцу `CompanyOwnerAccountCreatorTest.php`.
  - Functional `site/tests/Functional/Company/ProfilePasswordChangeTest.php` (образец `PublicRegistrationFlowTest.php`): логин тестовым пользователем → GET 200 → POST с неверным текущим → ошибка; POST валидный → redirect, пароль реально изменён (повторный логин / `isPasswordValid`); незалогиненный GET → redirect на логин.
- **1.7 — Навигация.** Ссылка «Сменить пароль» в существующем меню/профиле, если есть очевидное место в базовом layout; если места нет — пропустить, записать FOLLOW-UP.

### Stage checks

- baseline: `make site-test-unit` — OK (1753 tests, 9826 assertions) до изменений
- targeted: `make site-test-unit` + фильтр по новым тестам
- module: functional-тесты Company (`composer test` / phpunit filter `Company` в test-окружении)
- полный stage: `make site-test-unit`, ручной smoke через Docker (логин → /profile/password → смена → логин новым паролем), `git diff --stat`, self-review, внешний Claude review до `REVIEW_GREEN`
- lint: `php bin/console lint:yaml config`, `lint:twig templates`, `lint:container` (через `site-php-cli`)

### Reviewer focus

- Пароли не попадают в логи/исключения/profiler (поля `password`/`plainPassword`/`currentPassword`).
- Rate limit применяется ДО проверки текущего пароля, но ПОСЛЕ валидации формы; ключ = user id (без IP).
- Контроллер тонкий, логика в `PasswordChanger`; транзакционность flush.
- Обработчик письма устойчив к отсутствию user; нет утечки о существовании аккаунта.
- `migrate(true)` после успеха; нет регрессии `__serialize`/логина.

## Файлы

Новые:
- `site/src/Shared/Service/RateLimiter/PasswordChangeRateLimiter.php`
- `site/src/Company/Service/PasswordChanger.php`
- `site/src/Company/Exception/InvalidCurrentPasswordException.php`, `SamePasswordException.php`
- `site/src/Company/Form/ChangePasswordFormType.php`
- `site/src/Company/Controller/ProfileController.php`
- `site/src/Company/Message/SendPasswordChangedEmailMessage.php`
- `site/src/Company/MessageHandler/SendPasswordChangedEmailHandler.php`
- `site/templates/security/change_password.html.twig`
- `site/templates/notifications/email/password_changed.{html,txt}.twig`
- `site/tests/Unit/Company/Service/PasswordChangerTest.php`
- `site/tests/Functional/Company/ProfilePasswordChangeTest.php`

Изменённые:
- `site/config/packages/rate_limiter.yaml` (+ limiter `password_change`)
- `site/config/services.yaml` (биндинг factory, как у существующих лимитеров)
- `docs/tasks/change-password/{plan.md,checkpoint.md,stages/stage-1.md}` — артефакты задачи

Не трогать:
- любые файлы задачи `pl-category-import` (uncommitted changes в дереве)
- `security.yaml`, `User.php`, регистрацию, `ResetUserPasswordCommand`
- Messenger routing (`config/packages/messenger.yaml`) — новое сообщение должно роутиться как существующие email-сообщения; если они без явного routing — оставить так же

## Документация задачи

- План: `docs/tasks/change-password/plan.md`
- Checkpoint: `docs/tasks/change-password/checkpoint.md` (после каждого Work item)
- Stage Report: `docs/tasks/change-password/stages/stage-1.md`
- Handoff: `docs/tasks/change-password/handoff.md`

## FOLLOW-UP (вне scope)

- Выровнять минимальную длину пароля при регистрации (6 → 8) и добавить `NotCompromisedPassword`.
- Flow «забыл пароль» через email (reset token).
- Аудит-лог событий безопасности в БД.
- Страница профиля с навигацией на смену пароля.
