## Current checkpoint

**Phase:** Stage 3 (PR 2, ветка feat/cashflow-category-description-ai)
**Status:** checking
**Stage base commit:** 4b094e94c (merge #2934)

### Completed
- PR #2934 (Stage 1–2) слит 08.11.2026, деплой run 42464100289.
- Замер до деплоя (11:40 МСК): компаний 21, статей ДДС обычных 378, системных 78.
- Stage 3: запрос и маппер отдают описание, промпт v4, подсказка в форме, тесты.

### Checks and baseline
- Cash Unit+Integration+Functional — 880 OK; CS и PHPStan по изменённым файлам — OK.
- ai-service: ruff, mypy, unit 178 OK; evals на stub 6 passed, живые модели — skipped (нет ключей, нужно одобрение).

### Exact next action
- Приёмка деплоя #2934, затем PR 2 → CI → merge после деплоя #2934.
