## Current checkpoint

**Phase:** Stage 5
**Status:** implementation in progress
**Stage base commit:** 0e646cb42cf971180d7d9107f0983ee48490df62
**Current Work item:** 5.3
**Owner gate:** yes — Final Release Gate after completed delivery

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
- Stage 3 delivered:
  - commit `3c9cd4cd53dc07dadf4a06f541f69e52ce28163f` pushed without force;
  - Draft PR #2310 updated and remains Draft;
  - PR workflow at Stage 4 handoff: `in_progress` (run 4900).
- Stage 4 frontend instructions recorded:
  - `CLAUDE.frontend.md` hash:
    `1d4176e3de4f865f37a185c3596b89bba334723bb26782de5eb31fa229ada22c`;
  - `PATTERNS.md` hash:
    `aee5498cae3cf96a6922103d931f4b92771171625e8512afa18135f0d52a09f7`;
  - legacy frontend STOP language is supplementary and does not override the
    explicit implementation instruction plus repository-root autonomous
    Stage workflow; no Vite entry, dependency or UI Kit mutation is planned.
- Stage 4 baseline:
  - backend slice: 25 tests, 231 assertions, green;
  - frontend lint and production build green; the existing missing
    `@symfony/ux-turbo/package.json` warning remains unchanged.
- Work item 4.1 complete:
  - added a transfer form DTO preserving exact decimal strings and a hidden
    UUIDv7 idempotency key;
  - account choices are active, fiat, non-crypto and company-scoped, with
    currency metadata exposed read-only;
  - tampered foreign choices and missing dates remain validation errors;
  - final form slice: 3 tests, 12 assertions, green.
- Work item 4.2 complete:
  - added create/show/delete/restore/deleted-list routes under
    `/finance/cash-transfers` with `ROLE_USER`, company scoping and CSRF;
  - the controller delegates all financial validation and atomic lifecycle to
    `CashFacade`; JavaScript only mirrors account currency labels;
  - show/deleted templates display both exact legs, effective quote direction,
    real system category names and pair status;
  - functional coverage proves idempotent resubmit, invalid-CSRF protection,
    tenant isolation and atomic delete/restore.
- Work item 4.3 complete:
  - list/deleted-list screens resolve aggregate legs in one company-scoped
    batch query, avoiding N+1 lookups;
  - aggregate legs link to the transfer, are excluded from bulk selection and
    do not expose edit/split/delete/individual-restore actions;
  - direct split entry redirects to the aggregate; standalone transactions
    and legacy `isTransfer=true` rows remain unchanged.
- Work item 4.4 complete:
  - dashboard selector supports RUB/USD/EUR/KZT, carries `currency` into the
    snapshot API and persists currency plus active period in the URL;
  - Cash widgets render in the selected currency while P&L widgets retain RUB;
  - server-rendered home KPIs now filter active accounts and cashflow tree
    totals by the selected currency instead of mixing fiat balances;
  - unsupported home currency redirects to the explicit RUB default;
  - frontend lint/build and Home functional coverage are green.
