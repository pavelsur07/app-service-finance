## Current checkpoint

**Phase:** Stage 4 — канал Альфы: mTLS, доверенные CA, гейт сроков сертификатов
**Status:** done — handoff (кроме пробы песочницы: ждёт `test_cert.zip`)
**Stage base commit:** `ed20f5e70`

### Completed
- Stage 1–3 — на проде 26.10 (PR #2798, #2799, #2800).
- `stages/stage-4.md` — план, чек-лист приёмки, итог.
- `2928af7c6` отметки прода Stage 3.
- `270f65cc3` `bank_sync.alfa.http_client`: mTLS, CA Минцифры, `BankChannelNotConfiguredException`.
- `51e6e6e66` гейт `bank-sync:alfa:certificates-check` (cron 07:50), проба `bank-sync:alfa:channel-probe`.
- `eb7d5b669` документация: runbook `alfa-demo.md`, `ARCHITECTURE.md` 2.79, `health-gates.md`, «История плана».
- `78f967667` замечания Codex (раунд 1).

### Checks and baseline
- Локальные тесты: БД `app_test_banksync` в контейнере `asf1-pg`, Redis `asf1-redis`.
- PHPStan 0, baseline не изменён; CS и strict_types чисто; unit 4094 OK; integration + functional 3238 OK (до правок ревью), BankSync 167 OK.

### Review status
- internal: done
- external: раунд 1 — 3 IMPORTANT исправлены; раунд 2 — REVIEW_GREEN

### Exact next action
- Handoff Stage 4: push, PR, вопрос «merge and deploy #N»; после деплоя — §5 «Прод» в `stage-4.md`.
- Получив `test_cert.zip` и пароль: по `alfa-demo.md` → `bank-sync:alfa:channel-probe --target=sandbox`, отметить 4.9.
- Затем Stage 5 (Alfa ID) по `plan.md` §5.

### Files to inspect first on resume
- `docs/tasks/bank-sync/stages/stage-4.md`
- `docs/tasks/bank-sync/alfa-demo.md`
- `site/src/BankSync/Infrastructure/Bank/Alfa/AlfaTlsConfig.php`
