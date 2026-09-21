# moysklad-stock-sync — checkpoint

**Phase:** Stage 1
**Status:** Phase 0 complete, Work item 1.1 next
**Task base:** `13f706a92e847eb21d1c82c303257b5196735b3e`
**Branch:** `feat/moysklad-stock-sync`
**Draft PR:** not created yet

## Completed

- Fresh worktree created from merged PR #2497.
- Official JSON API 1.2 revision `74f6a4fa` reviewed for `/entity/store`,
  `/report/stock/bystore`, pagination, stockMode and current rate limits.
- Expanded report selected over `/current`; module boundary and snapshot
  lifecycle fixed in `plan.md` and `stock-api-contract.md`.
- Locally available test key checked without disclosure; API returned 401, so
  it is not used as a MoySklad fixture source.
- Baseline `make site-test-unit`: 2965 tests, 16341 assertions, 4 deprecations.

## Exact next action

Write parser contract tests for Store and Stock fixtures, run RED, then add the
minimal DTO/parser implementation.
