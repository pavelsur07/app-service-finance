### Stage F1: Types and API Hooks — DONE

**Risk:** MEDIUM
**Next action:** continue autonomously

#### What was done
- Added generated OpenAPI type aliases for coverage, reconciliation, issues, and financial summary responses/query params.
- Added hooks over `useAbortableQuery` for the 4 verification endpoints.
- Reused the coverage endpoint as the shop-options source.
- Updated `httpJson` 422 handling to surface backend `error.message` when present.

#### Files changed
- `site/assets/react/ingestion-verification/types.ts` — new
- `site/assets/react/ingestion-verification/api/ingestionVerificationApi.ts` — new
- `site/assets/react/ingestion-verification/utils/date.ts` — new
- `site/assets/react/shared/http/client.ts` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `cd site && npx tsc --noEmit` — fails only on pre-existing non-ingestion files; new ingestion files are not reported.
- `rg -n "byType|byMonth|byCategory" site/assets/react/ingestion-verification site/assets/react/ingestion-verification-*.tsx` — no matches.

#### Risks / reviewer focus
- Shop options come from coverage because no standalone shop-options endpoint exists.

#### Open questions
- none
