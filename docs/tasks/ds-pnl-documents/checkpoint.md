## Current checkpoint

**Phase:** Stage 3 / Work item 3.1
**Status:** implementing
**Stage base commit:** Stage 1 — `f70ad721`

### Completed
- Stage 1: `DocumentListDTO` (q, проект, ЦФО, направление), `DocumentRepository::findByCompany` (EXISTS по операциям),
  `summarizeByCompany(companyId, dto)` → `DocumentListTotalsDTO`, `FinancialResponsibilityCenterFacade::getNamesByIds()`,
  контроллер передаёт `filters`, `totals`, `responsibilityCenterNames`, `projectDirections`, `responsibilityCenters`.

- Stage 2: список по скриншотам 01–02 (вкладки → фильтры → чипсы → итог → таблица → пагинация), раскрытие операций
  `<details>` + `:has` без JS, сворачивание ≤1340 px, меню строки, пустые состояния, группа меню «Доходы и расходы».

### Checks and baseline
- baseline `tests/Functional/Finance` — 125 OK
- Stage 1: unit DTO + `DocumentRepositoryListTest` + CFO facade + `tests/Functional/Finance` — OK; PHPStan по файлам — OK; cs — OK
- запросы списка (10 док.): до 6, после Stage 1 — 9 (итог, проекты, ЦФО), не зависят от числа документов

- Stage 2: `tests/Functional/Finance` + Sidebar/ModuleAccess — OK; новый `DocumentIndexViewTest` — 5 OK; lint/build — OK;
  smoke 12/12 (headless, 1280); 1440/1280/1100 — без горизонтального скролла; запросов 9 при 10 и при 1 документе

### Exact next action
- Stage 3: ветка `feat/ds-pnl-documents-edit` от Stage 2, страница документа по скриншотам 05–07

### Files to inspect first on resume
- `docs/tasks/ds-pnl-documents/plan.md`, `site/templates/document/index.html.twig`, `site/assets/styles/vf-custom-classes.css`
