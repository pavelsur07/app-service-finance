## Current checkpoint

**Phase:** Stage 3
**Status:** Stage 3 review green; preparing delivery
**Stage base commit:** 9384ec7ec99ba0d1f921fa2e52bf8b8160cebce4
**Current Work item:** Stage 3 delivery
**Owner gate:** no

### Completed
- Phase 0 repository inspection and business-rule reconciliation.
- Task branch `agent/cash-multicurrency-transfers` created from local
  `origin/master` at the recorded Stage base.
- Applicable instructions read; hashes recorded:
  - `AGENTS.md`: `250448192606423f156aafe015c8c7463c8d9f1023c23ac4089331eb42dc9cae`
  - `CLAUDE.md`: `78be8f88ff4f28cd1282071be43692e70d2809df48b6a63f8ad4310e1609551f`
- Relevant Cash, Money, multi-tenancy, form, transaction, report and frontend
  patterns inspected.
- Five-Stage implementation plan saved.
- Stage 1 task-base baseline passed: 8 tests, 122 assertions.
- Work item 1.1 complete:
  - added `FiatCurrency` as the single `RUB/USD/EUR/KZT` contract;
  - rejected unsupported account currency at construction/setter boundaries;
  - protected persisted account currency with a Doctrine pre-update invariant;
  - made edit currency disabled and reused enum choices in account/transaction
    forms and DTO validation;
  - regression proof on the old implementation: 3 expected failures;
  - final targeted result: 8 tests, 78 assertions, green.
- Work item 1.2 complete:
  - added company-scoped account and project repository lookups;
  - extended `CompanyFacade` with scoped project resolution;
  - removed unvalidated `getReference()` calls from transaction writes;
  - account, counterparty, cashflow category and project are now resolved in
    the transaction company before persist/update;
  - account switch and derived transaction currency are updated together;
  - regression proof: four tenant-isolation tests failed on old code;
  - final targeted results: `CashTransactionServiceTest` — 11 tests,
    39 assertions; combined service/facade/account action slice — green.
- Work item 1.3 complete:
  - facade creation is covered for unsupported and account-mismatched currency;
  - file rows reject unsupported currency and currency different from account;
  - file and 1C imports reject a cross-company account before reading/writing;
  - bank API import skips unsupported/mismatched rows with structured warning
    and import error accounting;
  - regression proof was red for file, bank and 1C safety cases;
  - final facade/import slice: 24 tests, 105 assertions; ClientBank1C slice:
    5 tests, 30 assertions, all green.
- Work item 1.4 complete:
  - legacy currencyless PaymentPlan matching is explicitly RUB-only;
  - an already persisted legacy match remains readable before the currency
    guard, so this change does not detach historical data;
  - regression proof failed on the old implementation because the USD
    transaction was matched and the plan was paid;
  - final `PaymentPlanMatcherTest`: 2 tests, 6 assertions, green.
- Work item 1.5 complete:
  - documented the fiat/account/transaction/PaymentPlan contract in
    `ARCHITECTURE.md`;
  - completed the integrated Stage checks and review-fix cycles;
  - added the Stage Report under `stages/stage-1.md`.
- Stage 1 delivered:
  - commit `e339b3dabdde1c8c893dc19e7bc0143699d08dac` pushed without force;
  - Draft PR #2310 opened against `master`;
  - CI status at handoff to Stage 2: `IN_PROGRESS` (`Detect changes`).
- Stage 2 baseline: 19 tests, 121 assertions, green.
- Work item 2.1 complete:
  - added the `CashTransfer` aggregate without duplicating leg amounts or
    currencies; transactions remain the source of truth;
  - added company-scoped idempotency, unique source/target legs, nullable
    manual FX metadata, timestamps and soft-delete audit fields;
  - added company/idempotency/leg repository lookups;
  - added PostgreSQL-only expand migration with FKs, unique indexes and
    structural CHECK constraints;
  - migration dry-run succeeded; the first isolated test up exposed and fixed
    the `companies` table name; final isolated up/down/up succeeded;
  - Doctrine mapping is valid and schema diff contains no `cash_transfer`
    drift (unrelated pre-existing schema drift remains outside the task);
  - red proof: three tests failed before the class existed; final entity and
    persistence slice: 4 tests, 24 assertions, green.
- Work item 2.2 complete:
  - `FiatCurrency::canTransferTo()` is the single v1 pair contract: same
    currency or RUB↔USD/EUR; KZT remains same-currency only;
  - added `EffectiveExchangeRateCalculator` using Money minor units and BCMath,
    with no float and HALF_UP rounding to scale 18;
  - quote direction is target currency per one source currency; the immutable
    rate value carries base, quote, date and `manual_effective` source;
  - same-currency transfers require equal Money amounts and return no FX
    metadata; inputs with excess currency scale are rejected instead of
    rounded silently;
  - red proof: 7 tests failed before the calculator existed; final rate/entity/
    pair slice: 15 tests, 55 assertions, green.
