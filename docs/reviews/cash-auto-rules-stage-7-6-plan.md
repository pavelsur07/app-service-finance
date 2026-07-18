# Cash auto rules — Stage 7.6 Phase 0: Cash transaction integration

## Status

- Phase: **Stage 7.6.2 implemented locally; owner review required before PR/merge**
- Scope: **Cash transaction model, create/edit/facade/import write paths, manual form, pair validation, and audit regression coverage**
- Overall risk: **HIGH** because the stage changes financial classification behavior for newly created Cash transactions
- Database migration: **not required**; Stage 7.5 already deployed the nullable `cash_transaction.responsibility_center_id` column, FK, and index
- Production writes, backfill, and historical recalculation: **forbidden**
- Next action: **review Stage 7.6.2; Stage 7.6.3 manual UI and Stage 7.6.4 imports remain separate gated stages**

## Goal

Persist one company-valid `projectDirectionId × responsibilityCenterId` pair on new Cash transactions, allow an explicit valid pair on manual edits, and preserve every existing transaction unless a user explicitly changes its classification.

Stage 7.6 does not add Cash/P&L analytics, document propagation, P&L aggregation, or a ЦФО auto-rule target.

## Approved baseline

- `PROJECT_GENERAL × CFO_GENERAL` exists for every production company.
- Stage 7.5 deployed nullable scalar `responsibility_center_id` storage on `cash_transaction` without mapping it in the Entity and without changing existing facts.
- Existing rows remain valid legacy rows with nullable classification fields; there is no backfill.
- A project may be allowed for several ЦФО, so ЦФО must never be inferred from a project name or from the first matching pair.
- Internal transfers use the system pair.
- Company modules are crossed only through documented Facade/DTO contracts.

## Inspected project patterns

- `CashTransactionService` is the shared manual and `CashFacade` creation path and owns flush/recalculation side effects.
- `CashFacade::createTransaction()` is the only supported cross-module Cash creation API and preserves import idempotency.
- `FinancialResponsibilityCenterFacade` already exposes active choices, company-scoped lookup, and allowed-pair validation.
- `ConfigureFinancialResponsibilityCenterProjectsAction` is the reference for same-company pair guards and fail-closed domain errors.
- `AuditLogSubscriber` already records Cash create/update changes; scalar `responsibilityCenterId` changes will be included automatically.
- `CashTransactionAutoRuleService` fills a project only when `CashTransaction::getProjectDirection()` is `null`.

No new framework, dependency, generic classification engine, public endpoint, or Messenger message is justified for Stage 7.6.

## Current Cash write-path inventory

| Path | Current behavior | Stage 7.6 target |
|---|---|---|
| Manual create | `CashTransactionController::new()` → `CashTransactionService::add()` | explicit allowed pair, or system pair when both IDs are absent |
| Manual edit | `CashTransactionController::edit()` → `CashTransactionService::update()` | preserve an untouched legacy/current tuple; validate every explicit classification change |
| Cross-module create | `CashFacade::createTransaction()` → `CashTransactionService::add()` | add optional ЦФО ID without breaking constructor argument order or idempotency |
| Telegram create | `CreateTelegramCashTransactionAction` → `CashFacade` | follows the same create contract; no Telegram-specific resolver |
| File import | `CashFileImportService` creates `CashTransaction` directly in batches | system pair only for newly persisted rows |
| 1C client-bank import | `ClientBank1CImportService` creates or overwrites directly | system pair for new rows; overwrite preserves the stored tuple |
| Bank API import | `BankImportService` creates directly | system pair only for new rows |
| Duplicate facade/import rows | existing row returned or skipped | never default, repair, or overwrite classification |
| Fixtures/builders/direct test entities | direct entity construction | may continue representing legacy `null` facts unless a test exercises a new write path |

`CashTransactionPersister.php_` is an inactive `.php_` artifact and is not loaded as application code. It must not be deleted or brought into scope in Stage 7.6.

## Proposed classification contract

### Entity storage

- Map the already deployed column as scalar `?string $responsibilityCenterId`, not as a Doctrine relation to a Company Entity.
- Add only a getter and setter needed by Cash writers and later module stages.
- Keep the database column nullable for legacy rows and rolling application compatibility.
- Do not add or alter a migration in Stage 7.6.

