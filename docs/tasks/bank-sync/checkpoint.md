## Current checkpoint

**Phase:** Stage 2 — общая ротация ключей шифрования
**Status:** done — handoff
**Stage base commit:** `fc3143e20`

### Completed
- Stage 1 — на проде 26.10 (PR #2798, миграция `Version20261026120000`).
- `stages/stage-2.md` — план, чек-лист приёмки, итог.
- 2.1 прод read-only: Marketplace 11 × `v1`, MoySklad access 2 × `v1`, refresh пусто, BankSync 0.
- 2.3 `e4cb0675e`: `SecretRotatorInterface`, `KeyVersionCount`, `RotationBatchResult`,
  проверка расшифровкой в `SecretRotationService::rotate()`.
- 2.4 `53898351d`: ротаторы Marketplace, MoySklad, BankSync (DBAL, условие на старый шифротекст).
- 2.5–2.6 `1268b2f56`: `app:security:rotate-keys`, обёртка старой команды, тесты, baseline −5.
- 2.7 `997e00a4b`: инструкция смены ключа, `ARCHITECTURE.md` 2.77, README.

### Checks and baseline
- Локальные тесты: БД `app_test_banksync` в контейнере `asf1-pg`, сеть `asf1-net`.
- PHPStan 0, unit 4010 OK, integration + functional 3204 OK.

### Review status
- internal: done
- external: раунд 1 — REVIEW_GREEN

### Exact next action
- Handoff Stage 2: push, PR, вопрос «merge and deploy» (миграций нет).
  Затем Stage 3 (OAuth-подключение, общая часть) — план в `plan.md` §5.

### Files to inspect first on resume
- `docs/tasks/bank-sync/stages/stage-2.md`
- `site/src/Shared/Command/RotateEncryptionKeysCommand.php`
