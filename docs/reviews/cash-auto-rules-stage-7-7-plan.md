# Cash auto rules — Stage 7.7 Phase 0: Document and P&L integration

## Current status

- Phase: **Stage 7.7.2 implemented locally; owner accepted external review max-turns blocker**
- Branch: `agent/cash-stage7-7-2-document-writers`
- Production actions: **none**
- Code changes: **Stage 7.7.2 — Document writers and Cash → Document propagation; not committed yet**

Stage 7.7 starts after:

- Stage 7.5 deployed nullable `responsibility_center_id` columns on `documents`, `document_operations`, and `pl_daily_totals`;
- Stage 7.6.2/7.6.4 made new Cash writers persist a validated Project × ЦФО pair;
- Stage 7.9 configured auto-rules so new defaulted Cash rows can receive `CFO_GENERAL` without changing historical rows.

## Result required by Stage 7.7

Documents and P&L facts must preserve the same validated Project × ЦФО pair that exists on the originating financial fact.

Observable target behavior:

- `Document` and `DocumentOperation` can store a nullable scalar ЦФО id.
- Cash → Document conversion copies the Cash transaction ЦФО to the created document and operation.
- Manual Finance documents can submit a Project × ЦФО pair and reject invalid or cross-company pairs.
- New `pl_daily_totals` written from documents retain the Project × ЦФО dimension.
- Existing historical `NULL` ЦФО values are not backfilled and remain the explicit future `Не распределено` bucket.

## Non-goals

- No historical rebuild.
- No production mutation.
- No import/manual run on production.
- No Stage 7.8 analytics UI/report screens.
- No source/shop dimension in `pl_daily_totals`.
- No source-scoped P&L rebuild.
- No inference of ЦФО from project when several ЦФО are allowed for the same project.
- No dynamic AJAX Project→ЦФО filtering unless a later UI stage explicitly needs it.
- No broad Finance controller refactor.

## Existing code paths inspected

### Finance documents

- `site/src/Finance/Entity/Document.php`
- `site/src/Finance/Entity/DocumentOperation.php`
- `site/src/Finance/Form/DocumentType.php`
- `site/src/Finance/Form/DocumentOperationType.php`
- `site/src/Finance/Controller/DocumentController.php`
- `site/src/Finance/Application/CreatePLDocumentAction.php`
- `site/src/Finance/Facade/FinanceFacade.php`

Pattern: document-level project is copied to operations when an operation project is empty. Stage 7.7 should mirror this for ЦФО: operation ЦФО overrides document ЦФО; empty operation ЦФО falls back to document ЦФО before aggregation.

### Cash → Document

- `site/src/Cash/Application/CreateDocumentFromTransactionAction.php`
- `site/src/Cash/Application/DTO/CreateDocumentCommand.php`
- `site/src/Cash/Service/Transaction/CashTransactionToDocumentService.php`
- `site/src/Finance/Facade/FinanceFacade.php::createDocumentFromCashTransaction()`

Pattern: Cash conversion already copies amount, counterparty, category, and project. Stage 7.7 should copy the scalar Cash `responsibilityCenterId` in the same place, with the same company/pair validation used by Cash.

### P&L register

- `site/src/Finance/Application/Service/PLRegisterUpdater.php`
- `site/src/Finance/Repository/PLDailyTotalRepository.php`
- `site/src/Finance/Entity/PLDailyTotal.php`
- `site/src/Finance/Facts/PLDailyTotalFactsProvider.php`
- `site/src/Finance/Report/PlReportCalculator.php`

Current aggregation key is:

```text
company_id × pl_category_id × date × project_direction_id
```

Stage 7.7 must extend only the writer/storage key to:

```text
company_id × pl_category_id × date × project_direction_id × responsibility_center_id
```

The read/report split by ЦФО belongs to Stage 7.8.

### Marketplace / Ingestion P&L writers

- `site/src/Marketplace/Application/CloseMonthStageAction.php`
- `site/src/Marketplace/DTO/PLEntryDTO.php`
- `site/src/Finance/Application/Action/RebuildPnlPeriodAction.php`

These are separate writers. The minimal Stage 7.7 path should not silently invent ЦФО for marketplace/ingestion documents. They need explicit source-level rules or a separate default-to-system decision.

Best-practice decision for Stage 7.7:

- Document-created P&L totals use the document/operation ЦФО when present.
- Marketplace-created PL documents may accept an optional scalar `responsibilityCenterId` only when a caller provides one.
- Ingestion rebuild remains unchanged until a separate source-linking/ЦФО mapping decision is approved.

## Stage split

### Stage 7.7.1 — Finance scalar mapping and pair validation

**Risk:** HIGH-LOCAL

Goal:

- Map existing nullable DB columns on `Document`, `DocumentOperation`, and `PLDailyTotal`.
- Add scalar getters/setters only.
- Add or reuse a Finance-side validation service that accepts `companyId`, `projectDirectionId`, `responsibilityCenterId`.
- Reuse `FinancialResponsibilityCenterFacade` / existing Company pair read contract. Do not duplicate Company ownership rules in Finance SQL.

