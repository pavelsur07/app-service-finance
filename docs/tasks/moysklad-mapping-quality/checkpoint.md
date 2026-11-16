## Current checkpoint

**Phase:** Stage 2 / Work item 2.1
**Status:** implementing
**Stage base commit:** 7423a1562

### Completed
- Work item 1.1: parser RED (2 failures) → GREEN (16 tests/46 assertions), nullable barcode snapshots/entities и повтор импорта.
- Work item 1.2: scoped batch Facade и настройки, миграция применена к isolated test DB.
- Прочитан утверждённый план; создана свежая ветка/worktree от актуального master.
- Технический план и приёмка записаны; изучены preview/policy/writer/import/facade.
- Официальный формат штрихкодов проверен: JSON list объектов ean8/ean13/code128/gtin/upc со строковыми значениями.

### Checks and baseline
- unit MoySklad — OK (222 tests, 727 assertions).
- migrations baseline — OK, 320 migrations; additive Stage1 migration — OK.
- module: 414 tests/1616 assertions; 2 existing concurrency tests timed out while PHPStan/fixer ran concurrently; diagnostics pending.
- focused PHPStan: 9 test typing errors identified and fixed; corrected run OK no errors.
- Targeted storage/import: OK (13 tests, 89 assertions).
- Ruling: confirmations/diagnosis storage introduced in consuming Stages 2/3, avoiding unused tables in Stage 1; no change to final scope.

### Review status
- internal: pending; external: pending.

### Exact next action
- RED tests общей policy: код, алиас WB, штрихкоды, комплектация; затем preview/writer/confirmations.

### Files to inspect first on resume
- TASK.md, plan.md; CatalogPageParser, ProductSnapshot, VariantSnapshot; ChangeListingMappingAction, AutomaticMappingPreviewQuery.
