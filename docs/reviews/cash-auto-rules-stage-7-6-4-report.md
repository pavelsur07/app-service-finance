### Stage 7.6.4: Import cutover — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required before PR/merge or any production acceptance

#### What was done

- Connected file import, 1C client-bank import, and bank-provider import writers to the existing `CashTransactionResponsibilityCenterResolver`.
- Each import resolves the company `PROJECT_GENERAL × CFO_GENERAL` pair once per import/company context.
- New imported `CashTransaction` rows receive both:
  - `projectDirection = PROJECT_GENERAL`
  - `responsibilityCenterId = CFO_GENERAL`
- 1C overwrite keeps the existing transaction Project×ЦФО unchanged.
- Preview mode remains non-persistent.
- Duplicate detection, import source/external id semantics, batching, logging counters, cursor movement, and balance recalculation were not changed.
- Added focused unit/integration coverage for file, 1C, and bank import paths.
- Updated architecture documentation.

#### Files changed

- `site/src/Cash/Service/Import/File/CashFileImportService.php` — resolves and applies system pair to newly persisted file-import transactions.
- `site/src/Cash/Service/Import/ClientBank1CImportService.php` — resolves and applies system pair only to new 1C transactions; overwrite preserves existing pair.
- `site/src/Cash/Service/Import/Bank/BankImportService.php` — resolves and applies system pair to newly persisted bank-provider transactions.
- `site/tests/Unit/Cash/Service/Import/File/CashFileImportServiceTest.php` — unit coverage for file-import pair assignment.
- `site/tests/Unit/Cash/Service/Import/Bank/BankImportServiceTest.php` — unit coverage for bank-provider pair assignment.
- `site/tests/Integration/Cash/Service/Import/ClientBank1CImportServiceTestCase.php` — system-pair fixture for 1C import tests.
- `site/tests/Integration/Cash/Service/Import/ClientBank1CImportServiceSoftDeleteTest.php` — system-pair fixture for soft-delete reimport coverage.
- `site/tests/Integration/Cash/Service/Import/File/CashFileImportWorkerStorageTest.php` — system-pair fixture and assertion for worker file import.
- `site/tests/Integration/Cash/Service/Import/IdempotencyTest.php` — assertions for new 1C pair assignment and overwrite preservation.
- `ARCHITECTURE.md` — Stage 7.6.4 import writer contract.

#### Self-review

- [x] Scope compliance: import writers only; no history/backfill/recalc command
- [x] Project patterns followed: reused existing resolver/facade contract
- [x] No forbidden actions: no migration, queue config, production command, dependency, or public endpoint
- [x] Security/company access checked: resolver uses company-scoped system pair lookup
- [x] Tests/checks run
- [x] Documentation updated

#### Checks

- Container PHP lint for touched PHP files — passed.
- Targeted file/bank import unit tests — passed: 3 tests, 15 assertions.
- Targeted 1C import integration tests — passed: 4 tests, 44 assertions.
- Full Cash import integration folder — passed: 10 tests, 68 assertions.
- Symfony container lint for `test` — passed.
- `make site-test-unit` — passed: 1516 tests, 8912 assertions.
- `make site-test-integration` — passed: 730 tests, 3556 assertions.
- Scoped PHP CS Fixer dry-run for touched files — passed: 0 files fixable.
- Full `composer cs:check` — failed on existing repo-wide style drift: 605 unrelated files reported fixable; touched files were checked separately with scoped PHP CS Fixer and passed.
- Doctrine schema validate with `--skip-sync` for `test` — passed.
- `git diff --check` — passed.

#### Risks / reviewer focus

- If a company is missing the system Project×ЦФО pair, imports now fail closed before persisting new transactions.
- Before PR merge/deploy, run a read-only production preflight confirming every company has an active `PROJECT_GENERAL × CFO_GENERAL` pair; otherwise scheduled/import flows for unprovisioned companies will fail closed.
- 1C overwrite intentionally does not rewrite existing Project×ЦФО.
- File import resolves the system pair before reading rows, so a missing pair fails the job before partial row persistence.
- Bank import resolves the system pair before provider account/transaction loops, so a missing pair prevents provider processing.

#### Open questions

- none.
