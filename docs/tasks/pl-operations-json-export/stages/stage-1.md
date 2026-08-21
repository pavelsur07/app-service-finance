## Stage 1: Выгрузка операций ОПиУ в JSON — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP, ждать Владельца (Release Gate)

### Scope Stage
- Stage base commit: `19202c6c`
- Ветка: `feat/pl-operations-json-export`
- Work items completed: `1.1` сервис выгрузки, `1.2` контроллер и маршрут, `1.3` кнопка в шапке, `1.4` функциональные тесты, `1.5` ARCHITECTURE.md

### Что сделано
- `PlOperationJsonExporter` — единственное место сборки плоской выгрузки: строка = одна `DocumentOperation` с продублированными полями своего документа. Один DBAL-запрос, без гидрации ORM и без N+1.
- Маршрут `GET /documents/operations/export.json` (`document_operations_export_json`), single-action контроллер, `#[IsGranted('ROLE_USER')]` — ровно уровень доступа страницы списка.
- Кнопка «Выгрузить JSON» в правой части шапки страницы «Операции ОПиУ», обёртка `btn-list` рядом с «+ Добавить документ».
- Конверт `{exported_at, company, count, operations[]}` — домашний стиль репозитория (`SalesJsonExportController`). Имя файла `pl-operations-{Ymd-His}.json`.
- Изоляция компании: `WHERE d.company_id` плюс `company_id = d.company_id` в каждом JOIN справочника.

### Затронутые файлы
- `site/src/Finance/Infrastructure/Export/PlOperationJsonExporter.php` — new
- `site/src/Finance/Controller/PlOperationJsonExportController.php` — new
- `site/tests/Functional/Finance/Controller/PlOperationJsonExportControllerTest.php` — new
- `site/templates/document/index.html.twig` — modified
- `ARCHITECTURE.md` — modified
- Миграций нет.

### Решения по scope
- **Формат и объём согласованы с Владельцем явно:** плоские операции, все операции активной компании.
- **Фильтры страницы не чинятся.** `dateFrom/dateTo/type/status/number/counterparty` из offcanvas сломаны до задачи: шаблон их шлёт, `DocumentController::index` и `DocumentListDTO` читают только `page`/`limit`. Починка — отдельная задача, выгрузка их не учитывает.
- **Двухсегментный путь маршрута.** `/documents/export.json` был бы перехвачен `document_show` (`/documents/{id}`, регистрируется раньше). Существующие маршруты не менялись.
- **Без пагинации и стриминга** — как все 7 существующих `export.json` в проекте. Потолок и путь апгрейда (`StreamedResponse` + `iterateAssociative()`) помечены комментарием в коде.

### Self-review
- [x] Scope compliance — вне scope ничего не тронуто
- [x] Patterns / naming — `final class` / `final readonly class`, `declare(strict_types=1)`, single-action `__invoke`
- [x] Forbidden actions — none (нет `dump()`, нет `SELECT *`, нет бизнес-логики в контроллере, нет `new Service()`)
- [x] Security (companyId, IDOR) — единственный источник companyId `ActiveCompanyService::getActiveCompany()`; изоляция закреплена двумя тестами
- [x] CS-Fixer / tests — green
- [x] ARCHITECTURE.md updated — раздел «Finance: выгрузка операций ОПиУ в JSON»

### Доказательство тестов красным (мутационная проверка)
- Перестановка `category` / `category_code` и переворот `COALESCE` в экспортере → 3 из 5 тестов падают.
- Снятие `AND x.company_id = d.company_id` с JOIN-ов → `testDoesNotLeakForeignCompanyReferenceNames` падает: `Failed asserting that 'Чужая категория' is null`.

### External review
- Reviewer: Codex CLI 0.148.0 (`codex exec -s read-only --ephemeral`, дифф и контекст переданы через stdin)
- Iterations: 3
- Result: REVIEW_GREEN (итерация 3 — находок нет)
- Confirmed findings fixed:
  - Итерация 1, MINOR: тест не различал `name` и `code` категории, не покрывал документ с несколькими операциями и fallback контрагента/проекта → тестов стало 5, добавлены различающиеся `name`/`code`, документ с двумя операциями, сценарий override/fallback.
  - Итерация 2, IMPORTANT: JOIN-ы справочников не ограничены компанией — строка с чужой ссылкой отдала бы чужое название категории/контрагента/проекта → в каждый JOIN добавлено `AND x.company_id = d.company_id`, добавлен тест `testDoesNotLeakForeignCompanyReferenceNames`.
- Rejected findings with reason: нет
- Ограничения ревьюера: без доступа к шеллу и к схеме БД — DDL справочных таблиц, состав маршрутов, уровень доступа страницы и результаты прогонов переданы в промпте. Наличие индексов ревьюер подтвердить не мог.

### Команды для проверки
- `docker compose run --rm site-php-cli php bin/phpunit --filter PlOperationJsonExportControllerTest` → OK (6 тестов, 41 assertion)
- `docker compose run --rm site-php-cli php bin/phpunit tests/Functional/Finance` → OK (78 тестов, 1186 assertions)
- `docker compose run --rm site-php-cli composer test:unit` → OK (1900 тестов, 10866 assertions)
- `docker compose run --rm site-php-cli composer test` (полный набор) → OK (3404 теста, 19141 assertion, 7 pre-existing deprecations)
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff <3 новых файла>` → 0 нарушений
- `docker compose run --rm site-php-cli php bin/console lint:twig templates/document/index.html.twig` → OK
- `docker compose run --rm site-php-cli php bin/console debug:router | grep documents` → `document_operations_export_json  GET  /documents/operations/export.json`

### Риски / на что обратить внимание ревьюеру
- Выгрузка читает весь набор операций активной компании в память. Для крупной компании ответ может быть тяжёлым; лимита нет сознательно, как у остальных `export.json`. Объём на PROD не замерялся — прод-доступ не запрашивался.
- Индексы под `documents.company_id` и `document_operations.document_id` в диффе не проверялись: миграции не менялись, запрос идёт по существующим FK-колонкам.
- Ручной smoke на живой странице не выполнялся: dev-стек этого чекаута поднят только для тестов.

### Открытые вопросы
- нет
