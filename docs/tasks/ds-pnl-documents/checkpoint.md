## Current checkpoint

**Phase:** handoff
**Status:** done
**Stage base commit:** Stage 1 — `f70ad721`

### Completed
- Stage 1: `DocumentListDTO` (q, проект, ЦФО, направление), `DocumentRepository::findByCompany` (EXISTS по операциям),
  `summarizeByCompany(companyId, dto)` → `DocumentListTotalsDTO`, `FinancialResponsibilityCenterFacade::getNamesByIds()`,
  контроллер передаёт `filters`, `totals`, `responsibilityCenterNames`, `projectDirections`, `responsibilityCenters`.

- Stage 2: список по скриншотам 01–02 (вкладки → фильтры → чипсы → итог → таблица → пагинация), раскрытие операций
  `<details>` + `:has` без JS, сворачивание ≤1340 px, меню строки, пустые состояния, группа меню «Доходы и расходы».

- Stage 3 (ветка `feat/ds-pnl-documents-edit`): страница документа по 05–07 (`_page.html.twig`, `_page_scripts.html.twig`),
  `show`/`deleted_index` на токенах; fetch-join в «Удалённых» (N+1); баги: 500 при операции без суммы у документа с основанием,
  пустая сумма проходила валидацию; поле «Комментарий», `data-flow` у статей.

### Checks and baseline
- baseline `tests/Functional/Finance` — 125 OK
- Stage 1: unit DTO + `DocumentRepositoryListTest` + CFO facade + `tests/Functional/Finance` — OK; PHPStan по файлам — OK; cs — OK
- запросы списка (10 док.): до 6, после Stage 1 — 9 (итог, проекты, ЦФО), не зависят от числа документов

- Stage 2: `tests/Functional/Finance` + Sidebar/ModuleAccess — OK; новый `DocumentIndexViewTest` — 5 OK; lint/build — OK;
  smoke 12/12 (headless, 1280); 1440/1280/1100 — без горизонтального скролла; запросов 9 при 10 и при 1 документе

- Stage 3: Finance unit+integration+functional 303 OK; `DocumentEditPageTest` 4 OK (регрессия красная на старом коде — 500);
  smoke 15/15 (1440/1280)

- handoff: cs/strict 0 из 2982, PHPStan OK, полный phpunit 6446 OK; ревью внутреннее + Codex — находки исправлены

### Exact next action
- push обеих веток, PR 1 (база master), PR 2 (база PR 1), вопрос «merge and deploy»

### Files to inspect first on resume
- `docs/tasks/ds-pnl-documents/plan.md`, `site/templates/document/index.html.twig`, `site/assets/styles/vf-custom-classes.css`
