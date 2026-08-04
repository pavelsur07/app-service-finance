## Stage 2: Controller + маршруты + UI — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Следующее действие:** continue autonomously (Final Release Gate)

### Scope Stage
- Stage base commit: `1a05a2d982332c81bde1369207e96a85988dfc27` (= Stage 1)
- Work items completed: 2.1, 2.2, 2.3, 2.4

Изначально Controller (Stage 2) и UI (Stage 3) были запланированы раздельно.
По факту реализации объединены в один Stage: Controller, рендерящий
несуществующий шаблон, нетестируем и недеплоируем сам по себе, поэтому
Work items прежних 2.x и 3.x сделаны и отревьюены одним диффом (`plan.md`
обновлён соответственно).

### Что сделано
- `PLCategoryController::import()` (`GET /pl-categories/import`) — список
  компаний, доступных пользователю (кроме активной), опциональный dry-run
  preview по `?sourceCompanyId=`; ошибки Action (`\DomainException`) —
  flash `danger`, без падения страницы.
- `PLCategoryController::importApply()` (`POST /pl-categories/import/apply`)
  — CSRF-токен `'pl-category-import'.$targetCompanyId`, реальный импорт,
  redirect на список с flash `success`; на ошибку — redirect обратно на
  форму (без `sourceCompanyId`, чтобы не задваивать flash).
- Доступ к source-компании (`CompanyFacade::userHasAccess()`) проверяется
  на GET и POST **независимо** — POST не полагается на предшествующий GET.
- `templates/pl_category/import.html.twig` — легаси Tabler (per решение
  Владельца), тот же стиль, что `index.html.twig`/`new.html.twig`; ссылка на
  новый экран добавлена в `index.html.twig`.
- `PLCategoryImportControllerTest` — 6 функциональных тестов (список
  источников исключает target/чужие компании; GET 403 для недоступной
  компании; preview показывает create+update; POST 403 без валидного CSRF;
  POST 403 для недоступной source-компании даже с валидным CSRF — независимая
  проверка от GET; apply создаёт+обновляет+идемпотентен при повторном запуске).

### Затронутые файлы
- `src/Finance/Controller/PLCategoryController.php` — modified (+2 action)
- `templates/pl_category/import.html.twig` — new
- `templates/pl_category/index.html.twig` — modified (+ссылка)
- `tests/Functional/Finance/PLCategoryImportControllerTest.php` — new (6 тестов)
- `docs/tasks/pl-category-import/plan.md` — modified (объединение Stage 2/3)

### Self-review
- [x] Scope compliance
- [x] Patterns / naming — конвенции существующего `PLCategoryController` (Tabler, CSRF-идиома `isCsrfTokenValid`, `getUser() instanceof User`)
- [x] Forbidden actions — none
- [x] Security (companyId, IDOR) — target только из `ActiveCompanyService`; source валидируется на обоих маршрутах через `CompanyFacade::userHasAccess()` независимо; CSRF на мутирующем POST
- [x] PHPStan/CS-Fixer/tests — CS-Fixer чисто; `lint:twig` чисто; PHPStan не настроен в проекте
- [x] ARCHITECTURE.md updated — не требовалось (нет нового Facade/Enum/Entity в Stage 2)

### External review
- Reviewer: Codex CLI (`codex exec -s read-only --ephemeral`)
- Iterations: 2
- Result: **REVIEW_GREEN**
- Confirmed findings fixed:
  1. Не было теста на POST-apply с валидным CSRF, но недоступной source-компанией (IDOR был покрыт только для GET) → добавлен `testApplyIsForbiddenForInaccessibleSourceCompanyEvenWithValidCsrf`.
  2. Happy-path обновления (не только создания) не был покрыт — во всех сценариях target был пустым → тесты переработаны: заранее создаётся существующая target-категория с тем же code, но другим именем; проверяется, что она реально обновляется (по неизменному id), а не дублируется.
  3. При ошибке (`\DomainException`, например source==target) redirect сохранял `sourceCompanyId`, из-за чего GET заново пересчитывал dry-run и добавлял второй дублирующий flash → redirect теперь без `sourceCompanyId`.
- Rejected findings with reason: нет
- Ограничения ревьюера: без доступа к shell/файловой системе — весь контекст (Stage 1 API, бизнес-правила, решение Владельца про Tabler) передан в промпте текстом.

### Команды для проверки
- `docker compose run --rm site-php-cli composer test:unit` — 1753 теста, зелёные
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite functional` — 388 тестов, зелёные (включая новые 6)
- `docker compose run --rm site-php-cli php bin/console lint:twig templates/pl_category` — чисто
- `php-cs-fixer fix --dry-run --diff` точечно по изменённым PHP-файлам — чисто

### Риски / на что обратить внимание ревьюеру
- **Ручной smoke-test в реальном браузере не выполнен.** В этом окружении нет
  легко поднимаемого HTTP dev-сервера с браузерным доступом (веб-контур
  проекта завязан на внешний `traefik-public` network + `Host(localhost)`
  роутинг, не поднятый в текущей сессии). Вместо этого функциональность
  экрана проверена через Symfony `WebTestCase` (полный HTTP-цикл: роутинг →
  контроллер → Twig-рендер → CSRF → редиректы, с разбором реального DOM через
  crawler) и `lint:twig`. Это не заменяет визуальную проверку в браузере —
  явно фиксирую как ограничение, а не как «сделано и проверено».
- Наследуется из Stage 1: отсутствующий индекс `uniq_plcat_company_code` —
  отдельный дефект схемы вне scope.

### Открытые вопросы
- Нет.
