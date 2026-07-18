# Cash auto rules — Stage 7.6.4 Phase 0: import cutover

- Date: 2026-07-18
- Scope: Cash import writers only
- Status: **PREPARED; no code changes in this Phase 0**
- Risk: **HIGH**
- Next action: **STOP; owner approval required before implementation**

## Context

Stage 7.6.2 is merged and deployed via PR #2191 / merge commit `6db658f4cbb48ff070f0f58b44a3947e04875136`.

It connected manual/core Cash create-update paths to the approved Project×ЦФО pair resolver. The minimal manual Cash form ЦФО field also shipped in #2191 because the existing form already allowed selecting a project, and keeping a project-only submit path would have been a regression.

Stage 7.6.4 must now cover only import-created transactions:

- file import;
- 1C client-bank import;
- bank provider import.

No history backfill, no transaction recalculation, no schema change, no queue/routing change, and no production import run are included in this stage.

## Current implementation

The three import writers create `CashTransaction` directly:

- `site/src/Cash/Service/Import/File/CashFileImportService.php`
- `site/src/Cash/Service/Import/ClientBank1CImportService.php`
- `site/src/Cash/Service/Import/Bank/BankImportService.php`

They intentionally do not use `CashFacade`/`CashTransactionService`, because those paths have different flush, duplicate, VAT, payment-plan, logging, and balance semantics.

Stage 7.6.4 should keep that structure.

## Best-practice decision

Use the smallest safe change:

1. Inject the existing `CashTransactionResponsibilityCenterResolver` into each import writer.
2. Resolve the system pair once per import context/company, not per row, unless the company changes inside the method.
3. Apply the returned pair to newly created `CashTransaction` before `persist()`:
   - `setProjectDirection($entityManager->getReference(ProjectDirection::class, $pair->projectDirectionId))`
   - `setResponsibilityCenterId($pair->responsibilityCenterId)`
4. Do not touch duplicate detection, overwrite behavior, preview behavior, batching, logging counters, balance recalculation, cursors, or import source/external id logic.

Rejected alternatives:

- Re-route imports through `CashFacade`: too broad; it would change importer behavior beyond the ЦФО default.
- Add a new generic import transaction factory: unnecessary abstraction for three call sites.
- Backfill existing imported transactions: out of scope and requires separate production approval.

## Stage 7.6.4 implementation plan

### 1. File import

Add resolver dependency to `CashFileImportService`.

At the start of `readAndPersist()` after resolving `$companyId`, resolve `PROJECT_GENERAL × CFO_GENERAL` once for the job company.

For every new persisted transaction, set the resolved project and ЦФО before `persist()`.

Keep current behavior unchanged:

- row normalization;
- dedupe hash;
- counterparty get-or-create;
- batch size and `flushBatch()`;
- import log counters;
- balance recalculation range.

### 2. 1C client-bank import

Add resolver dependency to `ClientBank1CImportService`.

Resolve the system pair once after `$companyId` is known.

Apply the pair only when `null === $transaction` and a new transaction is created.

Do not apply the pair on overwrite of an existing transaction. Overwrite currently updates description/raw/counterparty only; changing existing Project×ЦФО during overwrite would be an unintended mutation.

Keep preview read-only. In preview mode, the method may instantiate a transient transaction object, but it must not persist or flush a Project×ЦФО change.

### 3. Bank provider import

Add resolver dependency to `BankImportService`.

Resolve the system pair once per company import before iterating accounts/dates.

Apply the pair to each newly created transaction before `persist()`.

Keep current behavior unchanged:

- provider calls;
- account matching;
- cursor advance;
- duplicate skip;
- per-day flush;
- import log finish payload.

### 4. Tests

Update or add the smallest focused coverage:

- File import unit/integration coverage: newly persisted transaction has system project and ЦФО.
- 1C import integration coverage: new import receives system pair; overwrite preserves existing pair.
- Bank import coverage: provider-created transaction receives system pair.

Fixture rule: tests that manually create companies must also create the system `ProjectDirection`, `FinancialResponsibilityCenter`, and `FinancialResponsibilityCenterProject`, matching the pattern already used in Stage 7.6.2 tests.

### 5. Documentation

Update:

- `ARCHITECTURE.md` if implementation adds or clarifies import writer behavior;
- `docs/reviews/cash-auto-rules-stage-7-6-4-report.md` after implementation self-review.

## Required checks

Minimum targeted checks after implementation:

- PHP lint for touched PHP files;
- targeted import tests:
  - `site/tests/Unit/Cash/Service/Import/File/CashFileImportServiceTest.php`
  - `site/tests/Integration/Cash/Service/Import/IdempotencyTest.php`
  - `site/tests/Integration/Cash/Service/Import/InternalTransferTest.php`
  - any added Bank import test;
- focused Cash integration set touched by Stage 7.6.2 if constructor signatures affect services;
- `make site-test-unit`;
- `git diff --check`.

Full integration suite is recommended before PR because this stage touches import persistence.

## Must not change

- No migration.
- No production write/import run.
- No history backfill.
- No recalculation command.
- No Messenger config, worker, transport, cron, or queue routing changes.
- No import source/external id semantics.
- No duplicate/overwrite policy change.
- No auto-rule planner/application behavior change.
- No new routes/API.
- No dynamic UI filtering.

## Risks / reviewer focus

- If a company is missing the system pair, imports should fail closed before persisting a partial transaction.
- 1C overwrite must not mutate existing Project×ЦФО.
- Preview mode must remain read-only.
- Resolver lookup should not introduce per-row N+1 in import loops.
- Batch flush/cursor/log counters must remain unchanged.

## Phase 0 self-review

- [x] Scope limited to Stage 7.6.4 planning
- [x] Existing import writer paths inspected
- [x] Existing Stage 7.6.2 resolver contract reused
- [x] No code, migration, production, queue, or external API change
- [x] Tests/checks identified
- [x] STOP gate recorded before HIGH-risk implementation