- Work item 2.3 complete:
  - added public create-transfer command/result and `CashFacade::createTransfer`;
  - source/target accounts are resolved company-scoped and must be different,
    active, non-crypto and open on the transfer date;
  - closed financial periods and unsupported currency pairs are rejected before
    persistence;
  - technical legs use exact active system children `CF_TECH_OUT`/`CF_TECH_IN`
    under system root `CF_TECH`, one balanced split each and the company general
    Project×ЦФО pair;
  - both leg amounts are user-provided, validated without implicit conversion,
    and canonicalized through `Money`;
  - red proof: facade tests failed before the contract existed; final create/
    tenant/period/opening/category validation slice: 7 tests, 34 assertions,
    green.
- Work item 2.4 complete:
  - aggregate, both legs, splits, transaction audits, balance snapshots and
    current balances are committed in one Doctrine/DBAL transaction;
  - account recalculation order is deterministic by account UUID and nested
    balance transactions use configured savepoints;
  - transfer creation suppresses auto-rule dispatch and does not invoke VAT or
    PaymentPlan matching; technical legs retain null VAT fields and no plan
    matches;
  - company-scoped idempotency uses a PostgreSQL transaction advisory lock plus
    the unique database constraint, and a sequential retry returns the exact
    same aggregate and leg IDs without repeating side effects;
  - snapshot cache invalidation runs once after commit only;
  - injected balance failure after the first flush proved rollback of the
    aggregate, both transactions and all related audit rows;
  - final action integration slice: 10 tests, 66 assertions, green.
- Work item 2.5 implementation/checks complete:
  - `ARCHITECTURE.md` now documents the aggregate, exact rate direction,
    technical categories, side-effect exclusions and public facade contract;
  - transfer-focused slice: 26 tests, 128 assertions, green;
  - complete `tests/Unit/Cash`: 190 tests, 661 assertions, green;
  - complete `tests/Integration/Cash`: 160 tests, 672 assertions, green;
  - bounded Cash/account functional slice: 59 tests, 415 assertions, green;
  - Doctrine mapping validation and PHP CS Fixer dry-run are green;
  - isolated test DB migration down/up succeeded; existing unrelated schema
    drift remains, with no generated `cash_transfer` SQL;
  - internal complete Stage review: no BLOCKER/IMPORTANT findings;
  - external review: `REVIEW_GREEN`; one safe MINOR clarified the idempotency
    replay contract in architecture documentation, and the alleged FX-pair
    duplication was rejected because both layers use the single
    `FiatCurrency::canTransferTo()` contract while aggregate validation is
    intentional defense in depth.
- Stage 2 review gate complete:
  - review-fix targeted repeat: 26 tests, 128 assertions, green;
  - final external complete-diff repeat: `REVIEW_GREEN`;
  - Stage Report added under `stages/stage-2.md`;
  - no unresolved BLOCKER or IMPORTANT findings.
- Stage 2 delivered:
  - commit `9384ec7ec99ba0d1f921fa2e52bf8b8160cebce4` pushed without force;
  - Draft PR #2310 updated and remains Draft;
  - CI at Stage 3 handoff: detect-changes green; isolated migrations, unit and
    API type checks in progress; production migrations job skipped.
- Stage 3 baseline: 26 tests, 133 assertions, green.
- Work item 3.1 complete:
  - added row-locked, company-scoped transfer delete/restore through
    `CashFacade`; aggregate and both legs change state atomically;
  - lifecycle validates current pair consistency and closed periods before
    mutation, then repeats validation under `PESSIMISTIC_WRITE` lock;
  - aggregate and both leg audit rows preserve the supplied actor; balance
    recalculation uses deterministic account order and snapshot cache is
    invalidated once after commit;
  - a balance failure after lifecycle flush proved rollback of aggregate,
    legs and audits;
  - ordinary edit/delete/restore, manual split edit and bulk delete reject an
    aggregate leg, while legacy `isTransfer=true` without an aggregate remains
    mutable;
  - transaction-to-transfer queries now require company scope and parenthesize
    source/target OR branches to preserve tenant filtering;
  - red-cycle fixed expected preflight exceptions closing EntityManager, a
    stale ORM read after DBAL balance upsert and repository testability;
  - final lifecycle/generic-guard slice: 52 tests, 267 assertions, green.
- Work item 3.2 complete:
  - verified the existing cashflow read model consumes transfer split rows,
    keeps totals keyed by currency and excludes both soft-deleted legs;
  - same-currency USD reports `CF_TECH_OUT=-100`, `CF_TECH_IN=+100` and zero
    technical-root/account net;
  - cross-currency RUB→EUR reports independent `RUB=-10000` and `EUR=+100`
    movements without conversion or a mixed total;
  - deleting the aggregate removes both movements and returns each currency
    closing to zero;
  - cashflow/transfer slice: 18 tests, 156 assertions, green.
