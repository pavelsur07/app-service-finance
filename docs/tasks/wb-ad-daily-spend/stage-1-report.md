# Stage 1 Report: Current WB Promotion API boundary

Stage base commit: `d29398e967fe56be7bce7dfb0acb43adcde2d582`
Risk: MEDIUM
Status: complete

## Delivered

- Implemented current read-only Wildberries Promotion API calls:
  `GET /adv/v1/upd` and `GET /adv/v3/fullstats`.
- Scoped credential lookup by company, WB SELLER connection type, and explicit
  connection ID.
- Enforced the 50-campaign `/fullstats` batch limit and 20-second spacing in
  the combined compatibility method.
- Added safe classification for authentication, rate-limit, transport, server,
  unexpected status, and invalid-response failures.
- Added an exact JSON decoder that preserves JSON number lexemes as strings, so
  financial decimals never pass through `float`.
- Added focused tests for endpoints, query shape, authorization header,
  connection isolation, chunking, spacing, empty response, failure classes,
  malformed numbers, escaped strings, and a dense nested fullstats payload.

## Checks

- Focused WB tests: green, 22 tests / 79 assertions before review fixes.
- Full MarketplaceAds unit suite after review fixes:
  green, 316 tests / 1956 assertions.
- PHP syntax checks for changed WB client/decoder/tests: green.
- `git diff --check`: green.

## Internal review

- Found and fixed numeric-string array keys being converted to integers while
  deduplicating campaign IDs.
- Found and fixed malformed JSON number lexemes that could become valid quoted
  strings.
- No unresolved BLOCKER or IMPORTANT findings.

## External Claude Code review

- Initial finding: IMPORTANT — number scanning copied the remaining JSON tail
  for every number token and could degrade to O(n²).
- Fix: offset-anchored PCRE matching without `substr()`, plus a dense nested
  50-campaign/5,000-SKU-number regression payload.
- Repeat review result: `REVIEW_GREEN`.
- Rejected MINOR: accepting an empty `{}` row. It is outside both WB response
  contracts, and strict rejection avoids PHP's ambiguous `{}` to `[]`
  associative conversion.
- FOLLOW-UP items retained for Stage 3: orchestration placement of request
  pacing and completion of manual/daily command integration.

## Compatibility and operations

- No live WB API request was made.
- No production configuration, migration, data, worker, or cron was changed.
- Existing Ozon behavior is unchanged.
