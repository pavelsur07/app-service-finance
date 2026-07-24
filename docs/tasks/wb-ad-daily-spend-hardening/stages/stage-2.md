# Stage 2 Report — WB self-recovery, retry, and alerting

Stage base commit: `26cb5ff0b3faa36340bc6b259accfd7fcf0f9e06`

## Result

- A persisted real-unmapped count triggers exactly one WB listing-catalog
  refresh and one reprojection of the same raw document.
- The recovery path never refetches `/upd` or `/fullstats`. A catalog failure
  keeps the first financial projection in `DRAFT`; if its transaction closes
  Doctrine's manager, the manager is reset before processing sibling
  connections.
- Load results, completion logs, and stdout expose refresh attempt/success and
  projection retry count.
- Each individual Promotion API request has at most three total attempts for
  HTTP 429/5xx. Integer and strict RFC 7231 `Retry-After` are supported;
  absent/invalid values use 2/4-second backoff, and values above 120 seconds
  fail without waiting.
- Unresolved review results emit exactly one normal-channel application
  `ERROR` per command run, with a sample capped at ten and marker
  `wb_ad_spend_review_required`.
- Authentication/other 4xx and transport errors are not retried. `DRAFT`
  continues to return command success; real connection failures remain
  non-zero.

No database migration or public HTTP API change was introduced.

## Checks

- Focused action/client/command/catalog tests: 38 tests / 245 assertions —
  green after review fixes.
- MarketplaceAds unit suite: 346 tests / 2203 assertions — green after review
  fixes.
- MarketplaceAds integration suite: 173 tests / 697 assertions — green.
- Final client regression test: 15 tests / 55 assertions — green.
- Symfony container lint — green.
- Task-scoped PHP CS Fixer and PHP syntax lint — green.
- `git diff --check` — green.

## Reviews

- Internal independent review: green after tightening `Retry-After` parsing to
  strict RFC 7231.
- External review iteration 1 found one IMPORTANT closed-EntityManager
  recovery issue and four safe MINOR findings; all were fixed and retested.
- External confirmation review: `REVIEW_GREEN`.
- Final narrow review after the last safe MINOR fixes: `REVIEW_GREEN`.
- Remaining BLOCKER/IMPORTANT findings: none.

The external reviewer could not execute Docker-backed tests under its
read-only Bash policy; Codex executed and recorded those suites above.

## Follow-up

- Consider a global command deadline for tenants with many connections and
  fullstats batches.
- Consider catalog-refresh cooldown for permanently deleted/unresolvable WB
  cards.
- A pre-existing projection-transaction failure can close Doctrine's manager
  in the command's general connection-failure path; address it separately
  without changing this Stage's catalog-refresh contract.

## Production

No production action was performed. The Production Gate remains closed.
