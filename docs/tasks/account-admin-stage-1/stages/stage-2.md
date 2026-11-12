# Stage 2: user, companies and permissions — DONE

Risk HIGH-LOCAL. Base `137cc1173f769904960cb9773da6db5efabb9b8c`.

Account/admin session DTOs and endpoints, current-user company discovery with
DB pagination and deduplication, CSRF-protected company selection. Existing
CompanyFacade access policy reused; no new financial rules or migrations.
Account OpenAPI area, shared generated types and existing schema synchronized.

RED: eight functional tests returned 404 before implementation. GREEN: sequential
module suite 48 tests / 227 assertions. Scoped PHPStan and CS clean; both apps
typecheck/lint clean; both OpenAPI generators and drift checks pass. Doctrine
ON/SortDirection used to avoid new deprecations. Full gates reserved for handoff.

Internal review: B0/I1, fixed. CI on Stage 1 revealed missing explicit logout
route policies; targeted route gate reproduced RED and passed after exact logout
and selection entries (2 tests / 15 assertions). No gate was relaxed.
One throttle run overlapped test-kernel compilation; isolated and sequential
module runs pass. Avoid concurrent kernel rebuild during auth tests.

External Claude medium: one round, REVIEW_GREEN, B0/I0/M1. Minor: permission
OpenAPI map lost required module names; explicit properties added and both
schemas regenerated, drift checks green. No open BLOCKER/IMPORTANT.

Next: Stage 3 SPA login, session lifecycle and company picker. Browser cookie
isolation remains real HTTP Stage 4 acceptance.
