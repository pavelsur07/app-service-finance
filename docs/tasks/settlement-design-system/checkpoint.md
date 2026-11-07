## Current checkpoint

**Phase:** Stage 3 / Work item 3.2
**Status:** checking
**Stage base commit:** `e4df18bed`

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

- Stage3: malformed date RED→GREEN; invalid counterparty original template RED reproduced using ArrayLoader, then GREEN/visible browser alert. Selected-first RED→GREEN.
- Fresh final internal reviewer:3IMPORTANT+1MINOR fixed, second review open0; DDS form composition completed.
- Browser interactions6checks PASS; matrix72cases/13screens×4+states,reader10,no-JS2 PASS;0pageerrors/overflow/broken logos. Full PHPStan initial failure on new test nullable getReference fixed with assertion; PHPStan3861/CS3897/strict3897/unit4547 tests25384assertions PASS. Full site-test and external Claude round1 REVIEW_GREEN, B0/I0;3MINOR local UI refinements fixed;11UI tests89assertions+Twig19 PASS. First full suite failed only Lifecycle global0 assertion (browser fixtures5movements). Clean targeted Lifecycle1test11assertions PASS; separate browserDB vf_settlements_ui created, testDB0 checked, full clean rerun running.

### Review status
- Stage2 internal1iteration, B0/I1fixed (readonly fields/buttons),open0; external handoff pending.
- Stage1 internal1iteration, B0/I1fixed,open0; external not required (<500lines).
- Stage1 checks:53tests/250assertions, Twig15, focused PHPStan/CS, Node lint/build/67tests PASS.
- Browser period focus/Escape+picker visible, pageerror0.

### Exact next action
- Await clean full suite. Browser3final refinements PASS on separateDB8098; externalgreen. Product checkpoint committed/pushed while clean full suite completes; PR remains Draft. Commit/push/Ready and final report after checks.

### Files to inspect first on resume
- plan.md, design.md, this checkpoint; git status; site/templates/settlement; site/tests/Functional/Settlement/SettlementUiRefactorTest.php.