### New transaction

| Submitted project | Submitted ЦФО | Result |
|---|---|---|
| `null` | `null` | resolve and persist `PROJECT_GENERAL × CFO_GENERAL` |
| value | value | persist only when the project/ЦФО pair belongs to the command company, is allowed, and the ЦФО is active |
| value | `null` | reject; a project can belong to multiple ЦФО, so no inference is safe |
| `null` | value | reject; a ЦФО without a project is outside the approved contract |

If the required system pair is missing, creation fails closed with a domain error. Stage 7.6 must not create or repair Company master data from Cash.

### Existing transaction edit

- An unchanged stored tuple is preserved exactly, including legacy `null/null`, legacy partial data, or an archived ЦФО. Editing an unrelated field must not classify history silently.
- A changed tuple must contain both IDs and pass active-company/active-center/allowed-pair validation.
- Clearing an existing pair or submitting only one ID is rejected.
- Project and ЦФО are changed in one Doctrine flush so audit and readers never observe a deliberate partial update.
- A user may explicitly replace a legacy tuple with the system pair or another allowed pair.

### Imports and idempotency

- Defaults apply only inside the branch that constructs a new transaction.
- Preview mode may resolve a pair in memory but must not persist anything.
- Duplicate and overwrite branches preserve stored project/ЦФО values, including legacy `null` values.
- Internal transfers created by import always receive the system pair.
- Stage 7.6 does not refactor legacy import batching through `CashFacade`; that would change flush, logging, deduplication, and balance-recalculation behavior outside the requested scope.

### Company isolation and active state

- Cash must call `FinancialResponsibilityCenterFacade`; it must not inject Company repositories or managed responsibility-center Entities.
- Explicit pairs require both `findByIdAndCompany()` and `isProjectAllowed()` checks (or one equivalent facade method if the implementation is shorter).
- Archived ЦФО cannot be assigned to new or changed classification, but an unchanged existing reference remains readable and editable for unrelated fields.
- The existing restrictive FK remains the final guard that a referenced ЦФО exists.
- The known narrow race between application pair validation and concurrent removal of the allowed pair is not solved by speculative Stage 7.6 DDL. It must be reviewed again in Stage 7.10; adding a composite fact-to-pair FK requires a separate migration plan.

### Audit

- Reuse `AuditLogSubscriber`; do not introduce a second manual-audit service.
- Manual classification updates must produce one normal Cash update audit containing scalar before/after `responsibilityCenterId` and the existing project change.
- System/import creates retain the existing `CREATE` audit with a system actor (`null`) when no authenticated actor exists.
- Import duplicate/skip paths must not create classification audit records.
- Auto-rule provenance remains unchanged and outside Stage 7.6.

## Mandatory auto-rule transition decision

The current project safe-fill condition is:

```php
null === $transaction->getProjectDirection()
```

After Stage 7.6 assigns `PROJECT_GENERAL × CFO_GENERAL` to a new transaction, a project-only auto-rule no longer sees an empty project and therefore does not fill it. Changing that condition in Stage 7.6 would also be unsafe: replacing only the system project could leave an invalid project/ЦФО pair, while inferring a ЦФО is impossible when one project is allowed for several ЦФО.

### Option A — coordinated cutover (**recommended**)

1. Implement only the storage/resolver foundation first.
2. Prepare the Stage 7.9 ЦФО auto-rule contract before enabling defaults on runtime/import writers.
3. Permit replacement of the system pair only when one winning application plan supplies a complete allowed project/ЦФО pair.
4. Inventory active project-target rules and require an explicit ЦФО target where needed before import cutover.
5. Deploy writer defaulting only after preview/application tests prove there is no invalid intermediate pair.

This preserves safe-fill semantics and avoids a production interval where new imported transactions silently stop receiving project classification.

### Option B — accept a temporary project-rule pause

Deploy the Stage 7.6 system default immediately and accept that project-only auto-rules will not classify new defaulted transactions until Stage 7.9 and rule configuration are complete.

This is simpler technically but is not recommended because it changes observable rule outcomes.

### Rejected option — infer ЦФО

Do not choose the only/first/currently active ЦФО for a project. The approved model explicitly permits several ЦФО per project, and pair configuration may change over time.

