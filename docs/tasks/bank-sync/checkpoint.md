## Current checkpoint

**Phase:** Stage 3 — OAuth-подключение, общая часть
**Status:** done — handoff
**Stage base commit:** `6f9d4fe90`

### Completed
- Stage 1 — на проде 26.10 (PR #2798, миграция `Version20261026120000`).
- Stage 2 — на проде 26.10 (PR #2799).
- `stages/stage-3.md` — план, чек-лист приёмки, итог.
- `4b8cb2c9f` статус подключения вместо `is_active`, миграция `Version20261026170000`.
- `fd44574d0` токены OAuth, PKCE, клиент token endpoint, тип `oauth2` в кодеке.
- `f7f85d386` вход: start/callback, `state` и PKCE в сессии.
- `f036a21bc` обновление токена в Handler под блокировкой подключения.
- `007bd0327` `ARCHITECTURE.md` 2.78, «История плана».
- `cb1cad19f`, `5d76acd42`, `abd8ffd40` — замечания Codex (раунды 1–3).

### Checks and baseline
- Локальные тесты: БД `app_test_banksync` в контейнере `asf1-pg`, Redis `asf1-redis`.
- PHPStan 0, baseline не изменён; CS чисто; unit 4052 OK, integration + functional 3234 OK.

### Review status
- internal: done
- external: раунд 4 — REVIEW_GREEN

### Exact next action
- Handoff Stage 3: push, PR, вопрос «run the migration and deploy #N»; после деплоя —
  §5 «Прод» в `stage-3.md`. Затем Stage 4 по `plan.md` §5.

### Files to inspect first on resume
- `docs/tasks/bank-sync/stages/stage-3.md`
- `site/src/BankSync/MessageHandler/SyncBankConnectionHandler.php`
- `site/src/BankSync/Application/CompleteBankAuthorizationAction.php`
