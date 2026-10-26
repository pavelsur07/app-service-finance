## Current checkpoint

**Phase:** Phase 0 — план
**Status:** planned
**Stage base commit:** —

### Completed
- `plan.md` — задача, решения, этапы 1–13 (пересобраны 2026-10-26), поставки A/B/C.
- `alfa-letter.md` — письмо в поддержку Alfa API (черновик).
- `stages/stage-1.md` — план Stage 1 из четырёх частей.

### Checks and baseline
- не снимался; Stage 1, п. 1.1–1.2.

### Review status
- internal: —
- external: —

### Exact next action
- Stage 1, п. 1.1: read-only проверка на проде (строки старых таблиц, `import_log`
  `bank:%`, сообщения `BankImportMessage` в очереди и `failed`).

### Files to inspect first on resume
- `docs/tasks/bank-sync/plan.md`, `docs/tasks/bank-sync/stages/stage-1.md`
- `site/src/Cash/Service/Import/Bank/BankImportService.php`
- `site/src/Cash/MessageHandler/Import/BankImportHandler.php`