No runtime writer implementation may start until the owner selects Option A or Option B.

## Proposed implementation units

### Stage 7.6.1 — Scalar mapping and Company read contract

**Risk:** MEDIUM

**Result:** Cash can represent `responsibilityCenterId`, and one small Cash resolver can obtain/validate scalar pairs through the Company facade. No production writer uses it yet.

Implementation status: DONE and accepted in production via PR #2186 / merge commit `167b24d4f5502fa7c9db6668a5ef891d66f41c1a`. The existing Stage 7.5 column is mapped, the system-pair scalar read contract and pair resolver are covered by focused integration tests, and no runtime writer has been connected.

Expected work:

- map the existing nullable Cash column;
- add a scalar DTO/facade read for the system pair if the existing methods cannot resolve it unambiguously;
- add one final resolver service with create/update validation methods;
- add focused unit/integration tests;
- update `ARCHITECTURE.md` for the added Entity/facade contract.

### Stage 7.6.2 — Core Cash create/update and cross-module command

**Risk:** HIGH

**Result:** `CashTransactionService` and `CashFacade` use the approved pair contract without changing duplicate behavior, VAT, payment matching, daily balances, snapshots, or auto-rule dispatch.

Implementation status: DONE locally. `CashTransactionDTO` and `CreateCashTransactionCommand` now accept optional scalar `responsibilityCenterId`; `CashTransactionService::add()` resolves `null/null` to the system pair or validates an explicit pair once before persist; `update()` preserves an unchanged stored pair and validates changed pairs; `CashFacade` keeps import duplicate detection before service creation. The existing manual Cash form includes the minimal scalar ЦФО field required to submit an already visible project safely. No import service, migration, history run, queue, or production operation is included.

Expected work:

- add nullable `responsibilityCenterId` to input DTOs at the end of constructor signatures;
- apply create/update resolution once in `CashTransactionService`;
- preserve the early duplicate branches;
- prove invalid/cross-company/partial pairs fail before flush;
- prove unrelated edits preserve legacy and archived tuples.

Mandatory STOP after self-review.

### Stage 7.6.3 — Existing manual Cash UI

**Risk:** HIGH

**Result:** the existing new/edit form accepts a ЦФО and displays the stored choice without adding routes or an API.

Expected work:

- add one `ChoiceType` using scalar DTOs from `FinancialResponsibilityCenterFacade`;
- list active choices; include the current archived choice only to preserve an unchanged historical tuple;
- keep server-side pair validation authoritative;
- show pair errors on the existing form;
- verify CSRF and active-company transaction guards remain unchanged.

Dynamic filtering, a new AJAX endpoint, list/report columns, and analytics are deferred. The first safe version may show all active company ЦФО and reject an incompatible pair on submit.

Mandatory STOP after self-review.

### Stage 7.6.4 — Import cutover

**Risk:** HIGH

**Result:** every newly persisted file/1C/bank transaction receives the system pair; preview, duplicate, overwrite, batching, logging, and balance behavior stay unchanged.

This unit is blocked until the mandatory auto-rule transition decision is resolved.

Mandatory STOP and controlled production acceptance after self-review.

## Expected change areas

- `site/src/Cash/Entity/Transaction/CashTransaction.php`
- `site/src/Cash/DTO/CashTransactionDTO.php`
- `site/src/Cash/Application/DTO/CreateCashTransactionCommand.php`
- one focused resolver under `site/src/Cash/Application/Service/` or the nearest existing Cash service namespace
- `site/src/Cash/Service/Transaction/CashTransactionService.php`
- `site/src/Cash/Facade/CashFacade.php`
- `site/src/Cash/Form/Transaction/CashTransactionType.php`
- `site/src/Cash/Controller/Transaction/CashTransactionController.php`
- `site/templates/transaction/_form.html.twig`
- `site/src/Cash/Service/Import/File/CashFileImportService.php`
- `site/src/Cash/Service/Import/ClientBank1CImportService.php`
- `site/src/Cash/Service/Import/Bank/BankImportService.php`
- `site/src/Company/Facade/FinancialResponsibilityCenterFacade.php`
- a Company scalar DTO/repository query only if needed for unambiguous system-pair resolution
- focused unit/integration/functional tests under existing Cash and Company suites
- `ARCHITECTURE.md` and Stage reports

