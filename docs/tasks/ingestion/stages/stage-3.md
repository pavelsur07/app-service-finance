### Stage 3: Block 5 canon schema — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Added canonical Ingestion enums: transaction type, direction, normalization issue kind, and connector capability.
- Added `Money` value object with same-currency guards. After Stage 6 review it was moved from Ingestion to `App\Shared\Domain\ValueObject`; after the signed-money correction it supports positive, negative, and zero minor units.
- Added tenant-owned canon entities: `FinancialTransaction`, `Counterparty`, and `NormalizationIssue`.
- Added repositories with explicit `companyId` read methods and iterable period reads for future P&L rebuilds.
- Added additive PostgreSQL migration for the three canon tables and indexes.
- Added unit and integration tests for invariants, stale-version protection, repository tenant boundaries, and issue resolution.
- Updated `ARCHITECTURE.md` with the canonical finance layer.

#### Files changed
- `ARCHITECTURE.md` — documented canonical Ingestion finance layer
- `site/src/Shared/Domain/ValueObject/Money.php` — shared value object
- `site/src/Ingestion/Entity/{FinancialTransaction,Counterparty,NormalizationIssue}.php` — new entities
- `site/src/Ingestion/Enum/{TransactionType,TransactionDirection,NormalizationIssueKind,Capability}.php` — new enums
- `site/src/Shared/Domain/Exception/MoneyMismatchException.php` and `site/src/Ingestion/Exception/StaleTransactionUpdateException.php` — new exceptions
- `site/src/Ingestion/Repository/{FinancialTransactionRepository,CounterpartyRepository,NormalizationIssueRepository}.php` — new repositories
- `site/migrations/Version20260618130000.php` — new additive migration
- `site/tests/Unit/Ingestion/*` — new value object/entity tests
- `site/tests/Integration/Ingestion/Repository/*` — new repository tests
- `docs/tasks/ingestion/stages/stage-3.md` — new stage report

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli php bin/console doctrine:migrations:migrate --no-interaction --env=test` — OK, test DB migrated to `Version20260618130000`
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'MoneyTest|FinancialTransactionTest|NormalizationIssueTest|RunSyncChunkMessageTest|IngestCursorTest|SyncJobTest|SyncJobStatusTest'` — OK, 51 tests / 94 assertions
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite integration --filter 'FinancialTransactionRepositoryTest|CounterpartyRepositoryTest|NormalizationIssueRepositoryTest|IngestCursorRepositoryTest|SyncJobRepositoryTest|SyncFacadeTest'` — OK, 11 tests / 41 assertions, PHPUnit deprecation notices present
- scoped `php-cs-fixer --dry-run` on Stage 3 files — OK
- `doctrine:migrations:execute DoctrineMigrations\Version20260618130000 --down --dry-run --env=test` — OK
- `doctrine:migrations:execute DoctrineMigrations\Version20260618130000 --up --dry-run --env=test` — OK
- `doctrine:schema:validate --skip-sync --env=test` — OK, mapping valid
- `lint:container --env=test` — OK
- Full `doctrine:schema:validate --env=test` — failed due broader project schema drift; dumped SQL shows many pre-existing diffs plus timestamp precision diffs across existing Ingestion tables

#### Risks / reviewer focus
- Migration creates three new tables only; no existing tables are altered or dropped.
- `FinancialTransaction.amountMinor` is stored as string internally because Doctrine hydrates `BIGINT` as string; the public getter remains `int`.
- `Money` is a signed base value object; any nonnegative-only rule must live in a concrete business scenario. `TransactionDirection` remains the normalized flow classification.
- Normalization pipeline/actions/handlers are not implemented in this stage.

#### Open questions
- none
