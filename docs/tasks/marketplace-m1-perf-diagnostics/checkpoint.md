## Current checkpoint

**Phase:** Stage 2 / Work item 2.1 (08-normalization-boundary.md)
**Status:** implementing
**Stage base commit:** 2fa6307b (Stage 1 commit; Stage 1 base 5a8c36d9)

### Completed
- Stage 1: инструментирование, CLI-отчёт, тесты — commit 2fa6307b.
- Stage 2: 09, 10, строка M1 в 06, ARCHITECTURE.md 1.98, флаг в docker-compose.prod.yml (x-php-env) и site/.env.

### Checks and baseline
- baseline `make site-test-unit` — OK (3223 tests) до изменений.
- Stage 1: unit-набор OK; новые 33 unit + 1 integration OK; `make site-stan` OK (baseline: переименование resolve→doResolve);
  `make site-cs-check`, `make site-cs-strict-types` OK; локальный e2e воркера (queue_wait+handler в файле, отчёт).
- Overhead: см. 09 §«Накладные расходы».

### Review status
- internal: pending (fresh-session review на handoff)
- external: pending (Large → одна итерация на handoff)

### Exact next action
- Дописать 08 по отчёту субагента о зависимостях нормализации; коммит Stage 2; full gates; review; PR.

### Files to inspect first on resume
- docs/tasks/marketplace-m1-perf-diagnostics/plan.md, docs/architecture/marketplace-audit/09-m1-diagnostics.md
