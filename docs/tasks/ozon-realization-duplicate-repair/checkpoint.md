## Current checkpoint

**Phase:** Stage 2 / Work item 2.1
**Status:** implementing
**Stage base commit:** Stage 1 `6a6f7d945`; Stage 2 — commit Stage 1.

### Completed
- Phase 0: локальная БД подготовлена, baseline 2 tests / 8 assertions PASS.
- Stage 1: общий PostgreSQL lock, атомарная обработка, refresh raw после ожидания,
  guards финального/финансово заблокированного периода, транзакционное переоткрытие.
- RED: обе гонки дали 6 вместо 3 строк / удвоенные суммы; четыре regression tests
  показали 250 сохранённых строк после сбоя и отсутствие защит периода/header.
- GREEN: Marketplace integration + logging unit 559 tests / 2505 assertions;
  дополнительные concurrency boundaries 4 tests / 42 assertions.
- Focused PHPStan изменённых Actions и новых integration tests — No errors.
- Focused CS исправил 2 файла; финальная проверка перед commit Stage 1.

### Review status
- Stage 1 internal: BLOCKER 0 / IMPORTANT 0; safe local findings fixed.
- External: не требуется на Stage 1 (<500 lines); обязательно на handoff Large task.
- Полные gates ещё не запускались: только на handoff.

### Rulings
- Продолжить branch/PR #2913: один task/branch/PR, без второго PR реализации.
- Сохранён no-op пустого raw без header; непустой raw требует корректного header.
- Company-scoped lookup возвращает generic not-found для чужого raw; tenant test обновлён.
- Focused PHPStan старого logging unit обнаружил pre-existing missingType.iterableValue;
  source/new integration checks чистые, full handoff gate проверит штатный scope.
- Repair execute потребует expected raw hash из dry-run: флаг --expected-raw-hash,
  чтобы изменение источника между dry-run и execute не было незаметным.

### Exact next action
- Завершить commit/push Stage 1 и обновить Draft PR; автоматически Stage 2.
- Написать RED tests команды и source-multiset verifier; затем реализацию.
- Production backup/wrapper/execute НЕ одобрены и не запускались.

### Files to inspect first on resume
- TASK.md и plan.md в этой директории.
- site/src/Marketplace/Ozon/Application/Action/ProcessOzonRealizationAction.php
- site/src/Marketplace/Application/ReopenMonthStageAction.php
- site/tests/Integration/Marketplace/Ozon/Application/ProcessOzonRealizationConcurrencyTest.php