Definition of Done:

- Entity mapping validates against the already deployed Stage 7.5 schema.
- Same-company active allowed pair passes.
- Cross-company, archived, missing, and disallowed pairs fail closed.
- `NULL` ЦФО remains allowed for legacy documents and legacy totals.
- No writer behavior changes yet.

Expected files:

- `site/src/Finance/Entity/Document.php`
- `site/src/Finance/Entity/DocumentOperation.php`
- `site/src/Finance/Entity/PLDailyTotal.php`
- small validator/service under `site/src/Finance/Application/Service/`
- focused Finance/Company tests
- `ARCHITECTURE.md`

Required checks:

- targeted Finance integration tests;
- Doctrine schema validate with `--skip-sync`;
- targeted PHP CS Fixer;
- `make site-test-unit`;
- relevant Finance integration subset.

### Stage 7.7.2 — Document writers and Cash conversion propagation

**Risk:** HIGH-LOCAL

Goal:

- Extend `CreatePLDocumentCommand` and `CreatePLDocumentOperationCommand` with nullable scalar `responsibilityCenterId`.
- Copy Cash transaction ЦФО in:
  - `FinanceFacade::createDocumentFromCashTransaction()`;
  - `CashTransactionToDocumentService`.
- Add manual Finance form fields using scalar choices from Company facade; keep server-side pair validation authoritative.
- On manual document submit/copy:
  - document ЦФО is copied to operations with empty operation ЦФО;
  - operation-level ЦФО overrides document-level ЦФО;
  - invalid Project × ЦФО pair is rejected with a form/action error.

Definition of Done:

- [x] New Cash-created documents copy Project × ЦФО to document and operation.
- [x] Existing documents with `NULL` ЦФО still load/save.
- [x] Manual form can preserve a historical archived/current ЦФО value if unchanged, matching the Cash form pattern.
- [x] Copy duplicates ЦФО values.
- [x] No PL aggregation key change yet; register may still collapse by project until Stage 7.7.3.

Expected files:

- `site/src/Cash/Application/DTO/CreateDocumentCommand.php`
- `site/src/Cash/Service/Transaction/CashTransactionToDocumentService.php`
- `site/src/Finance/Facade/FinanceFacade.php`
- `site/src/Finance/Application/CreatePLDocumentAction.php`
- `site/src/Finance/Application/Command/*`
- `site/src/Finance/Form/*`
- `site/src/Finance/Controller/DocumentController.php`
- `site/templates/document/*`
- focused Cash/Finance tests

Required checks:

- Cash document creation integration tests;
- Finance document functional tests;
- targeted Twig lint if templates change;
- relevant Finance integration subset;
- `make site-test-unit`.

### Stage 7.7.3 — P&L daily totals Project × ЦФО aggregation key

**Risk:** HIGH-LOCAL; production acceptance is HIGH-EXTERNAL

Goal:

- Change new `PLRegisterUpdater` writes to aggregate by Project × ЦФО.
- Extend `PLDailyTotalRepository::upsert()` with nullable `responsibilityCenterId`.
- Add the new conflict targets without breaking category deletion with nullable `pl_category_id`.
- Keep old report totals unchanged when no ЦФО filter is applied: totals across ЦФО buckets must sum to the same project/category/date values.

Required database policy:

- Keep PostgreSQL default distinct-`NULL` semantics for `pl_category_id`.
- Do not use `NULLS NOT DISTINCT` on `pl_category_id`; Stage 7.5 proved it breaks `ON DELETE SET NULL`.
- Use two additive uniqueness contracts so `ON CONFLICT` remains deterministic:
  - populated category rows: unique index on

```text
company_id × pl_category_id × date × project_direction_id × responsibility_center_id
```

  - uncategorized rows: partial unique index on

```text
company_id × date × project_direction_id × responsibility_center_id
WHERE pl_category_id IS NULL
```

- `PLDailyTotalRepository::upsert()` must use the matching conflict target for the category-present and category-null paths. A single standard unique index is not enough for `pl_category_id IS NULL`, because PostgreSQL treats `NULL` values as distinct and would insert duplicates instead of triggering `ON CONFLICT`.

Concurrency policy:

- Any duplicate guard for the new key must lock `pl_daily_totals` before checking, or be avoided in favor of a forward-only migration plus a controlled writer switch.
- Existing writer code must be compatible with the deployed schema during rolling deploy.

Definition of Done:

- Two document operations with the same date/category/project but different ЦФО persist as two `pl_daily_totals` rows.
- Same key and same ЦФО upserts accumulate/replace exactly as before.
- Uncategorized totals also upsert instead of duplicating rows for the same company/date/project/ЦФО.
- Category deletion still succeeds when multiple category rows collapse to `NULL`.
- Legacy `responsibility_center_id IS NULL` rows remain valid.
- No historical rebuild is run by the stage.

Expected files:

