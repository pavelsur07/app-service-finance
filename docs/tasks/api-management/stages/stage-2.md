# Stage 2 — интерфейс управления API

Base:3edbb656. Risk:MEDIUM. DoD completed: owner-only Twig settings, create/check/rename/revoke, CSRF, no-store one-time issuance, empty/error/mobile states and existing UI Kit.

Changes: five controllers and Symfony Forms; paginated scoped list and two navigation entries; Vite copy/history-clearing behavior; safe settings error rendering; functional coverage. Secret never passes through URL, flash or persistent browser storage. Issuance consumes CSRF token; repeated POST is422.

Verification: Api module+write-gate50tests651assertions PASS; focused PHPStan0errors, CS0/11, Twig/YAML PASS, npm lint/build PASS. UI Kit9107 and React mapping47 pre-existing violations unchanged. Real Chromium desktop/mobile smoke covered login, empty/list, issue/copy/storage/history, rename/check/revoke and390px overflow. Temporary fixture/session override removed.

Review: fresh independent complete Stage2diff review0BLOCKER/0IMPORTANT/0MINOR, git diff --check PASS. Implementation fixed empty POST status and long-name layout before review. External review0rounds: MEDIUM Stage, not required. Open findings0. Bundle1.12kB (gzip0.58kB); no dependency additions.

Risks/follow-ups: prepared permissions intentionally belong to Stages3–4. GitHub PR creation remains a service error; branch is pushed and implementation continues.
