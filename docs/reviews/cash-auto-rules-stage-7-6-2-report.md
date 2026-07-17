### Stage 7.6.2: Core Cash create/update and CashFacade pair contract — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done

- Added optional scalar `responsibilityCenterId` to `CashTransactionDTO` and the end of `CreateCashTransactionCommand`.
- Mapped the new command field through `CashFacade` without changing duplicate lookup behavior.
- Connected `CashTransactionService::add()` to the existing `CashTransactionResponsibilityCenterResolver`:
  - `null × null` resolves to `PROJECT_GENERAL × CFO_GENERAL`;
  - explicit `project × ЦФО` is persisted only when active, same-company, and allowed;
  - partial/malformed/unavailable pairs fail before persist.
- Connected `CashTransactionService::update()` to the same resolver:
  - unchanged stored tuples are preserved;
  - changed tuples are validated and written atomically.
- Preserved existing manual edit behavior by carrying the stored `responsibilityCenterId` into the DTO until Stage 7.6.3 adds the visible UI field.
- Fixed self-review P1 before merge: the existing manual Cash form already allowed selecting a project, so it now includes the matching scalar ЦФО field to avoid a project-only submit regression. The form also includes the currently stored archived ЦФО choice so unrelated edits preserve historical tuples.
- Updated focused Cash, facade, balance, and Telegram tests to include the required company system pair in manually seeded company graphs.
- Documented the Stage 7.9.3 acceptance closure and the Stage 7.9.4 transition gate.

#### Files changed

- `site/src/Cash/DTO/CashTransactionDTO.php` — optional scalar ЦФО input.
- `site/src/Cash/Application/DTO/CreateCashTransactionCommand.php` — optional scalar ЦФО input at the end of the command.
- `site/src/Cash/Facade/CashFacade.php` — maps the command field after duplicate pre-check remains in place.
- `site/src/Cash/Controller/Transaction/CashTransactionController.php` — preserves current ЦФО on edit DTO.
- `site/src/Cash/Service/Transaction/CashTransactionService.php` — create/update pair resolution through the existing resolver.
- `site/src/Cash/Form/Transaction/CashTransactionType.php` and `site/templates/transaction/_form.html.twig` — minimal scalar ЦФО field on the existing manual form.
- `site/tests/Functional/Cash/Controller/CashTransactionRouteRequirementTest.php` — manual create regression coverage for project × ЦФО.
- `site/tests/Integration/Cash/Service/Transaction/CashTransactionServiceTest.php` — create/update pair contract coverage.
- `site/tests/Integration/Cash/Facade/CashFacadeTest.php` — facade default pair and duplicate preservation coverage.
- `site/tests/Integration/Cash/Service/Accounts/AccountBalanceServiceTransactionFlowTest.php` — updated fixture for required system pair.
- `site/tests/Integration/Telegram/CreateTelegramCashTransactionActionTest.php` — updated fixture for required system pair.
- `site/tests/Functional/Telegram/TelegramWebhookCashTransactionTest.php` — updated fixture for required system pair.
- `ARCHITECTURE.md` and Stage docs — contract/status.

#### Self-review

- [x] Scope compliance: Stage 7.6.2 plus the minimal manual form field required to avoid a Stage 7.6.2 regression; no import cutover
- [x] Project patterns followed: reused existing resolver/facade/service paths
- [x] No forbidden actions: no migration, production write, queue config, dependency, history run, or recalculation command
- [x] Security/company access checked: resolver validates company-scoped active allowed pairs through Company facade
- [x] Tests/checks run
- [x] Documentation updated

#### Checks

- `php -l` for touched PHP files — passed.
- Focused Cash service/facade/resolver/Telegram/balance integration tests — passed: 20 tests, 97 assertions.
- Focused Cash manual form and Telegram functional tests — passed: 9 tests, 40 assertions.
- Full integration suite — passed: 730 tests, 3547 assertions.
- PHP CS Fixer dry-run for touched PHP files — passed: 0 files fixable.
- Twig lint for `templates/transaction/_form.html.twig` — passed.
- Symfony container lint for `test` — passed.
- Doctrine mapping validate with `--skip-sync` for `test` — passed.
- `make site-test-unit` — passed: 1514 tests, 8898 assertions.
- `git diff --check` — passed.

#### Risks / reviewer focus

- Manual create with a selected project now has a visible ЦФО field and persists an allowed pair. Empty project/ЦФО create still stores the system pair.
- Import services are intentionally not cut over in this stage; Stage 7.6.4 remains gated.
- Existing transactions are not backfilled or repaired.

#### Open questions

- none.
