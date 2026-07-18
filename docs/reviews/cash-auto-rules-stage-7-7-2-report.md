# Cash auto rules — Stage 7.7.2 Document writers and Cash → Document propagation

## Current checkpoint

**Phase:** Stage 7.7.2  
**Status:** owner accepted external Claude Code review turn-limit blocker; ready for commit/push/Draft PR

### Completed

- Cash → Document propagation now copies `responsibilityCenterId` to `Document` and `DocumentOperation`.
- `CreatePLDocumentCommand` and `CreatePLDocumentOperationCommand` accept nullable scalar `responsibilityCenterId`.
- Manual Finance document create/copy/edit uses scalar ЦФО choices from Company facade.
- Server-side normalizer handles:
  - document-level pair inheritance to operations;
  - operation-level pair override;
  - default empty manual rows to `PROJECT_GENERAL × CFO_GENERAL`;
  - fail-closed invalid Project × ЦФО pairs;
  - unchanged legacy incomplete pairs;
  - unchanged archived/current pairs.
- Copy duplicates document and operation ЦФО.
- Root form errors are rendered in manual document templates.
- `ARCHITECTURE.md` and Stage 7.7 plan updated.

### Current diff / affected files

- `ARCHITECTURE.md`
- `docs/reviews/cash-auto-rules-stage-7-7-plan.md`
- `site/src/Cash/Application/CreateDocumentFromTransactionAction.php`
- `site/src/Cash/Application/DTO/CreateDocumentCommand.php`
- `site/src/Cash/Service/Transaction/CashTransactionToDocumentService.php`
- `site/src/Finance/Application/Command/CreatePLDocumentCommand.php`
- `site/src/Finance/Application/Command/CreatePLDocumentOperationCommand.php`
- `site/src/Finance/Application/CreatePLDocumentAction.php`
- `site/src/Finance/Application/Service/FinanceDocumentResponsibilityCenterNormalizer.php`
- `site/src/Finance/Controller/DocumentController.php`
- `site/src/Finance/Facade/FinanceFacade.php`
- `site/src/Finance/Form/DocumentOperationType.php`
- `site/src/Finance/Form/DocumentType.php`
- `site/templates/document/_operation_form.html.twig`
- `site/templates/document/edit.html.twig`
- `site/templates/document/new.html.twig`
- `site/tests/Functional/Finance/Controller/DocumentCopyControllerTest.php`
- `site/tests/Functional/Finance/Controller/DocumentResponsibilityCenterControllerTest.php`
- `site/tests/Integration/Cash/Application/CreateDocumentFromTransactionActionTest.php`
- `site/tests/Integration/Cash/Service/Transaction/CashTransactionToDocumentServiceTest.php`
- `site/tests/Integration/Finance/CreatePLDocumentActionTest.php`

### Checks and baseline

- Baseline before implementation:
  - `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Cash/Application/CreateDocumentFromTransactionActionTest.php tests/Integration/Finance/CreatePLDocumentActionTest.php tests/Functional/Finance/Controller/DocumentCopyControllerTest.php` — passed, 11 tests / 67 assertions.
- Final local checks:
  - PHP lint for touched PHP files — passed.
  - Targeted Stage 7.7.2 PHPUnit package — passed, 18 tests / 109 assertions.
  - `docker compose run --rm -T site-php-cli php bin/console lint:twig templates/document --env=test` — passed.
  - `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance` — passed, 33 tests / 163 assertions.
  - `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Cash/Application tests/Integration/Cash/Service/Transaction` — passed, 23 tests / 99 assertions.
  - `docker compose run --rm -T site-php-cli php bin/phpunit tests/Functional/Finance/Controller tests/Functional/Finance/PLCategoryNewControllerTest.php tests/Functional/Finance/PLCategoryEditControllerTest.php` — passed, 11 tests / 107 assertions.
  - Scoped PHP CS Fixer dry-run on touched files — passed.
  - `make site-test-unit` — passed, 1516 tests / 8912 assertions.
  - `make site-test-integration` — passed, 737 tests / 3588 assertions.
- Known unrelated check:
  - `make site-cs-check` — failed on 602 pre-existing repository-wide style issues outside Stage 7.7.2; scoped touched-file CS check is green.

### Review status

- Internal automatic review: completed.
  - BLOCKER: none.
  - IMPORTANT: none.
  - MINOR fixed: compare system project company by ID instead of Doctrine object identity.
- External Claude Code review:
  - Iteration 1: failed with `Reached max turns (20)`.
  - Iteration 2 with narrower prompt: failed with `Reached max turns (20)`.
  - Iteration 3 with owner-approved `--max-turns 40`: failed with `Reached max turns (40)`.
  - No `REVIEW_GREEN` received.
  - Owner explicitly approved accepting Stage 7.7.2 without external `REVIEW_GREEN` because of the repeated max-turns blocker.

### Exact next action

- Commit only Stage 7.7.2 files, push branch, create Draft PR.

### Files to inspect first on resume

- `site/src/Finance/Application/Service/FinanceDocumentResponsibilityCenterNormalizer.php`
- `site/src/Finance/Controller/DocumentController.php`
- `site/src/Finance/Form/DocumentType.php`
- `site/src/Finance/Form/DocumentOperationType.php`
- `site/src/Finance/Facade/FinanceFacade.php`
- `site/src/Finance/Application/CreatePLDocumentAction.php`
- `site/src/Cash/Application/CreateDocumentFromTransactionAction.php`
- `site/src/Cash/Service/Transaction/CashTransactionToDocumentService.php`
