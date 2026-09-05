# Checkpoint — pl-category-delete-fk-violation

### Текущее состояние
- Ветка: `fix/pl-category-delete-fk-violation`
- `stage_base_commit`: `89b503d6`
- Stage 1 — DONE, см. `stages/stage-1.md`. REVIEW_GREEN на 2-й итерации.

### Exact next action
- Закоммитить, открыть PR, дождаться зелёного CI, смёржить, удалить ветку.

### Files to inspect first on resume
- `site/src/Finance/Controller/PLCategoryController.php`
- `site/src/Finance/Application/DeletePLCategoryAction.php`
- `site/src/Finance/Exception/PLCategoryInUseException.php`
- `site/src/Finance/Repository/DocumentOperationRepository.php`
- `site/tests/Functional/Finance/PLCategoryEditControllerTest.php`