- new Doctrine migration;
- `site/src/Finance/Application/Service/PLRegisterUpdater.php`
- `site/src/Finance/Repository/PLDailyTotalRepository.php`
- `site/src/Finance/Entity/PLDailyTotal.php`
- Finance repository/register tests;
- `ARCHITECTURE.md`.

Required checks:

- generated SQL review;
- empty-database migration test;
- local `app_test` migration only;
- Finance register integration tests;
- category deletion regression;
- full relevant Finance integration subset;
- `make site-test-unit`;
- container lint.

### Stage 7.7.4 — Marketplace/PLDocument caller contract only

**Risk:** HIGH-LOCAL

Goal:

- Decide whether marketplace month-close `PLEntryDTO` and `FinanceFacade::createPLDocument()` accept optional ЦФО.
- If accepted, propagate only explicitly provided ЦФО. Do not infer.
- Do not change Ingestion rebuild source-scoped behavior.

Default best-practice option:

- Add optional `responsibilityCenterId` to PL document DTOs/contracts, default `NULL`.
- Existing marketplace callers keep `NULL`.
- Actual marketplace ЦФО mapping is deferred until marketplace connection/source rules are defined.

Definition of Done:

- Existing marketplace month-close tests stay green.
- New optional field does not change generated totals when omitted.
- No production rebuild or source reprocessing.

## Owner decisions before implementation

Phase 0 found three material choices that affect financial semantics. They should be explicitly accepted before code implementation.

### Decision A — Document fallback rule

Recommended:

```text
Operation Project/ЦФО overrides Document Project/ЦФО.
If operation Project or ЦФО is empty, inherit the missing value from the document.
If after inheritance an existing row still has an incomplete pair and Project/ЦФО were not changed, keep legacy NULL behavior for that unchanged row.
For new rows, or when Project/ЦФО are changed, a non-system project without ЦФО is invalid and must fail closed.
New rows with empty Project/ЦФО should receive PROJECT_GENERAL × CFO_GENERAL through the writer path when the stage wires writers.
If both Project and ЦФО are present, validate active same-company allowed pair.
```

Reason:

- Matches current project fallback behavior.
- Preserves legacy incomplete documents only when they are not reclassified.
- Prevents new or edited manual documents from bypassing the Stage 7 rule: non-system project without ЦФО is invalid.
- Avoids inventing ЦФО for historical data while still making new writer behavior deterministic.

### Decision B — P&L uniqueness / nullable category policy

Recommended:

```text
Extend the P&L upsert key with responsibility_center_id, but keep pl_category_id nullable with PostgreSQL default distinct-NULL semantics.
Do not use NULLS NOT DISTINCT for pl_category_id.
Use a category-present unique index and a separate partial unique index/upsert path for pl_category_id IS NULL.
Do not backfill existing rows.
```

Reason:

- Preserves category deletion behavior verified in Stage 7.5.
- Allows future `Не распределено` bucket for legacy NULL ЦФО.
- Keeps deployment expand/switch controlled.

### Decision C — Marketplace/Ingestion scope

Recommended:

```text
Stage 7.7 handles Finance Document writers and PLRegister totals.
Marketplace PLDocument callers may carry an optional ЦФО when explicitly provided.
Ingestion RebuildPnlPeriodAction remains unchanged until source-linking/ЦФО mapping is separately approved.
```

Reason:

- Avoids hidden financial mapping assumptions.
- Keeps Stage 7.7 reviewable.
- Leaves source/shop semantics to the existing Finance source-linking follow-up.

## Baseline and checks

Phase 0 baseline:

- `pwd` / `git status --short` / `git branch --show-current` — clean `master` before branch creation.
- Documentation-only inspection; no runtime baseline needed before code changes.

Implementation checks by stage:

- Stage 7.7.1: mapping validation, pair validation tests, targeted Finance integration.
- Stage 7.7.2: Cash→Document propagation tests, manual Document functional tests, Twig lint if templates change.
- Stage 7.7.3: migration dry-run, empty-db migration, local `app_test` migration, PLRegister repository/action tests, category deletion regression.
- Stage 7.7.4: marketplace month-close regression tests.

Production acceptance checks after merge/deploy:

- read-only container and queue status;
- read-only SQL for new indexes/columns;
- aggregate-only SQL proving no invalid/cross-company responsibility-center facts;
- no backfill/rebuild/import/manual document mutation unless separately approved.

## Files and areas not to change

- Do not change Cash auto-rule matcher/preview/application in Stage 7.7.
- Do not change Stage 7.8 analytics reports/screens.
- Do not modify production wrappers or deployment config.
- Do not process queues.
- Do not run production P&L rebuild.
- Do not touch `site/src/Cash/Service/Import/File/CashTransactionPersister.php_`; it is inactive.

## Phase 0 conclusion

Stage 7.7 is implementable, but only after the three owner decisions above are accepted. The safest first implementation unit is **Stage 7.7.1 — Finance scalar mapping and pair validation**.

Recommended owner response:

```text
Подтверждаю Stage 7.7 decisions A/B/C и разрешаю Stage 7.7.1.
```
