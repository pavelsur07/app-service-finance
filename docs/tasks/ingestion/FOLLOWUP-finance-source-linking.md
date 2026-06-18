# FOLLOW-UP — Finance source linking after Ingestion acceptance/deploy

## Status

Decision required after Ingestion acceptance and deployment.

This is not part of Blocks 4-7 implementation and must not block the current Ingestion rollout.

## Context

Stage 7 added P&L dirty-period infrastructure for future canonical rebuilds. During review, the owner clarified an architectural boundary:

- `shop_ref` must not be added to `pl_daily_totals` or `pl_monthly_snapshots`.
- P&L aggregate tables are calculated result tables and should not carry source/shop linkage.
- Source linkage belongs closer to the business document layer: `Document.php` and/or `DocumentOperation.php`.
- The final field name may be not `shop_ref`; it should be a business-level name for preserving links from other modules.

## Decision Question

Where should Finance store links from external/source modules after Ingestion becomes accepted and deployed?

Options to evaluate:

1. Add a source/origin reference to `Document`.
2. Add a source/origin reference to `DocumentOperation`.
3. Add both document-level and operation-level references if one external source document can create multiple operations with different source identities.
4. Use a separate link table if multiple modules may attach several references to the same document/operation.

Naming to decide:

- avoid leaking technical `shop_ref` naming into Finance if it is not the business concept;
- consider names such as `originRef`, `sourceRef`, `externalContextRef`, or a domain-specific Russian/English business term;
- preserve the ability to reference Ingestion canonical transactions, marketplace documents, ads reports, and future source modules.

## Explicit Non-Goals

- Do not add `shop_ref` to `pl_daily_totals`.
- Do not add `shop_ref` to `pl_monthly_snapshots`.
- Do not change existing P&L formulas or aggregate semantics as part of this decision.
- Do not implement this before Ingestion is accepted and deployed.

## Acceptance Criteria For The Future Task

- Architecture decision records whether the reference belongs to `Document`, `DocumentOperation`, both, or a dedicated link table.
- Field/table naming is business-oriented and not tied to Ozon/WB-specific shop terminology.
- Migration plan is additive and safe for existing Finance data.
- Existing legacy P&L reports keep working during rollout.
- Tests cover preserving source links when Finance documents/operations are created from Ingestion.
