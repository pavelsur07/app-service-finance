# Source write-path inventory — Stage2 preflight

Status: guards implemented; targeted regression checks passed. Fresh Stage2 review and external review closure pending.
Lock order: company → Cash → Finance → SettlementBook → sorted item IDs.
Read-only inspection by inspect_settlement_sources; all paths under site/src/.

## Material Cash paths requiring guards

| Path | Change / requirement |
|---|---|
| Cash/Application/UpdateCashTransactionAction.php | account, amount, currency, date, direction, counterparty; wrap whole Action in tx, lock fresh source before mutation; description-only allowed |
| Cash/Application/DeleteCashTransactionAction.php | currently bare flush; tx + source lock + synchronous event before markDeleted |
| Cash/Application/BulkDeleteCashTransactionsAction.php | stable sorted fixed target set; guard every row before any mutation; one atomic transaction |
| Cash/Application/RestoreCashTransactionAction.php | source lock + tx; no recreation of Settlement effects |
| Cash/Application/AddCashTransactionAction.php | existing import match updates counterparty, sometimes clears it; guard actual counterparty change |
| Cash/Service/Import/ClientBank1CImportService.php and Cash/Application/ImportBank1CStatementAction.php | overwrite updates existing counterparty; preflight material targets before per-row commits, or enclosing atomic tx |
| Cash/Command/SoftDeleteCompanyTransactionsCommand.php | raw SQL delete/restore; lock and guard fixed selected ID set; update only that same set |

## Material Finance paths requiring guards

| Path | Change / requirement |
|---|---|
| Finance/Application/UpdateManualDocumentAction.php | form mutated managed Entity before Action; compare stored header/operation snapshot; do not refresh edited entity |
| Finance/Application/SoftDeleteDocumentAction.php | tx already exists; Cash first, Finance row lock/fresh snapshot/event before deletion |
| Finance/Application/RestoreDocumentAction.php | same locks and event before restore |
| Finance/Application/DeletePLDocumentAction.php | accepts any company document, including eligible manual ones; guard hard-delete despite normal marketplace callers |
| Finance/Command/RepairPlTree19621cffCommand.php | raw UPDATE document_operations.category_id includes eligible manual docs; lock owning Cash/Finance IDs before event and SQL |

## Nonmaterial / ineligible paths and evidence to pin with tests

- CashTransactionAutoRuleService: assigns counterparty only if null; eligible linked Cash already has one. Callers use transactional source locks; defensive source event is possible.
- CashTransferLifecycleAction: transfer legs have isTransfer=true and cannot be included.
- CashFileImportService and ImportBankStatementDayAction: create new sources, skip existing IDs.
- ApplyCashFileDescriptionsAction: description/override metadata only, already uses sorted locks and chunk transactions.
- SaveCashTransactionSplitsAction, split synchronization/backfill and AI category actions: category/splits only; exclude these from settlement fingerprint.
- Cash→Finance creation paths: create new documents; Cash allocatedAmount/violation metadata excluded from settlement fingerprint.
- RecalculateAllocatedAmountsAction: legacy allocation amount only.
- Finance creation/copy: new document IDs; no existing source mutation.
- LoanScheduleToDocumentService: new LOANS documents outside eligible type allowlist.
- FinanceDocumentResponsibilityCenterNormalizer edits persist through guarded manual update Action.
- CashTransactionsChanged and Doctrine postPersist/postUpdate subscribers run after mutation, unsuitable for guard rejection.

## Snapshot/contract findings

Cash repository existing ForUpdate getter does not refresh identity-map entities: use locked DBAL scalar projection or explicit safe refresh.
Finance DocumentRepository::lockStoredRegisterState locks header and reads operation state; extend/reuse it without discarding in-memory form changes.
For Finance linked to Cash: resolve Cash ID, lock Cash before Finance, reread Finance and verify association.
Cash fingerprint: company, counterparty, amount/currency, date, direction, deleted, transfer, account; exclude description/category/splits/legacy allocations.
Finance fingerprint: company, counterparty, date, deleted, status, type, source, Cash ID, sorted operation material state; exclude comments/description/number.
Finance DTO must include nullable source or Facade must validate source=null; do not infer it from absent fields.
Never use Finance Document::getTotalAmount() (float); settlement full amount is explicitly supplied as Money.

## Guard coverage and evidence

All material ORM paths dispatch source-owned synchronous events via CashSettlementSourceLifecycle / FinanceSettlementSourceLifecycle after row lock, before database writes. SettlementSourceMutationSubscriber rejects an active link; SourceExceptionListener returns business HTTP422.

| Material path | Guard / evidence |
|---|---|
| Cash update | source lock + Money/material field comparison; amount edit rejected, description allowed (SettlementSourceMutationGuardTest) |
| Cash delete / bulk | sorted fixed source locks, guards before mutation in tx; linked delete rejected, whole bulk unchanged (SourceMutationGuardTest) |
| Cash restore | source lock + guard; cancellation→delete→restore does not recreate settlement effects (RegisterCashSettlementTest) |
| Cash existing import match | locked refresh before comparing counterparty; linked update rejected (SourceMutationGuardTest) |
| 1C overwrite | company share lock; Action owns account session lock until commit/rollback; only real file overwrite targets prelocked ascending, concurrent new existing targets rejected; guard before counterparty assignment, dedupe hash set after refresh; batch rollback and retained failure log, allowed overwrite hash repair, commit-window mutex regression (SourceMutationGuardTest); legacy AccountLockTest/IdempotencyTest/DescriptionOverrideTest |
| Cash SQL company command | company and selected IDs locked, per-source events before fixed-ID UPDATE, recalc same tx; active source rejected via real CommandTester, command regression set |
| Finance edited document | one canonical stored/in-memory fingerprint, no refresh of edited form; operation edit rejected, comment allowed (SourceMutationGuardTest) |
| Finance soft/hard deletion | Cash-before-Finance snapshot lock + event, hard deletion date read after refresh; active source both deletions rejected, fresh-day PL regression (SourceMutationGuardTest / DocumentSoftDeleteActionTest) |
| Finance restore | source snapshot lock + event before restore; normal restore/allocation/register regression set DocumentSoftDeleteActionTest |
| Finance raw repair | all affected Cash IDs before sorted Finance IDs, lifecycle guard before SQL, operation WHERE includes expected document ID; real repair of linked eligible manual doc rejected (RepairPlTree19621cffCommandTest) |

Concurrency: SettlementSourceLockOrderTest uses independent PG sessions/child PHP process (not outer DAMA rollback) to prove ascending locks, and mutation waits for registration then sees newly committed active link. DESC mutation made the lock-order regression red; ASC green.

Account lock ownership: standalone service may own its lease only outside a caller transaction. An atomic caller must own the lease explicitly; production import Action retains it beyond service return until actual outer commit. Two-connection regression proved original release window red, fixed green.

A missing/empty active link never auto-reactivates when a source is restored. Company/source scope is present in snapshots and source-link checks; source fields are never read through stale ORM identity-map snapshots.

External review fixes: SourceInUseException is a DomainException so current forms and
flash/redirect survive; JSON listener is API-only, import confirm catches business
DomainException. Actual BrowserKit requests prove edit form preserved (HTML422) and
bulk delete redirects to list with danger flash. Overwrite preflight computes file
external IDs using existing hash/occurrence rules and locks only matching active
sources in sorted ID order; a two-session NOWAIT probe confirms unrelated account
history remains unlocked.
