# Checkpoint — business-profile-templates

Ветка: `feat/business-profile` от `origin/master` `3b648386b`. Поручено: Stage 1 и 2.

## Phase 0

- Baseline 2026-10-20: `phpunit tests/{Functional,Unit,Integration}/Company tests/{Unit,Functional}/Admin`
  → OK (763 tests, 2053 assertions); master `3b648386b` — CI зелёный.
- Регистрация — отдельная страница на Bootstrap (`templates/security/register.html.twig`),
  не DS-layout; выбор профиля — в её стиле.
- `CompanyType` общий для `/company/new` и редактирования; смена профиля в настройках — Stage 4,
  поэтому поле в форме — только при создании.
- Слой `Enum` модуля Company открыт для других модулей (`ModuleBoundaryRules::CLOSED_LAYERS`).

## Ход

| Stage | Статус |
|---|---|
| 1. Профиль в Company | ✅ коммит Stage 1 |
| 2. Реестр шаблонов | ✅ коммит Stage 2 |

Следующее действие: handoff — полные гейты, ревью, push, PR (миграция — отдельный dispatch).
