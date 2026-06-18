### Stage 4: FIX-04 Listing Resolver — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required before deployment/migration

#### What was done
- Added nullable listing attribution fields to `FinancialTransaction`.
- Extended upsert command/action to persist listing attribution atomically.
- Added listing resolver registry, Ozon resolver, and WB warning-only stub.
- Added `MarketplaceListingFacade` and worker-safe Marketplace repository methods.
- Added migration for transaction listing columns and indexes.

#### Files changed
- `site/src/Ingestion/Entity/FinancialTransaction.php` — modified
- `site/src/Ingestion/Application/Command/UpsertFinancialTransactionCommand.php` — modified
- `site/src/Ingestion/Application/Action/UpsertFinancialTransactionAction.php` — modified
- `site/src/Ingestion/Application/Service/ListingResolverRegistry.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonListingResolver.php` — new
- `site/src/Ingestion/Application/Source/Wildberries/WbListingResolver.php` — new
- `site/src/Ingestion/Facade/MarketplaceListingFacade.php` — new
- `site/src/Marketplace/Repository/*` — modified for worker-safe lookup
- `site/migrations/Version20260619130000.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

#### Checks
- focused integration — OK, 12 tests / 69 assertions
- broader Ingestion/P&L integration — OK, 58 tests / 262 assertions
- `make site-test-unit` — OK, 1070 tests / 6442 assertions
- `git diff --check` — OK

#### Risks / reviewer focus
- WB listing resolver intentionally returns `null` until WB ingestion mapping is defined.

#### Open questions
- none