- Work item 3.3 complete:
  - dashboard API accepts a validated fiat `currency` and defaults to `RUB`;
  - snapshot context, telemetry, warmup output and cache key carry the selected
    `cash_currency`, so cached RUB/USD/EUR/KZT snapshots cannot collide;
  - free cash, funds, inflow, outflow, CAPEX, cashflow split and top-cash
    queries filter by currency before aggregation, while revenue/profit/top-P&L
    builders remain unchanged;
  - every Cash drilldown propagates the selected currency;
  - functional coverage proves separate RUB/USD cash totals and identical P&L
    payloads; unsupported currency returns 422;
  - analytics/dashboard slice: 17 tests, 180 assertions, green.
- Work item 3.4 complete:
  - the shared list/export filter contract now carries an explicit currency;
  - the company-scoped repository applies currency before pagination or XLSX
    projection, so the screen and export cannot diverge;
  - the list filter exposes all supported fiat currencies and current query
    context is retained in pagination and the export link;
  - functional coverage combines 21 USD rows, a RUB row and a foreign-tenant
    USD row to prove currency filtering, page boundaries, tenant isolation and
    unfiltered backward compatibility;
  - final list/export slice with invalid-currency coverage: 10 tests,
    49 assertions, green.
- Work item 3.5 complete:
  - `ARCHITECTURE.md` documents atomic transfer lifecycle, generic mutation
    guards, report behavior, dashboard currency/cache contract and list/export;
  - final combined Unit/Integration/Functional Cash+Analytics run: 439 tests,
    1962 assertions, green;
  - Doctrine mapping, warmup help, Twig lint, Stage-owned PHP CS and
    `git diff --check` are green;
  - whole-repository CS remains red on 581 pre-existing unrelated files;
  - internal review is green;
  - external review-fix cycles ended with final `REVIEW_GREEN`; both reported
    MINORs (currency validation and repository finality) are resolved;
  - Stage Report added under `stages/stage-3.md`.

### Current diff / affected files
- `site/migrations/Version20260809120000.php` — expand-only transfer table.
- `site/src/Cash/Entity/Transfer`, `Repository/Transfer`, `Service/Transfer`
  and `ValueObject/Transfer` — aggregate and exact FX contract.
- `site/src/Cash/Application/CreateCashTransferAction.php` plus command/result
  DTOs and `CashFacade` — atomic public use case.
- Transfer unit/integration tests, `ARCHITECTURE.md`, plan and checkpoint.

### Checks and baseline
- Previous exploratory baseline on the prior branch:
  `docker compose run --rm -T -e APP_ENV=test site-php-cli php bin/phpunit -c phpunit.xml --filter 'MoneyAccountNewControllerTest|CashTransactionServiceTest|CashflowReportBuilderTest'`
  — `OK (8 tests, 122 assertions)`; must be repeated on the task base before
  executable changes.
- Current task-base baseline:
  `docker compose run --rm -T -e APP_ENV=test site-php-cli php bin/phpunit -c phpunit.xml --filter 'MoneyAccountNewControllerTest|CashTransactionServiceTest|CashflowReportBuilderTest'`
  — `OK (8 tests, 122 assertions)`.

### Review status
- Work items 1.1–1.4: focused self-review green.
- Stage 1 internal complete review: green after preserving crypto compatibility
  and correcting bank import error/duplicate accounting.
- Stage 1 external complete review: `REVIEW_GREEN`; safe repository UUID-guard
  MINOR fixed and the updated complete diff re-reviewed `REVIEW_GREEN`.
- Stage 2 internal complete review: green; no BLOCKER/IMPORTANT findings.
- Stage 2 external complete review: final repeat `REVIEW_GREEN` after the
  idempotency caller contract was documented.
- Stage 3 internal complete review: green; no BLOCKER/IMPORTANT.
- Stage 3 external complete review: final repeat `REVIEW_GREEN`; no unresolved
  BLOCKER, IMPORTANT or MINOR findings.
- Unresolved BLOCKER/IMPORTANT: none.
- Stage 1 FOLLOW-UP: consider a pre-flush account update contract if account editing is
  moved away from the current entity-bound form; keep the Doctrine callback as
  the global safety net until an equally comprehensive replacement exists.
- Stage 2 FOLLOW-UP for planned Work item 3.1: require `companyId` in the
  transaction-to-transfer lookup before production lifecycle callers use it.

### Exact next action
- Commit and push the complete Stage 3 diff, update Draft PR #2310, record the
  Stage 4 base commit and continue automatically because `owner_gate: no`.

### Files to inspect first on resume
- `ARCHITECTURE.md`
- complete Stage diff from `9384ec7ec99ba0d1f921fa2e52bf8b8160cebce4`.
