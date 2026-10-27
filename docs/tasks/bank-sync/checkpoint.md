## Current checkpoint

**Phase:** Stage 5 — вход через Alfa ID, отзыв, сверка согласий
**Status:** done — handoff (кроме 5.12 песочницы: ждёт доступ от Владельца)
**Stage base commit:** `9debf04e3`

### Completed
- Stage 1–4 — на проде 26.10 (PR #2798, #2799, #2800, #2802); прод Stage 4 отмечен 27.10.
- `stages/stage-5.md` — план, чек-лист приёмки, итог.
- 5.2–5.8 — коммиты `f377b332c` … `6b2b7c3f9`; документация `7f64930ee`.
- Замечания Codex — `9998f9310`, `0aea14c3f`.

### Checks and baseline
- Локальные тесты: БД `app_test_banksync` в контейнере `asf1-pg`, Redis `asf1-redis`.
- PHPStan 0, baseline не изменён; CS и strict_types чисто; unit 4155, integration +
  functional 3262, BankSync 258 — OK.

### Review status
- internal: done
- external: раунд 1 — 2 IMPORTANT, раунд 2 — 1 IMPORTANT + 1 MINOR, исправлены; раунд 3 — REVIEW_GREEN

### Exact next action
- Handoff Stage 5: push, PR, вопрос «merge and deploy #N»; после деплоя — §5 «Прод» в `stage-5.md`.
- Получив доступ к песочнице: `alfa-demo.md` «Вход через Alfa ID» → 5.12.
- Затем Stage 6 (привязка счетов) по `plan.md`.

### Files to inspect first on resume
- `docs/tasks/bank-sync/stages/stage-5.md`
- `docs/tasks/bank-sync/alfa-demo.md`
- `site/src/BankSync/Infrastructure/Bank/Alfa/AlfaAuthorizationProvider.php`
