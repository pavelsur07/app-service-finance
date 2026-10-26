## Current checkpoint

**Phase:** Stage 1 — модуль BankSync и граница с Cash
**Status:** in progress
**Stage base commit:** `23491e8cf`

### Completed
- `plan.md` — задача, решения, этапы 1–13 (пересобраны 2026-10-26), поставки A/B/C.
- `alfa-letter.md` — письмо в поддержку Alfa API (черновик).
- `stages/stage-1.md` — подробный план Stage 1.
- 1.1 прод read-only: старые банковские таблицы и очереди пусты, ключ `v1` работает.
- 1.2 baseline: 99 тестов банковского импорта и изоляции — OK.

### Checks and baseline
- Локальные тесты: БД `app_test_banksync` в контейнере `asf1-pg`, сеть `asf1-net`
  (стек `app-service-finance` остановлен).

### Review status
- internal: —
- external: —

### Exact next action
- Stage 1, п. 1.3: каркас модуля `BankSync`.

### Files to inspect first on resume
- `docs/tasks/bank-sync/stages/stage-1.md`
- `site/src/Cash/Service/Import/Bank/BankImportService.php` (источник переноса)
