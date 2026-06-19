### Stage F4: Pages, Twig, and Vite Entries — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Added 4 flat Vite entries:
  - `ingestion_verification_coverage_page`
  - `ingestion_verification_reconciliation_page`
  - `ingestion_verification_issues_page`
  - `ingestion_verification_financial_summary_page`
- Added 4 Twig pages with unique mount ids and matching entry keys.
- Added a small Twig tab partial for navigation between verification pages.
- Added 4 thin Symfony page controllers guarded by `ROLE_COMPANY_USER`.

#### Files changed
- `site/assets/react/ingestion-verification-coverage-page.tsx` — new
- `site/assets/react/ingestion-verification-reconciliation-page.tsx` — new
- `site/assets/react/ingestion-verification-issues-page.tsx` — new
- `site/assets/react/ingestion-verification-financial-summary-page.tsx` — new
- `site/vite.config.js` — modified
- `site/templates/ingestion/verification/*` — new
- `site/src/Ingestion/Controller/Page/*PageController.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `docker compose run --rm site-php-cli ./vendor/bin/phpunit -c phpunit.xml --filter VerificationPageControllerTest` — passed, 2 tests / 14 assertions, 3 PHPUnit deprecations.
- `cd site && npx vite build --configLoader runner --outDir /tmp/app-service-finance-vite-build --emptyOutDir` — passed.

#### Risks / reviewer focus
- New Vite entries and Twig mount contracts are high-risk by project rules.
- Normal `npm run build` is blocked in this workspace by root-owned `site/node_modules/.vite-temp` and `site/public/build`, so bundling was verified to a temporary outDir.

#### Open questions
- none
