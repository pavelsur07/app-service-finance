### Stage 6: Block 6 Ozon connector — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Added the Ozon Seller source adapter boundary for Ingestion:
  - `LegacyOzonClientAdapter`
  - Ozon credential provider interface/implementation
  - Ozon raw page and shop descriptor DTOs
- Added `OzonSellerReportConnector` for:
  - `ozon_seller_daily_report`
  - `ozon_seller_realization`
  - `CAN_DISCOVER_SHOPS`
  - `CAN_PULL`
  - no push support
- Added Ozon mappers:
  - `OzonSellerReportMapper`
  - `OzonRealizationMapper`
  - shared component mapper for sale, refund, commission, logistics, last mile, fee, acquiring, and other fallback rows
- Added tenant-aware Ozon control sums through `RawRecordAwareControlSumMapperInterface`, because Ozon `operationGroupId` depends on `companyId`.
- Registered production Ozon connector/mapper services and test-only fake Ozon adapter.
- Moved the generic Stage 4 fake connector/mapper from `OZON` to `WILDBERRIES` in test scope to avoid duplicate Ozon connector registration.
- Added anonymized Ozon fixtures and tests for:
  - adapter error classification
  - connector pull/discover/push contract
  - daily report mapping
  - realization mapping
  - DI registry tags
  - local end-to-end sync chunk → raw storage → normalization → canonical transactions
  - realization overwriting daily preliminary values through matching natural keys
- Added `docs/ingestion/ozon-mapping.md` and updated `ARCHITECTURE.md`.

#### Files changed
- `ARCHITECTURE.md` — modified
- `docs/ingestion/ozon-mapping.md` — new
- `site/config/services.yaml` — modified
- `site/config/services_test.yaml` — modified
- `site/src/Ingestion/Application/Action/NormalizeRawRecordAction.php` — modified
- `site/src/Ingestion/Domain/Contract/RawRecordAwareControlSumMapperInterface.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonMoneyParser.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonOperationKey.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonRealizationMapper.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonResourceType.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonSellerReportConnector.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonSellerReportMapper.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonTransactionComponentMapper.php` — new
- `site/src/Ingestion/Infrastructure/Api/Ozon/CredentialFacadeOzonCredentialProvider.php` — new
- `site/src/Ingestion/Infrastructure/Api/Ozon/LegacyOzonClientAdapter.php` — new
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonClientAdapterInterface.php` — new
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonCredentialProviderInterface.php` — new
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonRawPage.php` — new
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonShopDescriptor.php` — new
- `site/tests/Fixtures/Ingestion/Ozon/transaction_list_with_sale_and_commission.json` — new
- `site/tests/Fixtures/Ingestion/Ozon/realization_february_2026.json` — new
- `site/tests/Integration/Ingestion/Application/NormalizeRawRecordActionTest.php` — modified
- `site/tests/Integration/Ingestion/Application/Source/Ozon/OzonIngestionFlowTest.php` — new
- `site/tests/Integration/Ingestion/Application/Source/Ozon/OzonIngestionRegistryTest.php` — new
- `site/tests/Integration/Ingestion/Fixtures/FakeConnector.php` — modified
- `site/tests/Integration/Ingestion/Fixtures/FakeMapper.php` — modified
- `site/tests/Integration/Ingestion/Fixtures/FakeOzonClientAdapter.php` — new
- `site/tests/Integration/Ingestion/MessageHandler/RunSyncChunkHandlerTest.php` — modified
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonSellerReportConnectorTest.php` — new
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonSellerReportMapperTest.php` — new
- `site/tests/Unit/Ingestion/Infrastructure/Api/Ozon/LegacyOzonClientAdapterTest.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'Ozon|Ingestion'` — OK, 347 tests / 3688 assertions
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'Ingestion'` — OK, 36 tests / 169 assertions, 2 PHPUnit runner deprecations from existing non-Ingestion-selected test metadata:
  - `App\Tests\Integration\Marketplace\CloseMonthStageActionPreliminaryTest::testCloseCostsThrowsWhenEntriesAreEmptyEvenIfPreflightAllowsClose`
  - `App\Tests\Integration\MarketplaceAds\AdLoadJobRepositoryTest::testIncrementRejectsNonPositiveDelta`
- `make site-test-unit` — OK, 1024 tests / 6336 assertions, existing 1 warning and 1 deprecation remain in the project unit suite
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.stage6.cache --path-mode=intersection ...` — OK, 0 fixable Stage 6 files
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli php bin/console lint:container --env=prod` — OK, with existing Symfony/Doctrine deprecation logs
- `docker compose run --rm site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK, mapping valid; DB sync intentionally skipped
- `git diff --check` — OK
- `make site-cs-check` — FAILED on existing project-wide style drift: 659 of 1691 files fixable, outside Stage 6 scope

