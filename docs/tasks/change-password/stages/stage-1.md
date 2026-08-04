### Stage 1: смена пароля залогиненным пользователем — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously

#### Stage scope
- Stage base commit: `658a93ef7523f57d6685f2a4cadab307cb450738`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`, `1.6` (`1.7` навигация — сознательно пропущен, FOLLOW-UP)

#### What was done
- Эндпоинт `GET|POST /profile/password` (`app_profile_password`) для смены пароля залогиненным пользователем.
- Форма: текущий пароль, новый пароль (RepeatedType), honeypot; CSRF из Form component.
- Сервис `PasswordChanger`: проверка текущего пароля через hasher, запрет new == current, хеширование, flush, лог (только user id), диспатч email-уведомления (guarded try/catch).
- Rate limit 5/15 мин на аккаунт (ключ без IP), consume после валидации формы, до проверки пароля; reset после успешной смены; HTTP 429 при срабатывании.
- `Session::migrate(true)` после успеха; остальные сессии инвалидируются за счёт смены хеша (entity provider, подтверждено ревьюером по `ContextListener`).
- Email-уведомление через Messenger + NotificationRouter, шаблоны на общем `notifications/email/base.html.twig`; warning-логи при недоставке.
- Тесты: unit `PasswordChangerTest` (3), unit `SendPasswordChangedEmailHandlerTest` (3), functional `ProfilePasswordChangeTest` (6: anonymous redirect, happy path + session rotation + transport assert, wrong current, rate limit 429, honeypot, CSRF).

#### Files changed
- `site/config/packages/rate_limiter.yaml` — modified (limiter `password_change`)
- `site/config/packages/messenger.yaml` — modified (routing сообщения)
- `site/config/services.yaml` — modified (binding `PasswordChangeRateLimiter`)
- `site/src/Shared/Service/RateLimiter/PasswordChangeRateLimiter.php` — new
- `site/src/Company/Service/PasswordChanger.php` — new
- `site/src/Company/Exception/InvalidCurrentPasswordException.php` — new
- `site/src/Company/Exception/SamePasswordException.php` — new
- `site/src/Company/Form/ChangePasswordFormType.php` — new
- `site/src/Company/Controller/ProfileController.php` — new
- `site/src/Company/Message/SendPasswordChangedEmailMessage.php` — new
- `site/src/Company/MessageHandler/SendPasswordChangedEmailHandler.php` — new
- `site/templates/security/change_password.html.twig` — new
- `site/templates/notifications/email/password_changed.html.twig` — new
- `site/templates/notifications/email/password_changed.txt.twig` — new
- `site/tests/Unit/Company/PasswordChangerTest.php` — new
- `site/tests/Unit/Company/MessageHandler/SendPasswordChangedEmailHandlerTest.php` — new
- `site/tests/Functional/Company/ProfilePasswordChangeTest.php` — new
- `docs/tasks/change-password/**` — new (артефакты задачи)

#### Definition of Done
- [x] Смена пароля через `/profile/password`
- [x] Форма: текущий/новый (repeat)/honeypot, CSRF
- [x] Rate limit 5/15 мин на аккаунт, 429
- [x] Валидация нового пароля (8..4096), new != current
- [x] `migrate(true)` + flash + redirect
- [x] Email-уведомление через NotificationRouter/Messenger
- [x] Без паролей в логах/шаблонах
- [x] Unit + functional тесты зелёные
- [x] Вне scope: reset-флоу, аудит-лог БД, регистрация, remember-me — не тронуты

#### Baseline
- `make site-test-unit` — OK (1753 tests, 9826 assertions) до изменений

#### Checks
- targeted: `phpunit --filter PasswordChangerTest` — OK (3 tests); `phpunit tests/Functional/Company/ProfilePasswordChangeTest.php` — OK
- module: `phpunit tests/Functional/Company` — OK (26 tests, 162 assertions)
- full unit: `phpunit --testsuite=unit` — OK (1759 tests, 9860 assertions) — было 1753, +6 новых
- lint: `lint:yaml` (rate_limiter, messenger), `lint:yaml --parse-tags services.yaml`, `lint:twig` (новые шаблоны), `lint:container` — OK

#### Internal automatic review
- Iterations: 3
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: import KernelBrowser; изоляция тестов от Redis rate limiter (случайные UUID); assert ротации сессии через Set-Cookie (jar содержит протухшую cookie под domain '')
- FOLLOW-UP: навигация на страницу смены пароля; CSRF-тест (закрыт позже в рамках M9)

#### External Claude Code review
- Iterations: 5 (первая — `Reached max turns (40)`, ретрай с `--max-turns 80` по регламенту)
- Result: REVIEW_GREEN (итерации 2, 3 и финальное подтверждение 4)
- Confirmed findings fixed:
  - Ит.1: IMPORTANT#1 ключ лимитера без IP; MINOR#2 consume после isValid; #3 HTTP 429; #4 honeypot generic-ошибка; #5 IsGranted; #6 изоляция тестов; #7 warning при fail-open; #8 warning при send()===false; #9 strict_types; #12 строка о сессиях в письме
  - Ит.2 (MINOR): M1 email на base layout; M2 assert ротации сессии + followRedirect; M3 unit-тест handler'а; M4 try/catch вокруг dispatch; M5 private constraints; M6 ключ без префикса; M8 гигиена тестов; M9 CSRF-тест
  - Ит.3 (MINOR): 1 reset лимитера после успеха; 2 проверка по RateLimiterFactoryInterface; 3 assert сообщения в транспорте
  - Ит.4 (MINOR): reset() перенесён после migrate(true) и обёрнут в try/catch; типизированный assert сообщения (instanceof + userId); warning-лог неудачных попыток (InvalidCurrentPasswordException)
- Rejected/deferred findings with reason:
  - Ит.2 M7 `Retry-After` на 429 — FOLLOW-UP (требует расширения API обёртки лимитера)
  - Ит.2 #11 email вместо entity в vars — FOLLOW-UP (pre-existing паттерн регистрации; ревьюер ит.3 подтвердил отсутствие риска сериализации)
  - Ит.3 #2 часть про RegistrationRateLimiter — вне scope задачи
  - Ит.3 #4 таймзона в дате письма — косметика, суффикс `T` однозначен

#### Risks / reviewer focus
- Изменение auth-кода: порядок проверок (валидация → лимит → пароль), ротация сессии, инвалидация остальных сессий — подтверждены тестами и ревью.
- Fail-open лимитера при отсутствии factory — с warning-логом (паттерн проекта).
- Production: `async_sync` должен быть реальной очередью; рекомендован алерт на логи `Password changed email dispatch failed` / `was not sent` (FOLLOW-UP для owner/DevOps).

#### Checkpoint
- `docs/tasks/change-password/checkpoint.md` updated
- exact next action: commit + push + Draft PR + handoff

#### Open questions
- none

#### Expected owner response
- not required; continuing autonomously
