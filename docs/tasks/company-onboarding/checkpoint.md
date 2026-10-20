# Checkpoint — company-onboarding

Ветка: `feat/company-onboarding` от `origin/master` `7939a22f6`.

## Phase 0

- Baseline 2026-10-20: `phpunit tests/Functional/Company tests/Unit/Company
  tests/Integration/Cash tests/Unit/Admin tests/Integration/Balance tests/Functional/Balance`
  → OK (952 tests, 3087 assertions). Тестовая БД `app_test_onb`, сеть
  `app-service-finance_default`.
- Решение: Stage 1 без миграции — идемпотентность денежных счетов по типу.

## Ход

| Stage | Статус |
|---|---|
| 1. Cash: счёт и касса | ✅ коммит Stage 1 |
| 2. Finance: дерево ОПиУ | ✅ коммит Stage 2 |
| 3. Balance: учётные счета | ✅ коммит Stage 3 |
| 4. Сквозная проверка, дозаполнение | ✅ коммиты Stage 4 + правки ревью |

Следующее действие: внешнее ревью → push, PR, handoff (`handoff.md`).
