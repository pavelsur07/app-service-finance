# Handoff: смена пароля залогиненным пользователем

## Summary

Один Stage: эндпоинт `GET|POST /profile/password` (`app_profile_password`) — смена пароля
залогиненным пользователем по best practices на существующих паттернах проекта.

- **Branch:** `task/change-password` (base `658a93ef`)
- **Commit:** `eacd5957`
- **Draft PR:** https://github.com/pavelsur07/app-service-finance/pull/2297
- **Stage Report:** `docs/tasks/change-password/stages/stage-1.md`

## Files changed

- `site/config/packages/rate_limiter.yaml` — limiter `password_change` (5/15 мин)
- `site/config/packages/messenger.yaml` — routing `SendPasswordChangedEmailMessage` → `async_sync`
- `site/config/services.yaml` — binding `PasswordChangeRateLimiter`
- `site/src/Shared/Service/RateLimiter/PasswordChangeRateLimiter.php` — new
- `site/src/Company/Service/PasswordChanger.php` — new
- `site/src/Company/Exception/{InvalidCurrentPasswordException,SamePasswordException}.php` — new
- `site/src/Company/Form/ChangePasswordFormType.php` — new
- `site/src/Company/Controller/ProfileController.php` — new
- `site/src/Company/Message/SendPasswordChangedEmailMessage.php` — new
- `site/src/Company/MessageHandler/SendPasswordChangedEmailHandler.php` — new
- `site/templates/security/change_password.html.twig` — new
- `site/templates/notifications/email/password_changed.{html,txt}.twig` — new
- `site/tests/Unit/Company/PasswordChangerTest.php` — new
- `site/tests/Unit/Company/MessageHandler/SendPasswordChangedEmailHandlerTest.php` — new
- `site/tests/Functional/Company/ProfilePasswordChangeTest.php` — new
- `docs/tasks/change-password/**` — new

## Migrations

Нет. Schema не менялась.

## Public API / contract changes

Новый веб-эндпоинт `/profile/password` (только аутентифицированные, `ROLE_USER`).
Публичный API не менялся.

## Checks

- baseline: `make site-test-unit` — OK (1753 tests)
- final: `phpunit --testsuite=unit` — OK (1759 tests, 9860 assertions)
- module: `phpunit tests/Functional/Company` — OK (26 tests, 164 assertions)
- lint: yaml (rate_limiter, messenger, services с --parse-tags), twig (новые шаблоны), container — OK
- ручной smoke не выполнялся: поведение покрыто 6 функциональными тестами (включая ротацию session id и 429)

## Reviews

- Internal automatic review: 3 итерации, BLOCKER/IMPORTANT нет, MINOR исправлены.
- External Claude Code review: 5 итераций → **REVIEW_GREEN**.
  - Ит.1: 1 IMPORTANT (ключ лимитера с IP → обход throttling) + 8 MINOR — все исправлены.
  - Ит.2: REVIEW_GREEN, 9 MINOR добиты (email layout, try/catch dispatch, handler unit-тест, CSRF-тест и др.).
  - Ит.3: REVIEW_GREEN, 3 MINOR добиты (reset лимитера после успеха, RateLimiterFactoryInterface, transport assert).
  - Ит.4: REVIEW_GREEN, 2 MINOR добиты (guard reset(), типизированный assert сообщения) + security-лог неудачных попыток.
- Rejected/deferred: `Retry-After` на 429 (расширение API обёртки); entity в vars письма (pre-existing паттерн); правка RegistrationRateLimiter (вне scope); таймзона даты в письме (косметика, суффикс `T`).

## Risks

- Fail-open лимитера при отсутствии factory — смягчён warning-логом (паттерн проекта).
- Production: убедиться, что `async_sync` — реальная очередь; иначе письмо уходит синхронно и при сбое только логируется.

## Known limitations

- Страница доступна только по URL — ссылки в навигации нет (FOLLOW-UP).
- Нет flow «забыл пароль» (отдельная задача).

## Follow-up tasks (вне scope)

- Ссылка на смену пароля в навигации (два UI-режима: legacy sidebar, app shell header).
- `Retry-After` на 429 (расширить `PasswordChangeRateLimiter`).
- Выровнять min длину пароля регистрации (6 → 8) + `NotCompromisedPassword`; заодно `RegistrationRateLimiter` на `RateLimiterFactoryInterface` (Symfony 8).
- Flow «забыл пароль» через email (reset token).
- Отдельный лимит на успешные смены (объём писем); аудит-лог событий безопасности в БД.
- Алерт на логи `Password changed email dispatch failed` / `was not sent` / `Password change failed: invalid current password` (DevOps).

## What owner should review

- Draft PR #2297 (diff компактный: 17 файлов кода + 3 конфига + доки задачи).
- UX формулировки в письме и на странице.

## Expected owner response

Review PR #2297. Мерж, вывод из Draft, release и любые production-действия — только по явному указанию owner.
