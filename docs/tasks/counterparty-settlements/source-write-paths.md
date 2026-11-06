# Source write-path inventory — Stage2 preflight

Status: inspected, guards not yet implemented. No path below is marked covered.
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

Stage2 closure requires concrete guard locations and test evidence for every material row; this inventory alone does not satisfy acceptance.
