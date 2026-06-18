### Stage 4: Block 5 normalization pipeline — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Added `SourceConnectorInterface` / `SourceMapperInterface`, connector and mapper registries, pull/push/shop/mapped DTOs.
- Added normalization commands/actions for raw normalization, canonical `FinancialTransaction` upsert, and `NormalizationIssue` recording.
- Added `NormalizeRawRecordMessage`, `RunSyncChunkHandler`, `NormalizeRawRecordHandler`, and routing to `ingest_normalize`.
- Added `NormalizationCompletedEvent` / `AffectedPeriod` and dispatch after successful raw normalization flush.
- Added `IngestionFacade::getTransactions()` and `countOpenIssues()` for the next P&L stage.
- Extended `IngestRawRecord` with `markNormalizationDone()` / `markNormalizationFailed()` and added a task-scope repository lookup.
- Added test-only `FakeConnector` / `FakeMapper` fixtures tagged only in `services_test.yaml`; prod container does not include them.
- Updated `ARCHITECTURE.md` with the connector/normalization pipeline and facade contract.

#### Files changed
- `site/src/Ingestion/Domain/Contract/SourceConnectorInterface.php` — new
- `site/src/Ingestion/Domain/Contract/SourceMapperInterface.php` — new
- `site/src/Ingestion/Domain/Service/ConnectorRegistry.php` — new
- `site/src/Ingestion/Domain/Service/MapperRegistry.php` — new
- `site/src/Ingestion/Domain/Event/AffectedPeriod.php` — new
- `site/src/Ingestion/Domain/Event/NormalizationCompletedEvent.php` — new
- `site/src/Ingestion/Application/DTO/*` — new connector/mapper/upsert DTOs
- `site/src/Ingestion/Application/Command/NormalizeRawRecordCommand.php` — new
- `site/src/Ingestion/Application/Command/UpsertFinancialTransactionCommand.php` — new
- `site/src/Ingestion/Application/Command/RecordNormalizationIssueCommand.php` — new
- `site/src/Ingestion/Application/Action/NormalizeRawRecordAction.php` — new
- `site/src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php` — new
- `site/src/Ingestion/Application/Action/RecordNormalizationIssueAction.php` — new
- `site/src/Ingestion/Exception/ConnectorAuthException.php` — new
- `site/src/Ingestion/Exception/ConnectorNotFoundException.php` — new
- `site/src/Ingestion/Exception/ConnectorTransientException.php` — new
- `site/src/Ingestion/Exception/MapperNotFoundException.php` — new
- `site/src/Ingestion/Exception/UnsupportedCapabilityException.php` — new
- `site/src/Ingestion/Message/NormalizeRawRecordMessage.php` — new
- `site/src/Ingestion/MessageHandler/RunSyncChunkHandler.php` — new
- `site/src/Ingestion/MessageHandler/NormalizeRawRecordHandler.php` — new
- `site/src/Ingestion/Facade/IngestionFacade.php` — new
- `site/src/Ingestion/Entity/IngestRawRecord.php` — modified
- `site/src/Ingestion/Repository/IngestRawRecordRepository.php` — modified
- `site/config/packages/messenger.yaml` — modified
- `site/config/packages/test/messenger.yaml` — modified
- `site/config/services.yaml` — modified
- `site/config/services_test.yaml` — modified
- `site/tests/Integration/Ingestion/Fixtures/*` — new fake connector/mapper and event recorder
- `site/tests/Integration/Ingestion/Application/*` — new normalization/upsert tests
- `site/tests/Integration/Ingestion/MessageHandler/RunSyncChunkHandlerTest.php` — new
- `site/tests/Unit/Ingestion/Domain/Service/*` — new registry tests
- `ARCHITECTURE.md` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `make codex-test-unit-filter FILTER='Ingestion'` — failed at `codex-prepare`: host `php` not found in Codex PATH; switched to Docker project commands.
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter Ingestion` — OK, 65 tests / 143 assertions.
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter Ingestion --display-deprecations --display-phpunit-deprecations` — OK, 33 tests / 150 assertions; 2 PHPUnit runner deprecations from existing non-Ingestion doc-comment metadata tests.
- `make site-test-unit` — OK, 1014 tests / 6289 assertions; 1 existing warning in `StorageServiceTest`, 1 existing deprecation in `XlsxReaderServiceTest`.
- `make site-cs-check` — failed on 660 pre-existing project-wide style diffs outside this stage.
- `docker compose run --rm site-php-cli php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.stage4.cache <stage-4-files>` — OK.
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK.
- `docker compose run --rm site-php-cli php bin/console lint:container --env=prod` — OK; existing Symfony/Doctrine deprecation logs printed.
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK.
- `docker compose run --rm site-php-cli sh -lc 'php bin/console debug:container --env=prod --format=json 2>/dev/null | grep -F FakeConnector >/dev/null && exit 1 || exit 0'` — OK, fake connector absent from prod container.

#### Risks / reviewer focus
- `RunSyncChunkHandler` introduces the central fetch-to-normalize pipeline and new `NormalizeRawRecordMessage` routing; review retry/failure semantics carefully.
- No new idempotency table was added because the project only documents `IdempotentHandlerTrait` as a pattern and has no implementation. Idempotency relies on sync/raw statuses and canonical natural-key upsert.
- `FakeConnector` / `FakeMapper` are implemented as test fixtures instead of production `src` classes to keep prod container clean.
- Stage 4 has no database migration.

#### Open questions
- none
