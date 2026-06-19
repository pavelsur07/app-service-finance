### Stage F2: Shared UI Components — DONE

**Risk:** MEDIUM
**Next action:** continue autonomously

#### What was done
- Added feature-scoped shared controls and states:
  - `ShopSelector`
  - `PeriodPicker`
  - `MoneyCell`
  - `DeltaCell`
  - `StatusBadge`
  - `EmptyState`
  - `LoadingState`
  - `ErrorState`
- `ShopSelector` persists `ingestion.selected_shop` in localStorage.
- `ErrorState` maps network/5xx style failures to the required retry guidance.

#### Files changed
- `site/assets/react/ingestion-verification/components/*` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- Covered by Vite temporary build and TypeScript check status in Stage F5.

#### Risks / reviewer focus
- localStorage is best-effort and intentionally ignored if unavailable.

#### Open questions
- none
