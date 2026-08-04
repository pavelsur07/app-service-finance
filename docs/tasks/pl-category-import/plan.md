# Task: Импорт дерева категорий ОПиУ (PLCategory) между компаниями

Источник: бриф Владельца в чате (approved plan, см. также
`/home/deploy/.claude/plans/swift-foraging-ritchie.md` — тот же контент,
локальная копия plan-mode).

## Контекст

Экспорт JSON уже есть (`GET /pl-categories/export/json`), обратной операции
нет. Нужна прямая копия дерева ОПиУ компания→компания (без файла), для
пользователей с доступом к нескольким компаниям. ДДС не трогаем.

Согласованные бизнес-правила:
- При совпадении узла — обновлять поля из источника, никогда не удалять
  узлы target, которых нет в источнике.
- Транспорт — прямая копия компания→компания, без файла.
- Выбор компании-источника — обычный `<select>`, EntityPicker не подключаем.

## Матчинг узлов

`PLCategory` уникален по `(company, code)` в рамках всей компании (не только
среди siblings):
- `code` источника задан → искать target-узел по `(company, code)`.
- `code` источника `null` → искать по `(company, parent, name)` (как
  `AccountBootstrapper::ensurePL`).
- Найден → обновить `name/type/format/flow/expenseType/weightInParent/
  isVisible/formula/calcOrder/sortOrder`, не трогать `id`/`company`.
- Не найден → создать, `parent` — уже обработанный узел этого прохода.
- Ничего не удалять.
- Глубина ≤5 валидируется в `setParent()` — ошибка ветки не глотается.

## Stage 1: Backend core — matching engine + Company facade
Risk: 🟡 MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: fcd8b9874df93e2a878bd4506dc61e702c62be6

Definition of Done:
- `ImportPLCategoryTreeAction` умеет применить дерево источника в target
  компанию (create/update, dry-run и real), покрыт unit-тестами.
- `CompanyFacade` умеет отдать список доступных пользователю компаний и
  проверить доступ к произвольной компании.
- `exportJson` сериализует `expenseType` (round-trip fix).
- `ARCHITECTURE.md` обновлён.

Work items:
- 1.1 — Fix: добавить `expenseType` в `PLCategoryController::serializeCategory`.
- 1.2 — `CompanyMemberRepository::findActiveByUserId(string $userId): array`;
  проверить/добавить `CompanyRepository::findByUserId(string $userId): array`.
- 1.3 — `CompanyFacade::listAccessibleCompaniesForUser(string $userId): array`
  и `CompanyFacade::userHasAccess(string $companyId, string $userId): bool`.
- 1.4 — `Finance/Application/Command/ImportPLCategoryTreeCommand.php`,
  `Finance/Application/DTO/ImportPLCategoryTreeResult.php`,
  `Finance/Application/Action/ImportPLCategoryTreeAction.php` — matching
  engine по правилам выше.
- 1.5 — Расширить `PLCategoryBuilder` (`withCode`, `withParent`,
  `withExpenseType`); unit-тесты `ImportPLCategoryTreeActionTest`.
- 1.6 — Unit-тесты `CompanyFacade` новых методов.
- 1.7 — Обновить `ARCHITECTURE.md` (секция `CompanyFacade`).

Stage checks:
- `make site-test-unit` (или targeted PHPUnit filter по новым классам).
- `make site-cs-check` точечно по изменённым файлам.

Reviewer focus:
- Матчинг по code vs (parent,name) — нет ли дублей/пропусков веток.
- Нет удаления существующих target-узлов.
- `CompanyFacade` не протекает Entity наружу модуля (DTO/scalar).
- companyId/userId scoping — нет IDOR.

## Stage 2: Controller + маршруты (публичный endpoint)
Risk: 🟠 HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: <зафиксировать перед стартом Stage 2>

Definition of Done:
- `GET /pl-categories/import` — список источников (без target-компании),
  опциональный dry-run preview по `sourceCompanyId`.
- `POST /pl-categories/import/apply` — CSRF, реальный импорт, redirect+flash.
- Доступ к source-компании валидируется на обоих маршрутах.
- Functional-тесты зелёные.

Work items:
- 2.1 — `pl_category_import` (GET): список источников + dry-run preview.
- 2.2 — `pl_category_import_apply` (POST): CSRF + apply + flash + redirect.
- 2.3 — `PLCategoryImportControllerTest` (WebTestCaseBase): access-control,
  preview-счётчики, apply, неверный CSRF, идемпотентный повторный импорт.

Stage checks:
- `make site-test` (полный набор — новый функциональный тест затрагивает
  security/routing).
- `make site-cs-check` точечно.

Reviewer focus:
- IDOR: нельзя импортировать из компании, к которой нет доступа.
- CSRF на apply.
- Target-компания всегда берётся из `ActiveCompanyService`, не из
  пользовательского ввода.

## Stage 3: UI (легаси Tabler, тот же стиль, что остальные `pl_category/*`)
Risk: 🟡 MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: <зафиксировать перед стартом Stage 3>

Решение Владельца (обновлено в чате после Stage 1): экран импорта — легаси
на базе Tabler, как `index.html.twig`/`new.html.twig`/`edit.html.twig` этого
же модуля (`page-header` + `.card > .card-body`, `.btn.btn-primary`,
`badge bg-*-lt`), а не UI Kit. UI Kit для этого экрана не используем.

Definition of Done:
- `templates/pl_category/import.html.twig` — тот же Tabler-стиль, что и
  соседние шаблоны модуля.
- Ссылка-кнопка на новый экран с `pl_category/index.html.twig`.
- Ручной smoke-test в браузере (dev-сервер).

Work items:
- 3.1 — `import.html.twig`: селектор источника (`<select>`) + форма dry-run
  (GET), в Tabler-разметке модуля.
- 3.2 — Блок preview (таблица создать/обновить, пустое состояние текстом) +
  форма apply (POST, CSRF), Tabler `card`/`table`/`badge`.
- 3.3 — Кнопка-ссылка на `pl_category/index.html.twig`.
- 3.4 — Ручная проверка в браузере: 2 компании, дерево с/без code, preview,
  apply, повторный импорт (0/0).

Stage checks:
- `make site-cs-check` точечно (twig не проверяется cs-fixer, но связанные
  php-файлы — да).
- Ручной smoke-test — обязателен (UI-задача).

Reviewer focus:
- Разметка/классы согласованы с существующими `pl_category/*` (не мешаем
  Tabler с UI Kit на одном экране).
- Дерево/предпросмотр читаемы при глубокой вложенности (до 5 уровней).

## Definition of Done задачи целиком

- Экспорт/импорт ОПиУ работает симметрично (округление до `expenseType`).
- Повторный импорт идемпотентен.
- Нет удаления существующих категорий.
- Нет IDOR: источник и target всегда явно проверяются на доступ пользователя.
- `ARCHITECTURE.md` актуален.
- Draft PR создан/обновлён после каждого Stage.

## Вне рамок (сознательно)

- ДДС/баланс/проекты — не переносим.
- Импорт файлом (JSON upload) — не строим.
- `EntityPicker` для выбора компании — не подключаем.
- Миграция legacy Tabler-шаблонов `pl_category/*` на UI Kit.
