### Stage 2: DBAL queries and facade — DONE

**Risk:** MEDIUM
**Next action:** continue autonomously

#### What was done
- Added verification DBAL read queries for coverage, reconciliation, issues, and financial summary.
- Extended `IngestionFacade` with coverage, reconciliation, issues, and financial summary methods.
- Added a narrow legacy read method to `OzonTransactionTotalsCheckRepository` for latest company-period checksum.
- Added integration coverage for query aggregation and facade issue pagination.

#### Files changed
- `site/src/Ingestion/Infrastructure/Query/*Query.php` — new
- `site/src/Ingestion/Facade/IngestionFacade.php` — modified
- `site/src/Marketplace/Repository/OzonTransactionTotalsCheckRepository.php` — modified
- `site/tests/Integration/Ingestion/Infrastructure/Query/VerificationQueriesTest.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli ./vendor/bin/phpunit -c phpunit.xml --filter VerificationQueriesTest` — passed, 4 tests, 20 assertions; PHPUnit reported 3 deprecations without detail.

#### Risks / reviewer focus
- `financial-summary` reads company-level P&L snapshots; `shop_ref` is accepted but ignored because `pl_monthly_snapshots` has no shop dimension.
- Reconciliation filters canonical transactions by `shop_ref`, while legacy Ozon control is company-period scoped.
- Ozon control reads only `ozonTotals["total_minor"]`; missing/invalid value returns null.

#### Open questions
- none
