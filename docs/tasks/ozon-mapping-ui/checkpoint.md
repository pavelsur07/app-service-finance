## Current checkpoint
Phase: Handoff / final checks; Status: checking
Base c5485f18f; Stage 1 a2ff06e8c, Stage 2 commit next.
Done: Ozon-only labels/source descriptions; conflict repair confirmation; transaction + lock;
create/edit/toggle guard; company CSRF; monthly realization status. Other areas untouched.
Baseline15 tests/114 assertions; backend red5→green23/136; module62/264; focused PHPStan green;
Twig, frontend lint/build/API types and modal JS smoke green. BrowserKit DOM smoke, no real browser.
Next: full gates once; fresh read-only internal review + external; handoff PR #2830 Ready.
Production not mutated; old rollback #2828 not resumed; no formulas/categories/history changes.

Final checks running: make site-stan/site-cs-check/site-cs-strict-types/site-test-unit;
make site-test with existing prepared deps (-o site-composer-install preserves owner vendor).
Fresh internal reviewer REVIEW_GREEN 0 B/I/M/F; external Claude round1 REVIEW_GREEN 0 B/I,4 MINOR fixed/verified without rerun; post-review module64/273, focused PHPStan green; internal follow-up green.
