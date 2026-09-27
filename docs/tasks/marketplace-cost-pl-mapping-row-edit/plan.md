# marketplace-cost-pl-mapping-row-edit: страница /marketplace/cost-pl-mapping без нагрузки на БД

Бриф Владельца (чат, 2026-09-27): форма редактирования встроена в таблицу и сильно
нагружает БД; выбран вариант 1 — таблица только для чтения, правка одной строки
через модалку и JSON-эндпоинт.

Замер «до» (прод, read-only, 2026-09-27), крупнейшая компания: 107 категорий
затрат, 70 статей ОПиУ, 89 маппингов. По коду это даёт:
- GET страницы: ~4 + 70 запросов (дерево ОПиУ: `getChildren()` на каждый узел),
  107 × 71 ≈ 7 600 `<option>` в HTML, 107 CSRF-токенов в сессии;
- POST «Сохранить все»: 3 SELECT на строку + UPDATE на каждую строку даже без
  изменений (`update()` всегда трогает `updatedAt`) ≈ 430 запросов.

Baseline: `phpunit tests/Functional/Marketplace/Controller/CostPLMappingDefaultSetupControllerTest.php`
— OK 5/5; `tests/Integration/Finance/ImportPLCategoryTreeActionCodeCollisionTest.php` — OK 1/1.

## Stage 1: backend — один запрос на дерево, Query списка, Actions, эндпоинт строки
Risk: HIGH-LOCAL (новый эндпоинт, общий репозиторий Finance)
stage_base_commit: 5785dbc1
Definition of Done:
- `PLCategoryRepository::findTreeByCompany` — один SELECT, тот же DFS pre-order
  по `sortOrder`; тест на порядок и число запросов.
- `CostPLMappingListQuery` — одна выборка категорий с LEFT JOIN маппинга,
  фильтр компании и маркетплейса в SQL.
- `UpdateCostPLMappingAction`: создать/обновить маппинг одной категории, статья
  ОПиУ только своей компании, без изменений — без записи; `sortOrder` при
  создании больше не теряется.
- `DeleteCostCategoryAction`: логика удаления из контроллера.
- Контроллеры по одному action; `bulk-save` удалён; JSON-ошибки
  `{error:{code,message}}` 404/422.
- Тесты: Action happy + негативные (чужая компания, чужая статья ОПиУ,
  без изменений), регрессия `sortOrder`, функциональные на эндпоинт и IDOR.
- Исключено: пагинация (страница настройки ≤ ~110 строк на компанию, см. risks),
  изменение формул ОПиУ, семантики маппинга.
Work items:
- 1.1 — дерево ОПиУ одним запросом
- 1.2 — Query списка + DTO строки
- 1.3 — UpdateCostPLMappingAction + исключения + entity
- 1.4 — DeleteCostCategoryAction
- 1.5 — контроллеры, удаление bulk-save
Stage checks:
- phpunit по новым/затронутым тестам Marketplace и Finance; phpstan и cs по изменённым файлам
Reviewer focus:
- IDOR в эндпоинте строки; порядок дерева идентичен старому; отсутствие записи без изменений

## Stage 2: Twig — таблица только для чтения, модалка, fetch
Risk: MEDIUM
Definition of Done:
- в строке — текст статьи ОПиУ, бейдж «в ОПиУ», порядок; один `<select>` на странице;
- один CSRF-токен на правку и один на удаление;
- модалка: загрузка/ошибка 4xx с сообщением, обновление строки без перезагрузки;
- только классы, уже прошедшие `check:ui-kit`.
Work items:
- 2.1 — шаблон и JS
- 2.2 — функциональный тест страницы (рендер, один select)
Stage checks:
- phpunit функциональные; `npm run check:ui-kit`; ручной smoke
Reviewer focus:
- XSS при вставке имени в строку; состояние кнопки при двойном клике
