### Stage F6: Menu and Finance Tabs — DONE

**Risk:** MEDIUM
**Next action:** STOP, owner review required

#### What was done
- Added the sidebar menu structure `Загрузка данных -> Финансы`.
- Added `/ingestion/verification` redirect to the coverage tab.
- Added `_finance_tabs.html.twig` with Tabler `nav nav-tabs`.
- Updated all 4 verification pages to include the finance tabs before the React mount point.
- Kept `_tabs.html.twig` as a compatibility include for `_finance_tabs.html.twig`.
- Extended the page functional test to cover redirect, sidebar link, tab count, active tab href, and active tab label.

#### Files changed
- `site/templates/partials/_sidebar.html.twig` — modified
- `site/templates/ingestion/verification/_finance_tabs.html.twig` — new
- `site/templates/ingestion/verification/_tabs.html.twig` — compatibility include
- `site/templates/ingestion/verification/{coverage,reconciliation,issues,financial-summary}.html.twig` — modified
- `site/src/Ingestion/Controller/Page/VerificationIndexPageController.php` — new
- `site/tests/Functional/Ingestion/Controller/VerificationPageControllerTest.php` — modified
- `docs/tasks/ingestion/fix/handoff.md` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli ./vendor/bin/phpunit -c phpunit.xml --filter VerificationPageControllerTest` — passed, 2 tests / 36 assertions; 3 PHPUnit deprecations.
- `docker compose run --rm site-php-cli php bin/console lint:twig templates/ingestion/verification templates/partials/_sidebar.html.twig` — passed.
- `docker compose run --rm site-php-cli php -l src/Ingestion/Controller/Page/VerificationIndexPageController.php` — passed.

#### Risks / reviewer focus
- The sidebar intentionally has one finance item only; the four tabs are rendered inside the finance verification pages.

#### Open questions
- none
