## Current checkpoint

**Phase:** Stage 3 / Work item 3.1
**Status:** implementing
**Stage base commit:** `d10c43891`

### Completed
- Owner approved implementation («Делай»). Existing isolated worktree/branch and PR #2907 reused; PR returned to Draft. Main checkout untouched.
- Local PHP container mounts this worktree. Node dependencies copied into ignored node_modules; composer/yarn lock hashes match installed dependencies. No dependencies added.
- master advanced only with unrelated stock-turnover feature; no overlapping task files.

### Checks and baseline
- Functional/Settlement: 26 tests,164 assertions PASS.
- Existing counterparty picker/Form/API/Cash:23 tests,58 assertions PASS.
- Node24 container: npm run lint / build / test:unit PASS (67 tests).
- Logs: /tmp/vf-ui-baseline-{php,picker,node}.log.

### Decisions
- Reuse existing branch/PR rather than create a second implementation PR: AGENTS one-task/one-PR rule takes precedence over plan wording.
- Local synthetic browser data only, dedicated vf_settlements_test DB. No production config/data.

- Stage2 templates/forms implemented;34tests/234assertions, Twig19, PHPStan/CS PASS; frontend lint/build/67tests PASS.

### Review status
- Stage2 internal1iteration, B0/I1fixed (readonly fields/buttons),open0; external handoff pending.
- Stage1 internal1iteration, B0/I1fixed,open0; external not required (<500lines).
- Stage1 checks:53tests/250assertions, Twig15, focused PHPStan/CS, Node lint/build/67tests PASS.
- Browser period focus/Escape+picker visible, pageerror0.

### Exact next action
- Finish13screen×4viewport acceptance, stress/reader/no-JS/picker; full gates once; fresh final review then external review.

### Files to inspect first on resume
- plan.md, design.md, this checkpoint; git status; site/templates/settlement; site/tests/Functional/Settlement/SettlementUiRefactorTest.php.
