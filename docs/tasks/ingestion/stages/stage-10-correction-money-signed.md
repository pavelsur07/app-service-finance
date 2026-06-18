### Stage 10: Signed Money value object correction — DONE

**Risk:** MEDIUM
**Next action:** STOP, owner review required

#### What was done
- Updated shared `Money` to represent signed minor-unit values: positive, negative, or zero.
- Removed the universal `amountMinor >= 0` invariant from `Money`.
- Allowed `subtract()` to return a negative result.
- Fixed `negate()` so it returns the opposite sign.
- Added `compareTo()` and kept same-currency guards for arithmetic/comparison operations through `MoneyMismatchException`.
- Kept currency validation as uppercase ISO-like 3-letter code.
- Documented that nonnegative-only money rules must live in concrete business scenarios, not in the base `Money` value object.

#### Files changed
- `site/src/Shared/Domain/ValueObject/Money.php` — modified
- `site/tests/Unit/Shared/Domain/ValueObject/MoneyTest.php` — modified
- `docs/tasks/ingestion/TASK-05-connector-canon.md` — modified
- `ARCHITECTURE.md` — modified
- `docs/tasks/ingestion/stages/stage-3.md` — modified
- `docs/tasks/ingestion/SUMMARY-blocks-4-7.md` — modified
- `docs/tasks/ingestion/handoff.md` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

#### Checks
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'MoneyTest|FinancialTransactionTest|Ozon|Ingestion'` — OK, 389 tests / 3784 assertions
- `make site-test-unit` — OK, 1070 tests / 6438 assertions, existing 1 warning and 1 deprecation remain
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'Ingestion|MarkPnlPeriodDirtyAction|RebuildPnlPeriodAction|RebuildDirtyPnlPeriodsCommand' --display-phpunit-deprecations` — OK, 42 tests / 192 assertions, existing 2 PHPUnit runner deprecations remain
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK, mapping valid; DB sync intentionally skipped
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.money-signed.cache --path-mode=intersection ...` — OK
- `git diff --check` — OK

#### Risks / reviewer focus
- Existing Ozon mappers still normalize Ozon transaction components with absolute minor amounts and explicit `TransactionDirection`; this correction only makes the base value object capable of signed money.
- If a specific domain scenario requires nonnegative money, it should add a scenario-level assertion or a dedicated type such as `PositiveMoney` / `NonNegativeMoney`.

#### Open questions
- none
