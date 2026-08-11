# Checkpoint — module-access-roles

## Current checkpoint

**Phase:** Stage 3
**Status:** implementing
**Stage base commit:** 32d181ae (Stage 2 committed and pushed)
**Current Work item:** 3.3 — перенумерация миграций выше задеплоенной
**Owner gate:** no

### Completed

- Stage 1 — DONE (`77325276`): модель, voter, subscriber, миграция, тесты; internal/external
  REVIEW_GREEN (4 итерации); Stage Report `stages/stage-1.md`; Draft PR #2315.
- Stage 2 — DONE (`32d181ae`): UI шаблонов ролей, назначение участнику и в инвайте, защита
  последнего admin:write, гейтинг snapshot; REVIEW_GREEN (7 итераций); Stage Report `stages/stage-2.md`.
- Work item 3.1 — merge `origin/master` в ветку (`bc030ed4`). Ветка отставала на 42 коммита.
  Разрешено 6 конфликтов; коллизия за `/dashboard` разведена так, что React-пилот master сохранён,
  а легаси-дашборд переехал на `/finance` (см. plan.md, раздел «Решение по роутам лендинга»).
  `DashboardSnapshotService` сложил валюту ДДС от master и гейтинг от ветки; ключ кэша разделён
  по обоим измерениям.
- Work item 3.2 — правки плана по итогам ревизии: снят self-escalation в Stage 2/3 (управление
  шаблонами остаётся owner-only), снят Work item 4.4 (master удалил DebugWipeCompanyDataController),
  зафиксирован owner-гейт `ReportApiKeyController`, приведена в соответствие карта модулей
  (группы `system` в enum нет), в Stage 3 добавлены перенумерация миграций, unique index и вынос
  `flush()` из репозиториев.

### Current diff / affected files

Merge `bc030ed4` — resolved:
- `site/src/Analytics/Application/DashboardSnapshotService.php`
- `site/src/Analytics/Command/AnalyticsDashboardWarmupCommand.php`
- `site/src/Finance/Controller/HomeController.php`
- `site/src/Shared/Controller/HomeRedirectController.php`
- `site/templates/home/dashboard.html.twig` (восстановлен от master)
- `site/templates/partials/_sidebar.html.twig`
- `site/tests/Functional/Analytics/DashboardSnapshotControllerTest.php`
- `site/tests/Functional/Company/ModuleAccessTest.php`
- `site/tests/Functional/Company/Controller/LoginControllerTest.php`
- `site/tests/Functional/Finance/HomeCashCurrencyTest.php`
- `site/tests/Functional/Finance/HomeUiModeTest.php`
- `site/tests/Unit/Analytics/DashboardSnapshotServiceTest.php`

### Checks and baseline

- `make site-test-db-rebuild` — OK, 234 миграции, до `Version20260809120000`.
  Обе миграции ветки (`20260808120000`, `20260808130000`) применились до master-овской.
- `make site-test-unit` — OK (1873 tests, 10761 assertions).
- `composer test:functional` — OK (470 tests, 2823 assertions).
- `tests/Unit/Analytics tests/Unit/Company` — OK (187 tests, 666 assertions).
- Прод-проверка состава участников (read-only, с согласия Владельца):
  `company_members` = 3 × OWNER/ACTIVE + 1 × OPERATOR/ACTIVE. Legacy-значений роли нет,
  бэкфилл миграции покрывает 100% строк.

### Review status

- Stage 1: REVIEW_GREEN. Stage 2: REVIEW_GREEN (7 итераций).
- Stage 3: реализация не закончена, review не запускался.

### Exact next action

- Work item 3.3: перенумеровать `Version20260808120000` и `Version20260808130000` выше
  `Version20260809120000` (последняя применённая на проде), пересобрать тестовую БД, прогнать миграции.
- Далее 3.4–3.8 по plan.md.

### Files to inspect first on resume

- `docs/tasks/module-access-roles/plan.md` — Stage 3 DoD и Work items
- `site/migrations/Version20260808120000.php`, `site/migrations/Version20260808130000.php`
- `/tmp/.../scratchpad/discarded-module-access-roles.patch` — черновик write-гейтов Stage 3
  (86 гейтов, без тестов); патч живёт только до конца сессии, снят с дерева до мержа
