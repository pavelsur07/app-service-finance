# bank-sync — handoff Stage 4

**Branch:** `feat/bank-sync-stage4` · **PR:** см. описание PR · **CI:** см. PR

Поставка B (Stage 4–10, Альфа на проде скрыта). Для пользователей ничего не меняется:
на проде нет клиентского сертификата Альфы и подключений к ней.

## Summary of stages
- Stage 4 — канал до API Альфы по двустороннему TLS: отдельный HTTP-клиент с нашим
  сертификатом и доверием только к УЦ Минцифры, понятная ошибка, если сертификат не
  настроен, ночной гейт сроков сертификатов, проба канала, runbook замены без простоя.
  Подробно — `stages/stage-4.md`, «Итог этапа».

## Files changed
- `site/src/BankSync/Infrastructure/Bank/Alfa/{AlfaTlsConfig,AlfaHttpClientFactory}.php`,
  `Infrastructure/Security/PemCertificates.php`, `Exception/BankChannelNotConfiguredException.php`.
- `site/src/BankSync/Command/{CheckAlfaCertificatesCommand,ProbeAlfaChannelCommand}.php`,
  `Domain/Certificate/CertificateExpiryPolicy.php`, `Enum/CertificateExpiryLevel.php`.
- `SyncBankConnectionHandler` — канал не настроен → error без ретраев.
- `site/config/bank_sync/alfa/ca-bundle.pem` (публичные CA Минцифры), `config/services.yaml`, `site/.env`.
- `docker/cron/app.cron` — гейт 07:50; `.gitignore` — ключи и архивы сертификатов.
- Тесты unit/integration; `ARCHITECTURE.md` 2.79, `health-gates.md`, `alfa-demo.md`, `plan.md`.

## Migrations
- Нет.

## Public API / contract changes
- Сервис `bank_sync.alfa.http_client` (ленивый) — у `AlfaApiClient`.
- Переменные окружения `ALFA_TLS_CERT_PATH`, `ALFA_TLS_KEY_PATH`, `ALFA_TLS_KEY_PASSPHRASE` (необязательные).
- Команды `bank-sync:alfa:certificates-check` (cron), `bank-sync:alfa:channel-probe --target=sandbox|production`.

## Checks
- PHPStan полный — 0 ошибок, baseline не изменён; CS и `strict_types` — чисто.
- unit 4094 — OK; integration + functional 3238 — OK; BankSync 167 — OK.
- Вживую: mTLS со своим тестовым сервером (TLS 1.2, ключ под паролем) проходит, без сертификата — нет.

## Reviews
- internal: done.
- external: раунд 1 — 3 IMPORTANT, исправлены с тестами; раунд 2 — REVIEW_GREEN.

## Risks, limitations, follow-ups
- Проба песочницы Альфы ждёт `test_cert.zip` и пароль от Владельца.
- На проде гейт проверяет только CA (клиентского сертификата нет до Stage 11).