#### Risks / reviewer focus
- `LegacyOzonClientAdapter` performs live Ozon API calls only in production runtime; all tests use `MockHttpClient` or `FakeOzonClientAdapter`. No live external API call was made during this stage.
- The task specified `externalId = ozon:operation:{operation_id}`. Stage 6 uses `ozon:operation:{operation_id}:{component}` for canonical transaction external ids because current `FinancialTransaction` uniqueness is `(companyId, source, externalId, type)`, and one Ozon operation can contain multiple components with the same `TransactionType`. Daily and realization mappers use the same component ids, so overwrite semantics are preserved.
- Ozon realization payloads can vary by account/API version. The mapper supports the fields present in the fixture plus common legacy field aliases; reviewer should focus on expanding fixture coverage before enabling shadow production imports.
- `RawRecordAwareControlSumMapperInterface` is a narrow extension to the mapper contract. It avoids weakening tenant-aware `operationGroupId` generation while keeping existing mappers backward-compatible.

#### Open questions
- none

---

### Stage 6 Review Fix: Shared Money value object — DONE

**Risk:** LOW
**Next action:** continue to Stage 7 after owner approval for migration work

#### What was done
- Moved `Money` from `App\Ingestion\Domain\ValueObject` to `App\Shared\Domain\ValueObject`.
- Added `App\Shared\Domain\Exception\MoneyMismatchException` so the shared value object does not depend on the Ingestion module.
- Updated Ingestion DTO/entity/mapper imports and tests to use the shared `Money`.
- Moved `MoneyTest` to the Shared unit-test namespace.
- Updated TASK-05 and Stage 3 documentation references.

#### Files changed
- `site/src/Shared/Domain/ValueObject/Money.php` — moved/modified
- `site/src/Shared/Domain/Exception/MoneyMismatchException.php` — new
- `site/src/Ingestion/Application/DTO/MappedTransaction.php` — modified
- `site/src/Ingestion/Application/Source/Ozon/OzonTransactionComponentMapper.php` — modified
- `site/src/Ingestion/Entity/FinancialTransaction.php` — modified
- `site/tests/Unit/Shared/Domain/ValueObject/MoneyTest.php` — moved/modified
- Ingestion tests using `Money` — imports updated
- `docs/tasks/ingestion/TASK-05-connector-canon.md` — modified
- `docs/tasks/ingestion/stages/stage-3.md` — modified
- `docs/tasks/ingestion/stages/stage-6.md` — modified

#### Checks
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'MoneyTest|FinancialTransactionTest|Ozon|Ingestion'` — OK, 351 tests / 3720 assertions
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli php bin/phpunit --testsuite integration --filter 'Ingestion'` — OK, 36 tests / 169 assertions, existing 2 PHPUnit runner deprecations remain
- `docker compose run --rm site-php-cli php bin/console lint:container --env=test` — OK
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.money-shared.cache --path-mode=intersection ...` — OK, 0 fixable files
- `git diff --check` — OK

#### Risks / reviewer focus
- The old `App\Ingestion\Exception\MoneyMismatchException` class is left untouched for now to avoid an extra deletion in this review fix; runtime code now throws the Shared exception.

#### Open questions
- none

---

### Stage 6 Extension: Ozon fixture coverage — DONE

**Risk:** LOW
**Next action:** continue to Stage 7 after owner approval for migration work

#### What was done
- Added anonymized daily Ozon fixture for `ClientReturnAgentOperation` with:
  - refund amount
  - returned commission
  - return delivery
  - `services[]` logistics and fee components
- Added anonymized daily Ozon fixture without `operation_id` to lock fallback natural-key behavior.
- Extended mapper contract tests for:
  - refund decomposition
  - positive returned commission direction
  - `services[]` component ids
  - fallback `ozon:fallback:{posting}:{sku}:{date}:{component}` external ids
  - fallback control sum group consistency
- Extended adapter contract tests for:
  - realization rows/header metadata
  - 5xx classification as `ConnectorTransientException`
  - response body not being logged in adapter log context

#### Files changed
- `site/tests/Fixtures/Ingestion/Ozon/transaction_list_with_refund_and_services.json` — new
- `site/tests/Fixtures/Ingestion/Ozon/transaction_list_without_operation_id.json` — new
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonSellerReportMapperTest.php` — modified
- `site/tests/Unit/Ingestion/Infrastructure/Api/Ozon/LegacyOzonClientAdapterTest.php` — modified
- `docs/tasks/ingestion/stages/stage-6.md` — modified

#### Checks
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite unit --filter 'Ozon'` — OK, 286 tests / 3577 assertions
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --cache-file=var/cache/.php-cs-fixer.stage6-expand.cache --path-mode=intersection tests/Unit/Ingestion/Application/Source/Ozon/OzonSellerReportMapperTest.php tests/Unit/Ingestion/Infrastructure/Api/Ozon/LegacyOzonClientAdapterTest.php` — OK, 0 files changed

#### Risks / reviewer focus
- This extension changes tests/fixtures only. Production Ozon code, DI, database schema, and legacy Marketplace code were not changed.

#### Open questions
- none
