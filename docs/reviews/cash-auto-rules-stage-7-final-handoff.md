# Cash auto rules — Stage 7 final handoff

## Status

- Stage 7 is closed.
- Stage A / 7.8.1 production acceptance: DONE.
- Stage B / 7.11 consolidated cleanup: DONE, merged as PR #2199.
- Stage C / 7.8.2 Cashflow Project × ЦФО matrix: DONE, merged as PR #2201.
- Stage D final closure: DONE.

## What is now in `master`

- Company-owned ЦФО master data and system `PROJECT_GENERAL × CFO_GENERAL` pair.
- Protected ЦФО UI under `Справочники → ЦФО`.
- Cash, Finance documents, document operations, and P&L daily totals carry nullable ЦФО identity.
- New supported Cash/Finance writers default empty pairs to the company system pair and validate explicit Project × ЦФО pairs.
- P&L daily totals aggregate by Project × ЦФО.
- P&L read side supports ЦФО filtering and project comparison scoped to a selected ЦФО.
- Cashflow report/API supports optional ЦФО filtering.
- Cashflow report/API includes a Project × ЦФО matrix with both views:
  - `ЦФО → проекты`;
  - `Проект → ЦФО`.
- Auto rules can assign ЦФО through the reviewed safe-fill/project-pair contract.
- Stage 7 cleanup removed stale status loops and temporary old-schema runtime fallback.

## Production/data safety

- Stage 7 deployment did not backfill historical facts by default.
- Existing rows with `NULL` ЦФО remain legacy/unallocated until separately scoped.
- Production import smoke was intentionally not run because it mutates production.
- Queue consume/retry/delete was not performed.
- No historical recalculation was performed as part of closure.

## Final checks recorded in stage reports

- Stage 7.11:
  - targeted integration/functional tests passed;
  - `make site-test-unit` passed;
  - targeted lint/CS passed;
  - external read-only Claude review returned `REVIEW_GREEN`;
  - PR #2199 CI passed and was merged.
- Stage 7.8.2:
  - targeted unit/functional tests passed;
  - `make site-test-unit` passed;
  - Twig/container lint passed;
  - targeted PHP CS Fixer and `git diff --check` passed;
  - external read-only Claude review returned `REVIEW_GREEN`;
  - replacement PR #2201 CI passed and was merged.

## Known follow-ups intentionally outside Stage 7

- Production import smoke with explicit source/company/date bounds.
- Historical backfill or recalculation.
- Queue failed-message payload inspection, retry, or delete.
- Cashflow selected-ЦФО performance optimization if measured as slow.
- Composite DB guard for pair-removal race, if later required.
- Owner/financial-director permission model for ЦФО management.

## Operational note

PR #2200 was the original stacked Stage 7.8.2 PR. It was closed automatically after PR #2199 was merged and its base branch was deleted. The same Stage 7.8.2 head was reopened against `master` as PR #2201 and merged successfully.
