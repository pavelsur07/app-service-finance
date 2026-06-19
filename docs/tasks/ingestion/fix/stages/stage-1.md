### Stage 1: DTO, formatter, validation exceptions — DONE

**Risk:** MEDIUM
**Next action:** continue autonomously

#### What was done
- Added verification response DTOs with explicit snake_case `toArray()` contracts.
- Added `IssueDescriptionFormatter`.
- Added period validation service and API exceptions for invalid period/range.
- Added unit tests for formatter and period validation.

#### Files changed
- `site/src/Ingestion/Application/DTO/*View.php` — new verification read DTOs
- `site/src/Ingestion/Application/Service/IssueDescriptionFormatter.php` — new
- `site/src/Ingestion/Application/Service/VerificationPeriodValidator.php` — new
- `site/src/Ingestion/Exception/InvalidPeriodException.php` — new
- `site/src/Ingestion/Exception/InvalidPeriodRangeException.php` — new
- `site/tests/Unit/Ingestion/Application/Service/*Test.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked: N/A, no data access yet
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli ./vendor/bin/phpunit -c phpunit.xml --testsuite=unit --filter 'IssueDescriptionFormatterTest|VerificationPeriodValidatorTest'` — passed, 10 tests

#### Risks / reviewer focus
- DTO `toArray()` methods define the public snake_case response shape used by upcoming controllers.

#### Open questions
- none
