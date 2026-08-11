## Stage 3: Write-гейты finance/deals/catalog/admin — DONE

**Риск:** 🟠 HIGH-LOCAL (авторизация + миграции)
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Следующее действие:** continue autonomously к Stage 4 (write-гейты marketplace)

### Scope Stage

- Stage base commit: `32d181ae`
- Work items completed: `3.1`–`3.8`

### Что сделано

**3.1 — merge master (`bc030ed4`).** Ветка отставала на 42 коммита. Шесть конфликтов, из них один
продуктовый: Stage 1 забрал роут `/dashboard` под легаси финансовый дашборд и удалил
`home/dashboard.html.twig`, а master развивал именно этот шаблон (React DashboardGrid на snapshot
API, UI мультивалютных переводов). Резолюция сохранила обе стороны:

| Роут | Имя | Что отдаёт |
|---|---|---|
| `/` | `app_home_index` | `HomeRedirectController` — редирект по доступным модулям |
| `/finance` | `app_finance_index` | легаси финансовый дашборд |
| `/dashboard` | `app_dashboard_index` | React-пилот master |

`DashboardSnapshotService` сложил валюту ДДС от master и гейтинг по `module.finance.read` от ветки;
ключ кэша разделён по обоим измерениям.

**3.2 — правки плана (`9db72e3a`).** Снят self-escalation: план обещал заменить `assertOwner` на
`module.admin.write`. Снят Work item 4.4 (master удалил `DebugWipeCompanyDataController` в
`c45a9f74`). Зафиксирован owner-гейт `ReportApiKeyController`. Карта модулей приведена к коду —
группы `system` в enum нет.

**3.3 — перенумерация миграций (`1fada4bb`).** `20260808120000/130000` → `20260811120000/130000`,
потому что на проде уже применена `Version20260809120000`, а master деплоится автоматически.

**3.4–3.5 — write-гейты (`0fb40b53`).** 89 гейтов: 71 `FINANCE_WRITE`, 11 `DEALS_WRITE`,
6 `CATALOG_WRITE`, 1 `ADMIN_WRITE`. POST-only — атрибутом, смешанные `GET+POST` — рантайм-проверкой
(атрибут на класс гейтил бы и чтение). Черновик Stage 3 добавлял `use ModuleAccess` без самих
гейтов в 19 файлов: 9 GET-only получили удаление импорта, 10 мутирующих — настоящие гейты.

**3.6 — уникальность имени шаблона (`abe04bd1`).** Частичный функциональный индекс
`uniq_company_role_company_name` по `(company_id, LOWER(name)) WHERE company_id IS NOT NULL`.
Системные шаблоны не покрывает намеренно: в Postgres NULL-ы в уникальном индексе различны.

**3.7 — `flush()` в Action-слой (`abe04bd1`).** `SaveCompanyRoleAction`, `DeleteCompanyRoleAction`,
`AssignCompanyMemberAccessRoleAction`. `save()`/`remove()` убраны из репозиториев. Прошлый Stage
Report списывал это как «нет Action-слоя» — обоснование было ложным, `src/Company/Application/`
содержит 10+ Action.

**3.8 — тесты (`abe04bd1`, доработаны по ревью).** `ModuleWriteGateTest` — матрица по 4 группам
через DataProvider. Плюс дубль имени шаблона, перенос валюты в редиректе, каскад шаблонов при
удалении компании, разделение кэша по праву на финансы.

### Затронутые файлы

- `site/src/Company/Application/{SaveCompanyRole,DeleteCompanyRole,AssignCompanyMemberAccessRole}Action.php` — new
- `site/src/Company/Exception/CompanyRole{NameAlreadyExists,InUse,NotAvailable}Exception.php` — new
- `site/migrations/Version20260811{120000,130000}.php` — renamed
- `site/migrations/Version20260811{140000,150000}.php` — new
- `site/src/Company/Security/ModuleAccessResolver.php` — modified (снят BC-fallback)
- `site/src/Company/Entity/{CompanyMember,CompanyInvite}.php` — modified (`onDelete: RESTRICT`)
- `site/src/Company/Repository/{CompanyRole,CompanyMember}Repository.php` — modified
- `site/src/Company/Controller/{CompanyRole,CompanyMember}Controller.php` — modified
- `site/src/Shared/Controller/HomeRedirectController.php` — modified (перенос `currency`)
- `site/src/Finance/Controller/HomeController.php` — modified (роут `/finance`)
- `site/src/Analytics/Application/DashboardSnapshotService.php` — modified (merge)
- 45 контроллеров Cash/Catalog/Company/Deals/Finance/Loan/Telegram — modified (гейты)
- `site/tests/Functional/Company/ModuleWriteGateTest.php` — new
- `ARCHITECTURE.md` — modified (раздел подсистемы, enum, Entity, версия 1.77)

### Self-review

