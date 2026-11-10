### Stage 2: Атомарность счетов и восстановление — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `97c0d7495`
**Work items:** 2.1–2.3

#### What was done
- SaveMoneyAccountAction owns saving source/bank-link data and recalculation under the existing balance advisory lock, taken before the first source flush. ORM and DBAL rollback together; account refresh follows DBAL writes.
- Recalculator reloads saved opening parameters after lock acquisition, joins the caller transaction and documents the flush-first precondition. Formulas/date semantics unchanged.
- Restore emits CashTransactionsChanged only after successful transaction completion.
- Accounts/funds/transfers/calendar routes moved to final single-action controllers with readonly constructor DI. Draft creation and bank mutation are in existing Actions, new IDs UUIDv7; fund amount parsing moved into Action.
- Calendar list query moved to repository; scoped context/forecast to Query; shared HTTP presenter reuses existing recurrence Action. CounterpartyFacade optional includeArchived preserves full filter choices and ordinary form defaults.
- Removed 15 obsolete baseline entries for deleted controllers; new code passes level 8 without adding baseline.

#### Definition of Done
- [x] Real DB failure rolls back account and previously written snapshots.
- [x] Managed/reloaded current balance matches DBAL calculation and survives later flush.
- [x] Both real-process save/recalc orderings preserve latest saved parameters without deadlock.
- [x] Restore event emitted after successful transaction only; rollback emits none.
- [x] 74 original Cash route URL/method/requirement contracts unchanged; existing access/CSRF tests pass.

#### Checks
- Baseline: Stage 1 Cash module PASS 906 tests / 3608 assertions.
- RED: missing account recalc/rollback, stale-source recalc, missing restore event; independent save/recalc race reproduced stale 0 instead of 123.45 on original code.
- Internal lock-order finding reproduced with a second independent-process test (waiting save held row and blocked finishing recalc); fixed and GREEN both orders.
- Final focused account/restore/concurrency: 7 tests / 23 assertions PASS.
- Final Cash module + focused Finance pages: **915 tests / 3699 assertions PASS**.
- Scoped counterparty query regression RED→GREEN: own archived retained, foreign hidden, default form excludes archived.
- Focused PHPStan: no errors. Focused CS Fixer: no pending changes. Route comparison: 74 / 74 unchanged. git diff --check clean.

#### Internal review
- One IMPORTANT (row-before-advisory inversion) fixed with RED→GREEN real-process regression. No BLOCKER/open IMPORTANT.
- Checked full Stage diff: company scope, bank-link mutation parity, lock/current-state behavior, rollback and post-commit event, retained route/access/CSRF, no template/recurrence/formula changes.

#### External review
- Required: yes, HIGH-LOCAL >500 lines. Claude Code read-only, 1 round, exact **REVIEW_GREEN**.
- BLOCKER/IMPORTANT: 0 / 0.
- MINOR: flush-first doc added; deleted controller comments updated. Counterparty filter displayName label deferred to Stage 5 (explicit frontend separation), to match existing facade sorting.
- Reviewer ran no tests, read-only diff/context.

#### Risks / follow-ups
- Existing GET recurrence materialization preserved, explicitly out of scope for correction.
- Pagination intentionally delivered together with templates in Stages 4–5, no intermediate deploy.
- No migrations/dependencies/production changes.

#### Next
- Continue to Stage 3 automatically.
