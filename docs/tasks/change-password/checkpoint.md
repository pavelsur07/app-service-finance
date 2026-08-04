# Checkpoint: change-password

## Current checkpoint

**Phase:** Stage 1 — финальное внешнее ревью (подтверждение)
**Status:** reviewing
**Stage base commit:** 658a93ef7523f57d6685f2a4cadab307cb450738
**Current Work item:** none
**Owner gate:** no

### Completed
- Реализация 1.1–1.6; 1.7 → FOLLOW-UP
- External review: REVIEW_GREEN (ит.2), REVIEW_GREEN (ит.3), финальное подтверждение запущено
- Все IMPORTANT/MINOR ит.1–3 исправлены (см. stages/stage-1.md)
- Stage Report подготовлен: docs/tasks/change-password/stages/stage-1.md

### Checks (финальные)
- unit: OK (1759 tests, 9860 assertions)
- functional Company: OK (26 tests, 162 assertions)
- lint:container / lint:twig / lint:yaml — OK

### Review status
- internal: зелёный, 3 итерации
- external: REVIEW_GREEN ×2 + финальное подтверждение в фоне
- unresolved findings: none (FOLLOW-UP зафиксированы в stage-1.md)

### Exact next action
- Дождаться финального REVIEW_GREEN → commit только task-owned файлы → push `task/change-password` → Draft PR → handoff.md → финальный отчёт

### Files to inspect first on resume
- `git status --short` (коммитить ТОЛЬКО файлы задачи — в дереве чужие изменения pl-category-import)
- `docs/tasks/change-password/stages/stage-1.md`
