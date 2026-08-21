# Stage 1 Report — P&L document soft-delete lifecycle

- Risk: HIGH-LOCAL
- Base commit: `2a331ee348244cb6f59713d84c63424f4651c413`
- Result: `Document` has an auditable soft-delete lifecycle for the manual UI path; `DeletePLDocumentAction` remains a physical system delete.

## Delivered

- Added nullable `deleted_at`, `deleted_by`, and `delete_reason` columns plus the company/deletion index.
- Added tenant-safe soft-delete/restore actions with P&L register recalculation.
- Soft-deleted documents no longer consume a linked cash transaction allocation; restore revalidates available capacity.
- Active document pagination, P&L register reads, raw P&L report, JSON export, and linked cash-transaction document display exclude deleted documents.
- Technical month-reopen deletion remains physical and is covered by an integration test.

## Checks

- Baseline: 31 tests, 166 assertions — green.
- Stage targeted: 25 tests, 139 assertions — green; one pre-existing/dependency deprecation reported by PHPUnit.
- PHP syntax: green for every changed PHP file.
- Doctrine mapping validation: green.
- PHP CS Fixer dry run: green.
- `git diff --check`: green.

## Reviews

- Internal review: green.
- External Claude review: three missing active-document predicates were fixed; repeat review returned `REVIEW_GREEN`.

## Follow-ups carried into Stage 2/3

- Add direct HTTP coverage proving a deleted P&L document is absent from the linked ДДС transaction page.
- Block show/edit/copy/per-document export for deleted documents when the UI lifecycle is wired.

## Operational notes

- Migration: `Version20260821090000`; additive and non-destructive on `up()`, reversible on `down()`.
- No production migration or other Production Gate action was run.
- `deleted_by` is intentionally a denormalized audit string, matching the Cash soft-delete fields.
