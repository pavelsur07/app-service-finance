# Review and verification findings

Task diff reviewed against initial238dd997 and, after clean upstream merge,0b4206db. Fresh internal reviewers received spec/diff/checklist without implementation history. No open BLOCKER/IMPORTANT by final code inspection.

## Internal and CI findings

| Severity | Finding | Resolution / evidence |
| --- | --- | --- |
| BLOCKER | Doctrine DELETE does not enforce optimistic version; concurrent enable could be deleted | Pessimistic row lock, refresh, compare version/active inside transaction; concurrency regression |
| BLOCKER | Unicode token could enter Symfony invalid-header exception containing credentials | Reject non-printable/non-ASCII and >8192 input before HTTP/logging; four failing regressions then green, client34/182 |
| IMPORTANT | HTTP client lacked required safe request observability | Log method/sanitized URL/status/duration only; no body/token/exception message; privacy tests |
| IMPORTANT | Out-of-range pagination raised unexpected exception | Explicit Pagerfanta range handling ->422; functional coverage |
| IMPORTANT | Mixed GET/POST form route WRITE attribute rejected READ GET | Existing global integration+functional CI tests failed; READ attribute plus early POST-only WRITE; valid-form permission regression |

Metrics: internal/CI2 BLOCKER and3 IMPORTANT found, all fixed; external0 BLOCKER and0 IMPORTANT. Fresh incremental reviews of fixes found0 new BLOCKER/IMPORTANT.

## External review

Claude Code, read-only, round1, exact REVIEW_GREEN, exit0:

```text
site/bin/external-review.sh 0b4206db373fa89cff0d67146641093bdafc8461 --effort medium --context /tmp/moysklad-external-context.md
```

Run in isolated task-only worktree `/tmp/moysklad-review-final`; report `site/var/external-review/0b4206db/review.txt` there. Integrated backend/frontend/backfill diff3005lines. This combined review covers the large HIGH-LOCAL backend Stage and handoff snapshot; no redundant second invocation after MINOR/IMPORTANT fixes, per AGENTS7.2.

Five MINOR fixed without re-run:
1. Duplicate maxlength4096 removed;8192 matches backend.
2. Expected deletion validation returned from transaction before raising safe application exception; EntityManager stays open. Regression proved red under old throw-in-transaction behavior, then module suite green.
3. READ mutation test now uses valid CSRF/version so it independently proves write authorization.
4. Backfill logs only exception class/code/companyId; exact-context test excludes payload/message/trace.
5. New connection base URL uses configured MOYSKLAD_API_BASE_URL consistently with client.

Fresh internal review accepted all five and later POST-only WRITE fix. External reviewer had no production access; tests use fake credentials and mocked HTTP. No live MoySklad request made.

## Verification failures and limits

Initial full PHPStan found6 test typing/redundant-assert errors, corrected; full2620-file run green. Full CS found1 unused import, corrected; subsequent full run green. CI failures in mixed-route integration and functional guards share the single IMPORTANT above, not unrelated baseline failures. One host full-gate invocation terminated143; no success claimed. Durable container runner avoids losing long command output. Initial npm ci could not run without package-lock; used repository yarn.lock and frozen install. Initial frontend build warned about missing UX Turbo metadata; copying the existing vendor package into the isolated build environment removed that warning and final build/lint passed.

Unit suite reports4 deprecations; final counts/results are in handoff. Production row count, backup availability and lock duration are not yet measured. Shared mobile sidebar remains expanded because existing d-flex overrides collapse: FOLLOW-UP outside task UI. No change to existing sidebar behavior.

UI guard diagnosis: base0b4206db classes8964/mapping47; final classes8958/mapping47. Existing global UI Kit scanners do not account for legacy Tabler. Owner explicitly required Tabler without UI Kit; no guard/config or unrelated component changes made.

Local combined composer test on pre-permission-fix snapshot:5015tests28680assertions,4failures,6deprecations. Two failures were known mixed-route guards (fixed335acaca); two Cash AccountStrictMatch assertions lacked a Cyrillic-named tracked fixture because the temporary source-sync helper used quoted git ls-files output. Fixed helper to use NUL-delimited paths and resynced; no Cash code/fixture change. Final CI on335acaca passes all5016tests28701assertions, including all four previously failing cases. Focused local reconciliation includes MoySklad + both global route guards + AccountStrictMatch. Full local suite not repeated unnecessarily after green final CI; original nonzero result retained honestly.
