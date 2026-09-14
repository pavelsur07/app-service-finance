# Balance ledger implementation plan

Spec: TASK.md. Class Large, overall risk HIGH-LOCAL. Base: 238dd997.
Baseline: docker exec vf-balance-dev php bin/phpunit tests/Unit/Balance (running; result in checkpoint).
Original work packages below preserved. Delivery ruling: combine original backend Stages 1–3 into Stage 1 because structure, authorization, schema and ledger services must integrate before a reviewable backend commit. Original frontend Stage 4 becomes Stage 2; final Stage 5 becomes Stage 3. All DoD/work items/checks below remain required. This changes delivery grouping only, not scope.

Workflow: three delivery Stages, one branch/PR, no owner gate between stages; production migration/deploy only at final approval. All work on feat/balance-ledger in isolated worktree.

## Stage 1: Structure and storage
Risk HIGH-LOCAL; stage_base_commit 238dd997.
DoD: additive schema preserves legacy; two sides, four levels, accounts, company constraints, archive/config.
Work items: 1.1 new entities/migration and tests; 1.2 adapt categories/policies/seed, ownership checks; 1.3 integrate schema + module tests and focused static checks, internal/external review (>500 lines).
Checks: Balance unit/integration tests, Doctrine schema validation, migration preservation test, focused PHP lint/PHPStan.
Review: cross-company FKs, deletion/archive safety, no destructive down, tree depth/cycles.

## Stage 2: Accounting core
Risk HIGH-LOCAL; stage_base_commit recorded at start.
DoD: opening/draft/post/correction/reversal, exact state/history, idempotency/concurrency, used classification locks.
Work items: 2.1 failing accounting policy cases then core; 2.2 DBAL transactional commands/current states and integration tests; 2.3 full stage diff review/fix, module checks.
Review: signs/equation, no float, historical overdrafts, transaction rollback, immutable postings.

## Stage 3: Periods, permissions and reports
Risk HIGH-LOCAL; stage_base_commit recorded at start.
DoD: period gates, company + granular permissions, complete audit, all read models and Facade.
Work items: 3.1 close/reopen + access; 3.2 report/card/journal/statement/compare queries; 3.3 update contracts, integration tests, review.
Review: financial consistency, IDOR, race conditions, lost grants and membership.

## Stage 4: User interface
Risk MEDIUM; stage_base_commit recorded at start.
DoD: all TASK screens functional via Twig/Forms/Stimulus/UI Kit; old links disabled.
Work items: 4.1 routes/forms and CSRF/access; 4.2 screens/interaction/navigation; 4.3 functional tests and browser smoke, frontend gates and review.
Review: empty/error/responsive states, strict validation, no browser-authoritative money.

## Stage 5: Acceptance and handoff
Risk HIGH-LOCAL; stage_base_commit recorded at start.
DoD: complete acceptance, full gates, fresh internal + external review, handoff and Ready PR.
Work items: 5.1 acceptance/concurrency/migration tests; 5.2 site-stan, site-cs-check, site-cs-strict-types, site-test-unit, site-test and frontend checks; 5.3 fresh review/fixes, handoff, ready PR with migration approval request.
Review: whole diff against TASK. Do not delete remote branch or deploy before owner approval.