- Work item 4.5 complete:
  - complete bounded Cash/Analytics/Home run: 438 tests, 1977 assertions,
    green;
  - Doctrine mapping, Twig lint, test cache warmup, task-scoped PHP CS,
    frontend lint/build and `git diff --check` are green;
  - host-side Vite initially hit a root-owned generated `.vite` directory from
    prior container output; the project `site-frontend` container build is
    green without changing host permissions.
  - internal independent Stage review is green after two safe MINOR fixes:
    selected-currency cashflow KPI coverage and loading the existing dashboard
    Vite entry on `/dashboard`;
  - four initial external Claude attempts (ordinary
    working diff, intent-to-add diff, fully staged diff, and full diff supplied
    through stdin) each exhausted mandatory `--max-turns 40` without returning
    findings or `REVIEW_GREEN`; preflight/auth succeeded every time;
  - owner explicitly authorized `--max-turns 120` with all safe/read-only
    restrictions unchanged; the first completed review ended `REVIEW_GREEN`;
  - its one confirmed MINOR found an unsound count heuristic for the list
    header checkbox when only one transfer leg is present after filtering;
    calculation now compares current-page IDs with aggregate-leg map keys and
    functional coverage reproduces the filtered one-leg boundary;
  - two reviewer FOLLOW-UPs (response race guard and URL persistence for the
    pre-existing period controls) remain outside the Stage 4 DoD;
  - review-fix complete Stage run: 438 tests, 1978 assertions, green; focused
    PHP CS is green;
  - a second completed review ended `REVIEW_GREEN`; its safe MINORs were fixed
    by reusing the current-page transaction IDs and adding a dedicated eager
    detail lookup without changing generic lifecycle/persistence semantics;
  - focused tests caught an invalid eager-join assumption for aggregates
    persisted without splits, so detail and generic lookup paths were cleanly
    separated; final transfer slice: 19 tests, 179 assertions, green;
  - a third completed review ended `REVIEW_GREEN`; its two cosmetic MINORs
    (unused frontend type field and inline test class names) were fixed;
  - final functional repeat: 2 tests, 44 assertions; frontend lint and focused
    PHP CS are green;
  - final complete-diff external review ended `REVIEW_GREEN`; no BLOCKER or
    IMPORTANT findings remain. Its currency-formatter MINOR was rejected as
    confirmed pre-existing UI-Kit debt outside this Stage;
  - Stage Report added under `stages/stage-4.md`.
- Stage 4 delivered:
  - commit `0e646cb42cf971180d7d9107f0983ee48490df62` pushed without force;
  - Draft PR #2310 updated and remains Draft;
  - CI at Stage 5 handoff: Frontend Lint and the repository deploy-named
    workflow are `in_progress`; no production action was invoked by Codex.
- Stage 5 baseline: 33 transfer tests, 238 assertions, green.
- Work item 5.1 complete:
  - added read-only `app:cash:verify-transfers` using DBAL so damaged rows do
    not need to hydrate through domain constructors;
  - detailed company scope is processed in batches of 100 and only aggregate
    counts are printed; IDs, amounts and account details never enter
    diagnostics. Idempotency/leg uniqueness use two intentional global scans
    so cross-batch corruption cannot be hidden;
  - verifier covers pair/company/account/direction/currency, technical splits,
    same-currency equality, exact FX metadata, pair deletion, idempotency and
    cross-role leg ownership;
  - unlinked legacy `isTransfer=true` rows are counted as INFO, not errors;
  - the command has no repair/execute option and a failing test proves corrupt
    direction/rate values remain unchanged after diagnostics;
  - focused verifier tests: 3 tests, 19 assertions; combined transfer/verifier
    slice: 36 tests, 257 assertions, green; command help and focused PHP CS are
    green.
- Work item 5.2 complete:
  - `ARCHITECTURE.md` now records transfer UI boundaries, selected-currency
    Home behavior and the read-only verifier contract;
  - `site/src/Cash/README.md` documents the aggregate/facade contract, exact
    v1 limits, operational verification and safe expand-first rollout;
  - production category execution, migration, deploy, write smoke and rollback
    remain explicit Production Gate actions;
  - rollback after aggregate creation is documented as forward-fix preferred;
    code without pair guards must not receive Cash writes.
