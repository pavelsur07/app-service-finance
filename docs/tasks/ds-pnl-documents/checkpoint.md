## Current checkpoint

**Phase:** Stage 2 / Work item 2.1
**Status:** implementing
**Stage base commit:** Stage 1 — `f70ad721`

### Completed
- Stage 1: `DocumentListDTO` (q, проект, ЦФО, направление), `DocumentRepository::findByCompany` (EXISTS по операциям),
  `summarizeByCompany(companyId, dto)` → `DocumentListTotalsDTO`, `FinancialResponsibilityCenterFacade::getNamesByIds()`,
  контроллер передаёт `filters`, `totals`, `responsibilityCenterNames`, `projectDirections`, `responsibilityCenters`.

### Checks and baseline
- baseline `tests/Functional/Finance` — 125 OK
- Stage 1: unit DTO + `DocumentRepositoryListTest` + CFO facade + `tests/Functional/Finance` — OK; PHPStan по файлам — OK; cs — OK
- запросы списка (10 док.): до 6, после Stage 1 — 9 (итог, проекты, ЦФО), не зависят от числа документов

### Exact next action
- Stage 2: вёрстка `templates/document/index.html.twig` по скриншотам 01–02

### Files to inspect first on resume
- `docs/tasks/ds-pnl-documents/plan.md`, `site/templates/document/index.html.twig`, `site/assets/styles/vf-custom-classes.css`
