# Checkpoint — module-access-roles

## Current checkpoint

**Phase:** Final Release Gate
**Status:** done — все Stage закрыты, handoff готов
**Stage base commit:** 32d181ae (Stage 2 committed and pushed)
**Current Work item:** none
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
- Stage 5 — DONE: меню скрывает разделы недоступных модулей; «Отладка» вынесена из-под
  admin-гейта; SidebarModuleVisibilityTest. REVIEW_GREEN за 2 итерации. Stage Report: stages/stage-5.md.
- Stage 4 — DONE: 64 гейта MARKETPLACE_WRITE (59 атрибутом, 5 рантайм), статический инвариант
  ModuleWriteGateCoverageTest и поведенческий ModuleMixedRouteGateTest по 42 смешанным маршрутам.
  Инварианты вскрыли 10 пропусков Stage 3 (модуль переводов и массовое удаление приехали с master
  после черновика гейтов) — закрыты. Внешнее ревью: REVIEW_GREEN за 8 итераций, из них BLOCKER —
  ROLE_COMPANY_OWNER оказалась глобальной ролью, а не владением активной компанией.
  Stage Report: stages/stage-4.md.
- Work items 3.3–3.8 — перенумерация миграций, write-гейты (89 шт.), unique index,
  flush в Action-слой, тесты. Внешнее ревью Codex: REVIEW_GREEN за 3 итерации
  (3+1 IMPORTANT и 2+3+2 MINOR — все исправлены). Stage Report: stages/stage-3.md.
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

- `make site-test-db-rebuild` — OK, 236 миграций, до `Version20260811150000`.
  Миграции ветки перенумерованы выше задеплоенной на прод `Version20260809120000`.
- `make site-test-unit` — OK (1874 tests, 10765 assertions).
- `composer test:functional` — OK (493 tests, 2898 assertions).
- `bin/console lint:twig templates` — OK (229 файлов).
- `composer test:integration` — OK (967 tests, 4494 assertions).
- `doctrine:schema:update --dump-sql --env=test` — по FK `role_id` расхождений нет.
  Остаются pre-existing drift по типам timestamp и ложный `DROP INDEX` функционального индекса.
- `php-cs-fixer` точечно по файлам Stage 3 — чисто. Репозиторный `cs:check` красный по baseline.
- Прод-проверка состава участников (read-only, с согласия Владельца):
  `company_members` = 3 × OWNER/ACTIVE + 1 × OPERATOR/ACTIVE. Legacy-значений роли нет,
  бэкфилл миграции покрывает 100% строк, поэтому снятие BC-fallback никого не отрезает.

### Review status

- Stage 1: REVIEW_GREEN (4 итерации). Stage 2: REVIEW_GREEN (7 итераций).
- Stage 5: REVIEW_GREEN (2 итерации).
- Stage 4: REVIEW_GREEN (8 итераций). 1 BLOCKER, 16 IMPORTANT, 6 MINOR — исправлены;
  одно частичное отклонение с обоснованием (fixtures для параметризованных маршрутов → follow-up).
- Stage 3: REVIEW_GREEN (3 итерации, Codex CLI 0.147.0). 4 IMPORTANT и 7 MINOR подтверждены
  и исправлены, отклонённых находок нет. Ограничение: песочница Codex не запускалась
  (`bwrap: loopback`), дифф и контекст передавались через stdin.
- Нерешённых BLOCKER/IMPORTANT нет.

### Exact next action

- Ожидание решения Владельца. PR #2315 в Draft, CI зелёный, handoff.md собран.
- Merge в `master` разворачивает production deploy автоматически, поэтому разрешение
  должно явно называть и merge, и deploy.

### Files to inspect first on resume

- `docs/tasks/module-access-roles/plan.md` — Stage 4 и 5
- `docs/tasks/module-access-roles/stages/stage-3.md` — Stage Report и follow-ups
- `site/src/Company/Security/ModuleAccessMap.php` — карта, куда добавлять marketplace-гейты
- `site/tests/Functional/Company/ModuleWriteGateTest.php` — матрица гейтов, расширять на marketplace