- [x] Scope compliance — посторонних файлов нет (`git diff origin/master..HEAD` только по task-путям)
- [x] Patterns / naming — Action `final readonly`, исключения доменные
- [x] Forbidden actions — `dump()/dd()/var_dump()` отсутствуют; `flush()` вынесен из репозиториев
- [x] Security — owner-гейты сохранены, BC-fallback fail-open устранён
- [x] cs-fixer точечно по файлам Stage 3 — чисто; тесты зелёные
- [x] ARCHITECTURE.md обновлён

### Проверки

- `make site-test-unit` — OK (1874 tests, 10765 assertions)
- `composer test:functional` — OK (485 tests, 2855 assertions)
- `make site-test-db-rebuild` — OK, 236 миграций до `Version20260811150000`
- `doctrine:schema:update --dump-sql` — по FK `role_id` расхождений нет; остаётся
  pre-existing drift по типам timestamp и ложный `DROP INDEX` функционального индекса
- `php-cs-fixer` точечно по файлам Stage 3 — чисто (репозиторный `cs:check` красный по baseline)
- Регрессия доказана красным: со снятым гейтом в `CounterpartyController` и `DealController::new`
  соответствующие кейсы `ModuleWriteGateTest` падают (200 вместо 403)

### Внутреннее ревью

- Итераций: 2
- Найдено самостоятельно: `ARCHITECTURE.md` не знал о подсистеме вообще (Stage 1 и 2 его не
  обновили); `git add -A site/` затянул в коммит два чужих PNG аудита UI Kit — убраны из индекса.

### Внешнее ревью

- Reviewer: Codex CLI 0.147.0
- Итераций: 3
- Результат: **REVIEW_GREEN**
- Подтверждённые находки исправлены:
  - IT1 IMPORTANT: `/` терял `currency` — BC-регрессия, которую маскировала правка теста
  - IT1 IMPORTANT: тест deals не изолировал `DEALS_WRITE`, admin не покрыт
  - IT1 IMPORTANT: TOCTOU-эскалация при удалении шаблона
  - IT1 MINOR: проверка уникальности расходилась с индексом
  - IT1 MINOR: тест не доказывал разделение кэша по праву на финансы
  - IT2 IMPORTANT: ORM-маппинг `onDelete` разошёлся с БД после RESTRICT
  - IT2 MINOR ×3: 500 при гонке удаления; тривиальный тест DISABLED; устаревший комментарий
  - IT3 MINOR ×2: обратная гонка при назначении; `assertNotSame(403)` пропускал 500
- Отклонённых находок: нет
- Ограничения ревьюера: локальная песочница Codex не запускалась
  (`bwrap: loopback: Failed RTM_NEWADDR`), поэтому дифф, контекст и результаты прогонов
  передавались в промпте через stdin. Ревьюер не мог самостоятельно проверить Git-статус,
  untracked-файлы и прогоны — эти факты приняты с моих слов.

### Ключевое решение ревью: как закрыли эскалацию

Ревьюер предложил перевести FK на `RESTRICT`. Это принято, но корнем была не гонка, а
BC-fallback в `ModuleAccessResolver`: `OPERATOR` без `accessRole` получал полный доступ, поэтому
любое обнуление `role_id` повышало права вместо их снятия. Fallback снят — его срок истёк на
Stage 2 (миграция проставила шаблоны всем участникам, оба пути создания участника назначают
шаблон). `RESTRICT` добавлен сверху как инвариант БД.

Проверено эмпирически, что `RESTRICT` не ломает удаление компании: каскад
`companies → company_role` для компании без участников работает
(`testCompanyDeletionStillCascadesUnassignedRoles`).

### Риски / на что обратить внимание ревьюеру

- Read-гейт fail-closed действует на все 202 контроллера. Контроллер в новом неймспейсе без записи
  в `ModuleAccessMap` получит 403; ловит `ControllerAccessCoverageTest` на CI.
- Снятие BC-fallback меняет поведение для участника с `role_id IS NULL`. На проде таких нет
  (проверено read-only: 3 × OWNER/ACTIVE + 1 × OPERATOR/ACTIVE, все с шаблонами).
- Stage 4 и 5 не сделаны: write-гейтов marketplace нет, меню не скрывается. UI шаблонов ролей
  показывать до Stage 5 не следует — участник увидит полное меню и будет ловить 403 по клику.

### Follow-ups (вне scope Stage 3)

- Компанию с участниками нельзя удалить: `fk_company_members_company` объявлен `NO ACTION`, ORM
  участников не каскадит. Pre-existing дефект master, тестов на удаление компании не было.
- `$roleRepository->find($roleId)` на write-пути без валидации UUID: мусорная строка в
  UUID-колонке даст 500 вместо 400. Company-принадлежность проверяется после, IDOR закрыт.
- `CompanyController::setActive` требует владельца, поэтому участник не может переключиться на
  компанию, где он участник. Pre-existing.
- `src/Mcp/Application/Tool/CashCategoryUpsertTool.php` — нарушение порядка трейтов пришло
  с master, намеренно не тронуто.
- Частичный функциональный индекс не выражается атрибутами ORM: `migrations:diff` будет предлагать
  `DROP INDEX uniq_company_role_company_name`, строку нужно удалять вручную.

### Открытые вопросы

нет
