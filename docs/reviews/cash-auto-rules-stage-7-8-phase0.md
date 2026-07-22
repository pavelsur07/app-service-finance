# Cash auto rules — Stage 7.8 Phase 0: read-only Cash/P&L analytics gap

## Status

- Phase: **Stage 7.8 Phase 0**
- Scope: **Cashflow read-only ЦФО filter on the existing report/API**
- Risk: **MEDIUM** — additive query/filter contract on existing read-only routes
- Code changes in this Phase 0: none yet

## Context

The original Stage 7.8 goal was:

- view totals by ЦФО;
- view projects within ЦФО;
- view ЦФО within project;
- view the Project × ЦФО matrix.

Stage 7.7.4 already implemented the P&L read-side part:

- optional `responsibilityCenterId` filter on P&L preview;
- project comparison scoped to a selected ЦФО;
- public P&L JSON `responsibilityCenterId` support;
- raw P&L debug Project/ЦФО labels;
- no rebuild or historical mutation.

Therefore Stage 7.8 must not duplicate P&L work. The remaining useful gap is Cash/DDS read-side analytics.

## Similar patterns inspected

1. `PlReportPreviewController` and `PublicPlReportController`
   - resolve active company;
   - accept optional `responsibilityCenterId`;
   - ignore invalid/foreign ЦФО ids;
   - preserve existing behavior when the filter is absent.

2. `CashflowReportBuilder`, `CashflowReportRequestMapper`, and `ReportCashflowController`
  - existing ДДС report is category × period × currency;
   - existing public API reuses the same builder;
   - current builder does not read `CashTransaction::responsibilityCenterId`.

3. `CashflowReportJsonFormatter`
   - existing export can include filter metadata;
   - additive filter metadata can be added without changing existing totals when omitted.

## Best-practice decision

Use the existing Cashflow report instead of adding a new analytics screen.

Stage 7.8 should add one optional filter:

```text
responsibilityCenterId=<uuid>
```

Behavior:

- if omitted, ДДС report totals stay unchanged;
- if valid and active for the current company, only Cash transactions with this ЦФО are included;
- if invalid, archived, or foreign, the filter is ignored like Stage 7.7.4 P&L filters;
- legacy `NULL` ЦФО rows remain included only in the unfiltered report;
- opening/closing account balances stay company-wide because account balances are not currently stored by ЦФО.

## Stage split

### Stage 7.8.1 — Cashflow report ЦФО filter

**Risk:** MEDIUM

Goal:

- extend `CashflowReportParams` with nullable scalar `responsibilityCenterId`;
- resolve the query parameter through `FinancialResponsibilityCenterFacade`;
- filter `CashflowReportBuilder` transactions by `t.responsibilityCenterId` when selected;
- show a ЦФО select on the existing ДДС report page;
- preserve the filter in JSON/CSV routes;
- include selected ЦФО in JSON filter metadata.

Definition of Done:

- [x] ДДС report without `responsibilityCenterId` is unchanged.
- [x] Valid ЦФО filters transaction category totals by scalar `responsibilityCenterId`.
- [x] Invalid/foreign/archived ЦФО ids are ignored.
- [x] JSON export includes `responsibility_center_id` in filter metadata when requested.
- [x] Public JSON/CSV endpoints accept the same optional filter additively.
- [x] No migration, no production mutation, no queue, no import, no recalculation.

Expected files:

- `site/src/Report/Cashflow/CashflowReportParams.php`
- `site/src/Report/Cashflow/CashflowReportRequestMapper.php`
- `site/src/Report/Cashflow/CashflowReportBuilder.php`
- `site/src/Finance/Controller/ReportCashflowController.php`
- `site/src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php`
- `site/templates/report/cashflow.html.twig`
- focused unit tests for mapper/builder/formatter
- `ARCHITECTURE.md`
- Stage report

Required checks:

- targeted unit tests for Cashflow report builder, mapper, and formatter;
- targeted Twig lint for `templates/report/cashflow.html.twig`;
- targeted PHP CS Fixer dry-run;
- `make site-test-unit`;
- external read-only Claude Code review.

## Explicitly out of scope

- New dashboard, React screen, or matrix UI.
- Project × ЦФО Cashflow matrix.
- Dynamic project/ЦФО dependent selects.
- Any historical backfill or recalculation.
- Production data mutation.
- Changes to P&L formulas, signs, periods, or Stage 7.7.4 P&L behavior.

## Phase 0 conclusion

Stage 7.8.1 is implementable without another owner decision because it is additive, read-only, and follows the already merged Stage 7.7.4 filter contract.
