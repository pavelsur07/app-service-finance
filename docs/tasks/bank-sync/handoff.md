# bank-sync — handoff Stage 5

**Branch:** `feat/bank-sync-stage5` · **PR:** см. описание PR · **CI:** см. PR

Поставка B (Stage 4–10, Альфа на проде скрыта). Для пользователей ничего не меняется:
на проде не заданы переменные Alfa ID — кнопки входа через Альфу нет, подключений 0.

## Summary of stages
- Stage 5 — вход клиента через Alfa ID (OIDC): пара приложения и сертификат подписи на
  каждую среду, `nonce` и проверка `id_token`, `sub` пользователя банка в подключении,
  отзыв токена при удалении, 403 → «войдите заново», ночная сверка сроков согласий,
  срок согласия в списке подключений, у Альфы нет формы статического токена.
  Подробно — `stages/stage-5.md`, «Итог этапа».

## Files changed
- Вход: `Infrastructure/Bank/Alfa/{AlfaIdConfig,AlfaIdEnvironment,AlfaAuthorizationProvider}.php`,
  `Infrastructure/OAuth/{IdTokenVerifier,OAuthTokenResponse}.php`, `OAuthTokenEndpointClient`
  (id_token, revoke), `Domain/OAuth/*` (nonce, `AuthorizationGrant`, `revoke()`, `environments()`),
  Start/Complete Action и контроллеры.
- Согласия: `Infrastructure/Bank/Alfa/AlfaConsentsClient.php`, `Domain/Consent/*`,
  `Enum/ConsentState.php`, `Command/SyncAlfaConsentsCommand.php`,
  `BankConnectionRepository::findConsentCheckTargets()`.
- Удаление и ошибки: `DeleteBankConnectionAction` (отзыв под блокировкой),
  `SyncBankConnectionHandler` (403 → `needs_reauth`).
- UI: форма, New/Edit/Index контроллеры, `index.html.twig` (колонка «Согласие»).
- `CheckAlfaCertificatesCommand` — сертификаты подписи `id_token`.
- `Entity/BankConnection.php` + миграция; `config/services.yaml`, `services_test.yaml`,
  `site/.env` (пустые `ALFA_*`), `docker/cron/app.cron` (07:52).
- Тесты unit/integration/functional; `ARCHITECTURE.md` 2.80, `health-gates.md`,
  `alfa-demo.md`, `plan.md`, `stage-4.md` (прод).

## Migrations
- `Version20261027090000`: `bank_sync_connection.external_subject VARCHAR(255) NULL`,
  `consent_expires_at DATE NULL`. Обратимая, данные не трогает (на проде 0 строк).

## Public API / contract changes
- `BankAuthorizationProviderInterface`: `environments()`, `exchangeCode(..., nonce)` →
  `AuthorizationGrant`, `revoke()`.
- Переменные `ALFA_{SANDBOX,PROD}_CLIENT_ID`, `_CLIENT_SECRET`, `_ID_SIGNING_CERT_PATH`
  (необязательные).
- Команда `bank-sync:alfa:consents-sync` (cron 07:52).

## Checks
- PHPStan полный — 0 ошибок, baseline не изменён; CS и `strict_types` — чисто.
- Полный прогон после правок ревью (unit + integration + functional) — 7423 OK;
  BankSync 258 — OK; миграция на пустой БД и откат — OK.

## Reviews
- internal: done.
- external (Codex, high): раунды 1–2 — 3 IMPORTANT + 1 MINOR, исправлены с тестами;
  раунд 3 — REVIEW_GREEN.

## Risks, limitations, follow-ups
- 5.12 песочница ждёт договора, `client_id`/`client_secret`, `test_cert.zip` с паролем и
  `cer.crt` от Владельца; scope Client Credentials для client-consents (`openid`) в
  документации не назван — проверить там же.
- После деплоя: колонки на месте, сверка в cron «не настроено», exit 0.
