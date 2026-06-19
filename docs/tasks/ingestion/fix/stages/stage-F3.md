### Stage F3: Widgets and Views — DONE

**Risk:** MEDIUM
**Next action:** continue autonomously

#### What was done
- Added 4 smart widgets:
  - coverage heatmap
  - reconciliation summary
  - issues list
  - financial summary
- Added dumb views for the 4 screen bodies.
- Added loading, empty, error, and success states for each page.
- Added 500ms debounce before period/filter-driven API calls.
- Reused existing shared `Pagination` for issues.

#### Files changed
- `site/assets/react/ingestion-verification/widgets/*` — new
- `site/assets/react/ingestion-verification/views/*` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `cd site && npx vite build --configLoader runner --outDir /tmp/app-service-finance-vite-build --emptyOutDir` — passed.
- `rg -n "byType|byMonth|byCategory" site/assets/react/ingestion-verification site/assets/react/ingestion-verification-*.tsx` — no matches.

#### Risks / reviewer focus
- Coverage heatmap aggregates same-date/resource rows defensively because API fields are optional in generated types.

#### Open questions
- none
