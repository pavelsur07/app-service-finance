# Checkpoint — marketplace-security-hardening

## Current checkpoint

**Phase:** Stage 2 (H4 — tenant-сверка в Actions)
**Status:** implementing
**Stage base commit:** 787e49cc (Stage 1 commit)
**Current Work item:** 2.1
**Owner gate:** no (Stage 1–3); yes на Stage 4

### Completed
- Stage 1 (H3) — DONE: commit `787e49cc`, Draft PR #2291, REVIEW_GREEN (3 итерации), Stage Report `stages/stage-1.md`
- Baseline: unit 1722 OK; functional Marketplace+Admin 119 OK

### Current diff / affected files
- (в работе) `ProcessOzonRealizationAction.php`, `ProcessMarketplaceRawDocumentAction.php` + integration-тесты

### Checks and baseline
- Stage 1 final: unit 1722 OK, functional Marketplace+Admin 119 OK, lint:twig OK

### Review status
- Stage 1: REVIEW_GREEN
- Stage 2: iteration 0

### Exact next action
- 2.1: tenant-сверка в `ProcessOzonRealizationAction::__invoke` (после загрузки $rawDoc)
- 2.2: tenant-сверка в `ProcessMarketplaceRawDocumentAction::__invoke` ($document vs $command->companyId)
- 2.3: integration-тесты: чужой rawDoc → исключение

### Files to inspect first on resume
- `site/src/Marketplace/Application/ProcessOzonRealizationAction.php` (:65-85)
- `site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php` (:50)
- `site/tests/Integration/Marketplace/Application/`
