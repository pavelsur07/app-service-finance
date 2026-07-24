# Stage 1 Report — persisted WB spend reconciliation

Stage base commit: `44b28304d27c5e1025e8c29230d75b928a36681f`

## Result

- Added tenant- and raw-document-scoped persisted reconciliation using exact
  `Money` arithmetic.
- Exposed source, document, line, without-line, intentional unallocated, and
  real unmapped totals in the loader result, structured logs, and CLI output.
- Enforced four fail-closed invariants. A mismatch leaves or resets the raw
  document to `DRAFT`, logs
  `event=wb_ad_spend_reconciliation_failed`, and fails the connection.
- Kept the existing `total=` CLI field as a backward-compatible alias for the
  source total.
- Added unit and integration regression coverage, including multi-line
  documents, tenant isolation, negative corrections, an already-`DRAFT` raw
  document, and an isolated source/persisted-unallocated mismatch.

No database migration or public HTTP API change was introduced.

## Checks

- Focused query/action/command tests: 17 tests / 117 assertions — green.
- MarketplaceAds unit suite: 335 tests / 2128 assertions — green.
- MarketplaceAds integration suite: 173 tests / 697 assertions — green.
- Symfony container lint — green.
- Task-scoped PHP CS Fixer — green.
- PHP syntax lint — green.
- `git diff --check` — green.

## Reviews

- Internal independent review: green after fixing the already-`DRAFT`
  mismatch path and improving result/fixture coverage.
- External Claude review: `REVIEW_GREEN` after resolving all confirmed
  in-scope findings and safe documentation/test nits. The final MINORs were
  closed by putting all four invariants behind the DTO's `reconciles()`
  contract and adding same-company/raw-document isolation plus fail-closed
  unallocated-line coverage.
- Remaining BLOCKER/IMPORTANT findings: none.

The external reviewer could not execute Docker-backed tests under its
read-only Bash policy; Codex executed and recorded those suites above.

## Production

No production action was performed. The Production Gate remains closed.
