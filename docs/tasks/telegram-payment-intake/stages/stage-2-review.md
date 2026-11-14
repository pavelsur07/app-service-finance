# Stage 2 review resolution

External source: `site/var/external-review/dbfc6d5f/review.txt`, round 1.
No BLOCKER. All five confirmed external IMPORTANT findings fixed and verified
without re-run under AGENTS.md §7.2; no second reviewer marker claimed.
Output schema and published contracts remain unchanged.

| Finding | Resolution and regression evidence |
|---|---|
| External I1 partial groups/decimals | Whole numeric boundaries reject 12 500→500, 12.500→500, 1500,50→50 and 12000,5000→5000; percentages are quantity, not money. Tests assert deciding errors. |
| External I2 unqualified decimals/scales/currency | Short decimals require a unit. Omitted million/billion/slang scales fail. Explicit source currency overrides account currency, including RUB versus USD account; a known foreign currency cannot become null/account default. Positive 12,50 dollars and 120 rubles prove the source unit qualifies the amount. |
| External I3 dirty occurrence | Each literal occurrence is checked independently; a clean 500 rubles survives the dirty suffix in 1500. A matching check assigns distinct numeric occurrences to payable candidates, preventing two paid rows from borrowing the same paid occurrence. |
| External I4 anchors/tense/locality | Date, paid fact, sign and currency use one source occurrence and its clause. Explicit date formats dd.mm[.yyyy], Russian month names and five relative-day words are supported from frozen reference time. Common paid verbs are recognized. Adjacent future/unknown sentences do not lend paid/date facts. Conflicting dates/directions in one punctuation clause reject filled values; null requires a question. Prompt matches these restrictions. |
| External I5 output masking | Generated descriptions and all questions reject masking tokens; prompt omits names instead of storing placeholders in a ledger description. Evidence remains a literal unaffected source fragment. |
| External I6 missing files | Rejected: `git ls-tree dbfc6d5ff` lists contexts.json, corpus-review.jsonl and source messages.jsonl. All existed in the Stage 1 commit, so clean checkout is not missing them. Its fixture-coverage subfinding is valid and fixed: independent A/B, forbidden pair, archive and dialogue contexts are exercised by an eval test; the missing-account fixture is explicitly Symfony's obligation. |

Independent review's numeric boundary, explicit currency and paid/date locality
findings overlap external I1/I2/I4; inline sign binding is the additional internal
fix group. Fresh whole-task review's coordinated same-clause date/direction case
extends the locality group and is also fixed. Per-review findings: external
5 confirmed IMPORTANT, 1 missing-files premise rejected; independent internal
4 IMPORTANT plus 1 fresh locality finding. These are 6 distinct fix groups after
deduplicating overlapping reports, not 10 separate defects. Initial author
self-review counts are historical and not added to this review-point union.

Safe MINOR fixes: adversarial fixtures assert expected_error, branch-specific
rather than blanket dimensions; list/quantity tests exclude short-scale errors
and assert the deciding result; null evidence never produces an empty-regex
scan. The endpoint is already documented in the new standalone transport
appendix; the published generic ai-task/v1 files were left immutable.

TDD evidence: the first review counterexample run was 42 failed / 2 passed;
repeated-occurrence reuse, qualified short units and the fresh same-clause scope
cases each failed before their fixes. The final review regression suite is
53 passed; it includes positive cases for ordinary valid messages. A temporary
test-table rename caused collection failure in unit/eval gates; its obsolete
third tuple values were corrected before the final complete gate runs.

Final gates: make ai-service-test 284 passed, 1 skipped; make ai-service-evals
9 passed, 1 skipped. Lint includes ruff, formatting and mypy on all 54 source
files. The existing source-mount skip, no-key live eval skip and two Sentry
warnings remain. No live provider or production call was made. Actual model
quality, recall, latency/cost and pilot readiness remain unverified.

Handoff round 1 MINOR2 (Python): `Получил от клиента - 79000 руб` no longer
gives the separator hyphen silent priority over the incoming verb. Either filled
direction is ambiguous; null requires a question. Three counterexamples observed
RED before the source/prompt fix and GREEN afterwards. Core pipeline unchanged.
