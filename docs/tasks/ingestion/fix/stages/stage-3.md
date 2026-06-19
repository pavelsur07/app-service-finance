### Stage 3: Controllers, OpenAPI, exception listener — DONE

**Risk:** HIGH
**Next action:** continue autonomously per owner request to implement the approved plan

#### What was done
- Added 4 public verification endpoints under `/api/ingestion/verification/*`.
- Added OpenAPI attributes for request parameters and response payloads.
- Added `IngestionExceptionListener` for unified 422 Ingestion API errors.
- Added request logging with endpoint, companyId, and request timestamp.
- Added functional smoke coverage and listener unit tests.

#### Files changed
- `site/src/Ingestion/Controller/Api/Verification/*Controller.php` — new
- `site/src/Ingestion/Infrastructure/Http/IngestionExceptionListener.php` — new
- `site/tests/Functional/Ingestion/Controller/VerificationApiControllerTest.php` — new
- `site/tests/Unit/Ingestion/Infrastructure/Http/IngestionExceptionListenerTest.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli ./vendor/bin/phpunit -c phpunit.xml --filter VerificationApiControllerTest` — passed, 2 tests, 24 assertions; PHPUnit reported 3 deprecations without detail.
- `docker compose run --rm site-php-cli ./vendor/bin/phpunit -c phpunit.xml --testsuite=unit --filter IngestionExceptionListenerTest` — passed, 2 tests, 4 assertions.

#### Risks / reviewer focus
- This stage introduces public API routes and response contracts.
- Controller validation maps missing/invalid required period params to the planned 422 error format.

#### Open questions
- none
