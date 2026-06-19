### Stage 4: Tenant-leak and contract hardening — DONE

**Risk:** HIGH
**Next action:** continue autonomously per owner request to implement the approved plan

#### What was done
- Added functional tenant-leak coverage for all 4 verification endpoints.
- Verified issue responses do not expose `details`, `raw_record_id`, or `operation_group_id`.
- Re-ran the verification functional class after adding tenant fixtures.

#### Files changed
- `site/tests/Functional/Ingestion/Controller/VerificationApiControllerTest.php` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli ./vendor/bin/phpunit -c phpunit.xml --filter VerificationApiControllerTest` — passed, 3 tests, 35 assertions; PHPUnit reported 3 deprecations without detail.

#### Risks / reviewer focus
- Tenant isolation for the new endpoints depends on explicit `company_id` conditions in DBAL queries, not only on Doctrine filters.

#### Open questions
- none
