# Stage 2 Report — active/deleted read boundaries

- Risk: HIGH-LOCAL
- Base commit: `90c9dabf829f6dc03e9156cae2a1bbd6ea68c085`
- Result: soft-deleted P&L documents are inaccessible through normal document routes and absent from linked Cash transaction views.

## Delivered

- Added a shared deleted-document 404 guard to show, edit, copy, and per-document JSON export.
- Kept tenant mismatch and deleted state indistinguishable to callers.
- Added direct functional coverage for active 200 vs deleted 404 on all four routes.
- Added direct functional coverage that the Cash transaction page lists the active P&L document and hides the deleted one.

## Checks

- Stage controller suite: 15 tests, 117 assertions — green; one pre-existing/dependency deprecation reported by PHPUnit.
- Focused final test: 2 tests, 11 assertions — green.
- PHP CS Fixer dry run and `git diff --check`: green.
- Repository/read-path search completed; no additional in-scope `Document` controller boundary was found.

## Reviews

- Internal review: green.
- External Claude review: active positive control added for all guarded routes; repeat review returned `REVIEW_GREEN`.

## Follow-up carried into Stage 3

- Replace the manual UI hard-delete controller path with `SoftDeleteDocumentAction` and wire the deleted page plus restore route.
