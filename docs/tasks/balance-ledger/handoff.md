# Balance ledger handoff

Implemented the approved autonomous management Balance. PR [#2477](https://github.com/pavelsur07/app-service-finance/pull/2477), base `master`, branch `feat/balance-ledger`. Verified code revision `4b9df397`; final handoff commit changes documentation only. Production has not been accessed or changed.

## Delivered

Two sides (asset/passive, equity inside passive), four article levels and separate accounts; exact current/historical amounts; opening, drafts, balanced posting, reversal and correction; optimistic revisions and durable request keys; archive/reference history; sequential close and separate reopen rights; owner state recovery; scoped permissions and audit. Reports include balance by date, account/article cards, movements, comparisons and paginated journal. Document validation preserves entered amounts. All financial changes are serialized company → book and checked against permitted historical balances.

No financial integrations, external adapters or speculative queues were added. Legacy Balance category/link tables are retained as a technical archive; the new ledger does not obtain values from Cash/Funds.

Main files: `site/src/Balance/`, `site/templates/balance*`, `site/assets/controllers/balance_lines_controller.js`, `site/migrations/Version20260914120000.php`, Balance tests/builders. Supporting changes are the Company fresh-rights/member-choice Facade queries, exact money formatter and explicit route-gate tests. Architecture contracts updated. User guide: [operations.md](operations.md).

## Verification

- Final isolated local full suite: **4942 tests, 28335 assertions, exit 0; 6 deprecations**, no errors or failures. Dedicated local PostgreSQL database and Redis prevent lock collisions with other development processes.
- Actual empty-database migration run:260 versions through `Version20260914120000`, green. Doctrine mapping validation green (`--skip-sync` deliberately excludes archival/composite-constraint synchronization claims).
- Full PHPStan, PHP CS check and strict-types gates green. Full unit gate2866tests15971assertions,4deprecations. Later focused checks cover final small review corrections.
- Vite production build and full ESLint green; scoped UI Kit23templates312classuses green. Browser desktop/mobile checks verify real compiled Stimulus, exact preview, adding/removing lines, no errors/overflow. Login itself is covered by BrowserKit functional tests, not by the rendered-artifact browser smoke.
- Code CI revision4b9df397: [all application checks green](https://github.com/pavelsur07/app-service-finance/actions/runs/34812849198), [frontend lint green](https://github.com/pavelsur07/app-service-finance/actions/runs/34812849195). Production migration/deploy jobs skipped. Final documentation-only commit may start another automatic check run; source code remains identical to this verified revision.

Earlier failures were not hidden: task-specific concurrency/gate regressions were fixed and verified. A later local API test hit a shared Redis lock collision (`external_api_failures-127.0.0.1`); its complete class passed9tests120assertions independently, and the final full run uses its own Redis. PHP style mismatches were corrected with the actual `.php-cs-fixer.php` configuration, with cache disabled for a stale cached file result.

## Reviews

Fresh full-task internal reviews: first B2/I6; second B0/I3; acceptance `INTERNAL_REVIEW_GREEN` B0/I0. Two of the second-round IMPORTANT findings were confirmed and fixed; the out-of-band cached-state corruption scenario was rejected as an application defect and recorded FOLLOW-UP, with the inductive state/journal invariant and all writer paths audited. Safe MINOR corrections verified locally.

Earlier Stage internal review found B1/I10 and fixed them. Total internal findings B3/I19 (18 IMPORTANT confirmed and fixed,1 reclassified FOLLOW-UP). External: B1/I2, all confirmed findings fixed. Three external invocations: findings; max-turn tool failure; corrected retry `REVIEW_GREEN`. Two final external MINOR display findings fixed without another round. No confirmed open BLOCKER/IMPORTANT. Details: [review-findings.md](review-findings.md).

## Limits and follow-ups

- Existing global UI Kit checker8965 violations and React mapping47 violations remain outside this task; new Balance templates pass the scoped checker. Vite also emits the existing missing ux-turbo package warning while building successfully.
- PHP tests report existing deprecations; no deprecation-clean claim.
- Proactive detection of out-of-band database corruption is future maintenance work. Normal commands preserve current-state/journal equality; closing checks integrity and the owner can explicitly rebuild states from validated history.
- Financial integrations, multi-currency revaluation and statutory accounting/reporting remain later tasks.

## Owner decision: migration and deploy

`Version20260914120000` is additive and preserves existing data. Its automatic down intentionally refuses deletion of financial history; rollback must retain the new tables and use the reviewed release procedure/forward correction. No destructive data migration is proposed.

Required approval under [release workflow](../../workflow/release.md): merge PR into master, run the production migration via manual workflow dispatch, verify schema, then dispatch deployment and perform allowed read-only acceptance. The ordinary merge/deploy approval does not itself authorize a production migration.

Ready reply: **run the migration and deploy #2477**.
