# Stage 3: SPA login and session shell — DONE

Risk MEDIUM. Base `ce991770b0c74d3ec7cbdc8b47c81eceeb174436`.

Per-app session lifecycle with cancellation/generation guards, protected home,
login and explicit error/retry/forbidden states. Shared API transport obtains
purpose-specific CSRF immediately before each mutation; no automatic POST retry.
Account company picker paginates, hides old context while changing, rechecks on
focus and reconciles ambiguous mutations. Admin has no company picker. Public
about/404 bootstrap no session. Password never persisted. Native finance kit
controls and existing Button; EmptyState supports valid block content.

RED: browser anonymous root remained placeholder instead of redirecting to
login. Unit adapter import absent before code. GREEN: typecheck/lint/build both;
Vitest account 28, admin 27; Playwright desktop+mobile dev and preview account12,
admin10 each (production-only tests intentionally skipped in these modes).
Tests cover credentials/CSRF, local login401,403,429,network/retry, late responses
and logout, ambiguous company change, no external return URL, keyboard/pending,
pagination, focus, no overflow and public network isolation.

Initial UI mock glob intercepted Vite /packages/api source paths; narrowed to
exact realm API prefix, failed run stopped and corrected suites passed.
Internal full Stage diff review: B0/I0, valid EmptyState nesting fixed locally.
External not required for MEDIUM Stage; full task external review at handoff.
Real cookies/host isolation are not claimed from UI mocks: Stage 4 next.