- Work item 5.3 verification in progress:
  - complete backend suite: 3221 tests, 17687 assertions, green;
  - final verifier review-fix repeat: 3 tests, 20 assertions, green;
  - all 225 Twig templates and Doctrine mapping validation are green;
  - task-owned PHP CS Fixer scope: 79 files, green; whole-repository CS remains
    red on 576 pre-existing unrelated files;
  - frontend ESLint and production build are green; the existing missing
    `@symfony/ux-turbo/package.json` build warning is unchanged;
  - whole-repository UI-Kit class check remains red: task-base baseline was
    9086 legacy usages and current is 9194 because the new Twig screens reuse
    the same neighboring legacy Bootstrap patterns; fixing the global gate or
    redesigning the screens is excluded UI-Kit scope;
  - React UI-Kit mapping remains red on the same 47 missing wrappers; neither
    its inputs nor non-legacy React UI-Kit files changed in this task;
  - full schema validation confirms mapping is valid but reports pre-existing
    unrelated DB drift; schema dump contains no `cash_transfer` SQL;
  - direct test-environment `app:cash:verify-transfers` run is green and prints
    only zero-valued aggregate diagnostics;
  - frontend typecheck/test scripts do not exist; supported lint/build checks
    were run instead. The Stage 2 isolated migration down/up and SQL review
    remain green because the migration has not changed since delivery.
  - internal independent Stage 5 review is green after extending the account
    check to supported fiat account types, existing account rows and opening
    dates; no BLOCKER or IMPORTANT findings remain;
  - first external Stage 5 review found one confirmed BLOCKER in the read-only
    FX predicate: PostgreSQL division of two `NUMERIC(18,2)` values may retain
    only 16 fractional digits for a rate greater than one, causing false FAIL;
  - the verifier now casts the numerator to `NUMERIC(38,19)` before HALF_UP
    rounding to scale 18; direct SQL reproduced the old mismatch
    `...940900` versus the application value `...940902`;
  - a non-round USD→RUB regression (`1234.56` → `98765.43`) proves the valid
    large-quotient direction is green; focused repeat: 3 tests, 20 assertions,
    and PHP CS are green;
  - internal review-fix repeat is green; the next external repeat exhausted 40
    turns without a verdict and was not counted;
  - the completed external repeat ended `REVIEW_GREEN`; no BLOCKER/IMPORTANT
    findings remain. Its batching MINOR was resolved by documenting the two
    intentional global uniqueness scans; the final documentation repeat also
    ended `REVIEW_GREEN`.
  - final internal review of the complete task diff from exact task base
    `1b77472f66085752ed3dffd78e3a4f6ccbc9162b` is green; no new BLOCKER or
    IMPORTANT findings remain;
  - the first final external full-task review ended `REVIEW_GREEN` with one
    confirmed local MINOR: direct `CashFacade::createTransfer()` callers could
    pass comma-decimal amounts that the rate calculator normalized but the
    persisted `Money` parser did not. The action now normalizes amounts once
    before both operations, and a facade-level regression covers spaced comma
    decimals; focused transfer/verifier repeat is green (20 tests, 147
    assertions), as are PHP CS Fixer and `git diff --check`;
  - the dashboard reload UX and a stronger DB-level cross-role leg uniqueness
    guard remain accepted FOLLOW-UP items: neither is a current correctness
    path, and the latter is detected by the verifier;
  - final full-task external review repeat confirmed the normalization fix and
    ended `REVIEW_GREEN`; no new findings remain. Stage 5 review gate and the
    complete task review gate are green.

### Current diff / affected files
- `site/src/Cash/Command/VerifyCashTransfersCommand.php` and its integration
  test — read-only invariant diagnostics.
- `site/src/Cash/Application/CreateCashTransferAction.php` and its integration
  test — facade-safe amount normalization found during final review.
- `ARCHITECTURE.md`, `site/src/Cash/README.md`, plan, checkpoint and Stage 5
  report — architecture, rollout and delivery evidence.

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
- Stage 4 internal complete review: green; no BLOCKER/IMPORTANT findings.
- Stage 4 external review: final complete-diff `--max-turns 120` repeat ended
  `REVIEW_GREEN`; no unresolved in-scope BLOCKER, IMPORTANT or MINOR findings.
- Stage 5 internal and external complete reviews: `REVIEW_GREEN` after the FX
  precision fix and batching documentation clarification.
- Final internal and external full-task reviews from exact task base
  `1b77472f66085752ed3dffd78e3a4f6ccbc9162b`: `REVIEW_GREEN` after the
  facade amount-normalization MINOR was fixed and re-reviewed.
- Unresolved BLOCKER/IMPORTANT: none.
- Stage 1 FOLLOW-UP: consider a pre-flush account update contract if account editing is
  moved away from the current entity-bound form; keep the Doctrine callback as
  the global safety net until an equally comprehensive replacement exists.

### Exact next action
- Commit the task-owned Stage 5 changes, push without force and update Draft PR
  #2310. Then report the completed Final Release Gate and request only the
  owner's decision whether to mark the PR Ready.

### Files to inspect first on resume
- Stage 5 diff from `0e646cb42cf971180d7d9107f0983ee48490df62`.
- Existing read-only verification commands and company-batched repository
  iteration patterns.
- Transfer aggregate invariants, technical categories and migration checks.
