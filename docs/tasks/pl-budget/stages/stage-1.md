# Stage 1: страницы и API бюджета (backend)

**Risk:** MEDIUM
**Stage base commit:** `b19c6a138`

## 1. Какую задачу решаем

Дать пользователю раздел «Бюджет ОПиУ»: список вариантов года, создание
(пустой / копия), смену статуса и страницу варианта; дать будущей сетке
(этап 2) API чтения и сохранения ячеек. Итоги дерева считаются тем же кодом,
что и отчёт ОПиУ.

## 2. Что будет результатом

| Что | Где |
|---|---|
| Доступ | `ModuleAccessMap`: префикс `App\Budget\` → модуль FINANCE; запись — `FINANCE_WRITE`; утверждение — владелец (Action) |
| Дерево по готовым значениям | `FinanceFacade::calculatePlTree(companyId, from, to, columns)` — как `calculatePlPreview`, но суммы уже значения отчёта (доход − расход), без перевода по `flow`; `PlPreviewCalculator` с флагом |
| Чтение плана | `Budget\Infrastructure\Query\BudgetCellsQuery` — суммы и комментарии по статье × месяцу для варианта и пары (или по всем парам) |
| Сборка сетки | `Budget\Application\Service\BudgetGridBuilder` → `BudgetGridView`: строки дерева (id, код, название, уровень, тип, `editable`), 12 месяцев + итог, комментарии, предупреждения (лист без кода — в итоги не попадает) |
| Страницы (Twig, легаси) | `GET /finance/budget` — список вариантов года; `POST /finance/budget/versions` — создать; `POST /finance/budget/versions/{id}/status` — смена статуса; `GET /finance/budget/versions/{id}` — страница варианта (шапка, баннеры, действия, точка монтирования острова с `data-props`) |
| JSON | `GET /finance/budget/versions/{id}/grid?project=&center=` и `POST /finance/budget/versions/{id}/cells` (`X-CSRF-Token`); ошибки `{error:{code,message}}` через `BudgetExceptionListener` |
| Меню | пункт «Бюджет ОПиУ» в разделе финансов (`_sidebar_report`) |

## 3. Что считаем выполненной работой

- Каждый контроллер и метод Facade покрыт functional/integration-тестом,
  написанным до кода (красный → зелёный).
- Изоляция компании: чужой вариант — 404 на каждом маршруте.
- Права: чтение видит, запись правит, владелец утверждает; без записи POST — 403.
- Тесты модуля, Finance, ModuleAccess — зелёные; PHPStan и CS изменённых
  файлов — чисто; `ARCHITECTURE.md` обновлён.

## 4. Чек-лист приёмки

- [x] `/finance/budget` показывает варианты выбранного года: утверждённый
      первым, черновики, архив отдельным блоком; чужие не видны.
- [x] Создание пустого и копии через форму; двойная отправка (тот же скрытый
      UUID) — один вариант; ошибка ввода — flash, без 500.
- [x] Смена статуса с CSRF; участник без прав владельца получает понятный
      отказ при утверждении; второй утверждённый на год — сообщение.
- [x] Пользователь только с чтением не может создать, сменить статус,
      сохранить ячейки (403).
- [x] `grid` отдаёт дерево с итогами, совпадающими с формулами отчёта ОПиУ
      (итог без формулы — сумма детей с весом; итог с формулой и KPI — по
      формуле); выбор пары фильтрует, без пары — сумма всех пар.
- [x] `cells` сохраняет пачку и возвращает 422 с `code` на неверной ячейке,
      409/422 на утверждённом варианте, 404 на чужом.
- [x] Модуль виден в меню группы «Доходы и расходы» (решение Владельца
      04.11: дизайн по DS раздела ОПиУ).

## Work items

- 1.1 — `ModuleAccessMap` + тест доступа.
- 1.2 — `FinanceFacade::calculatePlTree()` (integration-тест: итог, формула,
  KPI, лист без кода).
- 1.3 — `BudgetCellsQuery` + `BudgetGridBuilder` (integration).
- 1.4 — список и создание (functional).
- 1.5 — смена статуса (functional).
- 1.6 — страница варианта, `grid`, `cells`, `BudgetExceptionListener`
  (functional).
- 1.7 — меню, `ARCHITECTURE.md`, ревью, итог.

---

### Stage 1: страницы и API бюджета (backend) — DONE

**Risk:** MEDIUM
**Stage base commit:** `b19c6a138`
**Work items:** 1.1–1.7

#### What was done
- Доступ: `App\Budget\` → FINANCE в `ModuleAccessMap`, мутирующие маршруты —
  `FINANCE_WRITE`.
- Finance: `FinanceFacade::calculatePlTree()` (флаг `reportValues` в
  `PlPreviewCalculator` — суммы уже в знаке отчёта), `PLCategoryDTO::$flow`,
  `$isVisible`.
- Budget: `BudgetCellsQuery`, `BudgetGridBuilder` (12 месяцев + `total`),
  контроллеры списка, создания, смены статуса, экрана варианта, `grid`,
  `cells`; `BudgetExceptionListener` (JSON `{error:{code,message}}`).
- Шаблоны по DS раздела ОПиУ (указание Владельца 04.11): `vf-page-head` с
  крошками «Доходы и расходы», `vf-card` с вкладкой «Варианты», сегменты лет
  `vf-seg` в `vf-card-bar`, `vf-table`, действия статуса через
  `_confirm_modal`, недоступное — неактивно с причиной; пункт меню в группе
  «Доходы и расходы». `ui-ux.md` §0 — эталон визуала.
- Отклонения от плана: меню — группа «Доходы и расходы» вместо «Деньги и
  отчёты»; в списке пока нет колонки «Итог года» (вопрос `ui-ux.md` §7).

#### Definition of Done
- [x] TDD: functional- и integration-тесты сначала красные.
- [x] Тесты зелёные, PHPStan и CS по изменённым файлам чистые, Twig lint OK.
- [x] `ARCHITECTURE.md` (Budget: маршруты, сетка, ошибки; Finance:
      `calculatePlTree`, поля DTO; Changelog 2.128), `checkpoint.md`.

#### Checks
- Budget, Finance, Company/Security, `ModuleWriteGateCoverageTest`,
  `ModuleMixedRouteGateTest`, Shared/Doctrine, `MessengerRoutingTest`, тесты
  потребителей `FinanceFacade`/`PLCategoryFacade` — OK (558 тестов).
- `phpstan analyse` изменённых файлов — OK; `php-cs-fixer` (без кеша) — OK.

#### Internal review
- iterations: 1; найдено и исправлено: API без `Accept` отдавал бы HTML 500
  (регрессионный тест); первый `h1` страницы — логотип меню, тесты проверяют
  `.vf-page-head h1`.

#### External review
- required: no (MEDIUM).

#### Risks / reviewer focus
- 13 проходов калькулятора ОПиУ на одну сетку (FOLLOW-UP, замерить на
  большой компании в этапе 3).

#### Next
- Stage 2 (React-сетка); знак при вводе — по предложению `ui-ux.md` §7.1 до
  ответа Владельца.
