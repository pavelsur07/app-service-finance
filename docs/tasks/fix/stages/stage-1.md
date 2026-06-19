### Stage 1: FIX-01 PLDirtyPeriod Verification — DONE

**Risk:** LOW
**Next action:** continue autonomously

#### What was done
- Verified `PLDirtyPeriod`, enum classes, and repository already live in `App\Ingestion`.
- Verified `PLDirtyPeriod` implements `TenantOwnedInterface`.
- Verified no `App\Finance\*PLDirtyPeriod` imports remain in `site/src`.

#### Files changed
- none for implementation

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `rg "Finance\\\\.*PLDirtyPeriod" site/src || true` — OK, no matches

#### Risks / reviewer focus
- none

#### Open questions
- none