The list is an inspection map, not permission to modify every file in one PR.

## Required checks

### Resolver/model

- system pair resolution for a valid company;
- fail closed when the system pair is missing or malformed;
- allowed same-company pair succeeds;
- partial, cross-company, disallowed, and archived-center pairs fail;
- Doctrine mapping validates against the already deployed Stage 7.5 schema;
- no migration diff for `cash_transaction.responsibility_center_id`.

### Core create/edit/facade

- new `null/null` transaction gets the system pair;
- explicit allowed pair is stored atomically;
- invalid pair produces no transaction and no recalculation side effects;
- unchanged legacy `null/null`, partial, and archived tuples survive unrelated edit;
- explicit edit records project and ЦФО in one audit update;
- facade duplicate result does not alter the existing classification;
- Telegram creation follows the shared contract without Telegram-specific code.

### Manual UI

- active-company ЦФО choices only;
- same-name choices remain distinct by UUID;
- cross-company IDs are rejected without IDOR disclosure;
- incompatible pair returns the form with a domain error;
- current archived ЦФО can be preserved but not newly selected;
- CSRF and current transaction company guard remain green.

### Imports

- one focused regression for each live importer;
- preview persists zero rows;
- new row receives the system pair;
- duplicate/overwrite preserves an existing custom or legacy tuple;
- internal transfer receives the system pair;
- batch counters, import logs, dedupe keys, and balance recalculation remain unchanged.

### Standard checks

- targeted PHP syntax and PHP CS Fixer;
- focused unit and integration tests;
- relevant Cash/Telegram/import functional tests;
- `make site-test-unit`;
- relevant full integration suite when write paths are enabled;
- Symfony container lint and Twig lint for the UI unit;
- `git diff --check`, complete diff/security/no-debug review.

## Must not change

- existing facts through a migration, backfill, repair, or batch update;
- Cash formulas, VAT, signs, dates, balances, payment matching, or snapshot invalidation;
- existing import dedupe, overwrite, preview, batching, logging, or cursor behavior;
- current auto-rule priority, winner, conflict, safe-fill, preview, audit, queue, or message semantics without separate owner approval;
- documents, document operations, P&L totals, P&L uniqueness, or Cash-to-document propagation (Stage 7.7);
- Cash/P&L analytics or report contracts (Stage 7.8);
- auto-rule ЦФО target (Stage 7.9 unless explicitly resequenced);
- auth/RBAC, routes, Messenger configuration, workers, cron, dependencies, or production configuration;
- inactive `.php_` artifacts or unrelated legacy direct-repository patterns;
- project/CFO inference from names, ordering, or a temporary single match.

## Deployment and rollback

- Stage 7.6 requires no database migration.
- The Entity mapping is backward-compatible because the nullable column already exists in production.
- Application rollback leaves any newly written scalar IDs in place; old Stage 7.5 application code ignores the column.
- No historical recalculation is required or permitted.
- Before any writer cutover, run a read-only production check for the exact system-pair invariant and inventory active project-target auto-rules.
- Production acceptance must verify new writes only and must not run auto-rule history or P&L rebuilds.

## Owner decisions required

1. Approve the proposed create/edit pair matrix.
2. Confirm that unchanged legacy/archived tuples remain untouched on unrelated edits.
3. Select the auto-rule transition:
   - **Option A — coordinated cutover (recommended)**; or
   - Option B — temporary pause of project-only classification for new defaulted transactions.
4. Approve Stage 7.6.1 as the first implementation unit; later HIGH-risk units retain separate review gates.

## Phase 0 self-review

- [x] Scope limited to Stage 7.6 planning and documentation
- [x] `ARCHITECTURE.md`, `PATTERNS.md`, `CLAUDE.md`, Stage 7 contract, and Stage 7.5 baseline inspected
- [x] Manual, facade, Telegram, file, 1C, and bank write paths traced
- [x] Company isolation and audit behavior inspected
- [x] Existing auto-rule interaction identified before implementation
- [x] No PHP, Twig, migration, configuration, test, or production changes made
- [x] No speculative dependency, endpoint, abstraction hierarchy, or broad refactor proposed
