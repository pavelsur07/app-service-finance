## Current checkpoint

**Phase:** Stage 1
**Status:** REVIEW_GREEN — delivery in progress
**Stage base commit:** 1b77472f66085752ed3dffd78e3a4f6ccbc9162b
**Current Work item:** Stage boundary
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

### Current diff / affected files
- `docs/tasks/cash-multicurrency-transfers/plan.md` — Phase 0 plan.
- `docs/tasks/cash-multicurrency-transfers/checkpoint.md` — this checkpoint.
- `site/src/Cash/Enum/FiatCurrency.php` — supported fiat contract.
- `site/src/Cash/Entity/Accounts/MoneyAccount.php` — validation and immutable
  persisted currency.
- Account/transaction forms, DTO and focused tests for Work item 1.1.
- Transaction service, account/project repositories, Company facade and
  tenant-safety tests for Work item 1.2.
- Bank, 1C and file import currency boundaries and tests for Work item 1.3.
- PaymentPlan RUB-only matching boundary and regression test for Work item 1.4.

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
- Internal complete Stage review: green after preserving crypto compatibility
  and correcting bank import error/duplicate accounting.
- External complete Stage review: `REVIEW_GREEN`; safe repository UUID-guard
  MINOR fixed and the updated complete diff re-reviewed `REVIEW_GREEN`.
- Unresolved BLOCKER/IMPORTANT: none.
- FOLLOW-UP: consider a pre-flush account update contract if account editing is
  moved away from the current entity-bound form; keep the Doctrine callback as
  the global safety net until an equally comprehensive replacement exists.

### Exact next action
- Commit and push the reviewed Stage 1 diff, update the task Draft PR, then
  record the new HEAD as the Stage 2 base and continue automatically.

### Files to inspect first on resume
- `site/src/Cash/Entity/Accounts/MoneyAccount.php`
- `site/src/Cash/Service/Accounts/MoneyAccountService.php`
- `site/src/Cash/Form/Accounts/MoneyAccountType.php`
- `site/src/Cash/Form/Transaction/CashTransactionType.php`
- `site/src/Cash/Service/Transaction/CashTransactionService.php`
