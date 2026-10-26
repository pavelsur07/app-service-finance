# bank-sync — handoff Stage 2

**Branch:** `feat/bank-sync-stage2` · **PR:** см. описание PR · **CI:** см. PR

Поставка — Stage 2 из плана (`plan.md` §5). Для пользователя поведение не меняется.

## Summary of stages
- Stage 2 — общая ротация ключа шифрования: `app:security:rotate-keys` видит и
  перешифровывает секреты Marketplace, MoySklad (access и refresh) и BankSync; раньше
  ротация была только у маркетплейсов, и смена ключа по инструкции сделала бы токены
  MoySklad нечитаемыми. Подробно — `stages/stage-2.md`, «Итог этапа».

## Files changed
- `site/src/Shared/Security/{Contract,ValueObject,Service}` — контракт ротатора, проверка перешифровки.
- `site/src/Shared/Command/RotateEncryptionKeysCommand.php` — общая команда.
- `site/src/{Marketplace,MoySklad,BankSync}/Infrastructure/Security/*SecretRotator.php`.
- `site/src/Marketplace/Command/RotateConnectionKeysCommand.php` — обёртка;
  `ConnectionApiKeyCodec::rotateIfNeeded()` удалён (вызовов нет).
- Тесты, baseline (−5), `docs/maintenance/encryption-key-rotation.md`, `ARCHITECTURE.md` 2.77, README.

## Migrations
- Нет.

## Public API / contract changes
- Новая команда `app:security:rotate-keys [--execute] [--module=…]`.
- Тег `app.security.secret_rotator` (`SecretRotatorInterface`).
- `app:marketplace:rotate-connection-keys` — тот же результат через общую команду.
- `ConnectionApiKeyCodec` — без `SecretRotationServiceInterface` в конструкторе.

## Checks
- PHPStan полный — 0 ошибок, baseline −5; CS и `strict_types` — чисто.
- Unit 4010 — OK; integration + functional 3204 — OK.

## Reviews
- internal: замечаний, требующих правок, нет.
- external: раунд 1 — REVIEW_GREEN.

## Risks, limitations, follow-ups
- Ротация на проде не запускается этим PR — действие Владельца по инструкции.
- Dry-run на проде доступен только через Владельца: команды нет в `codex-console`.
- Удалить `app:marketplace:rotate-connection-keys` после первой смены ключа по новой инструкции.

## Owner decision
Ready: PR без миграций — merge into master with automatic production deploy?
