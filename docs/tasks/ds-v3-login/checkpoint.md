# Current checkpoint

Phase: Stage 1 closing / Stage 2 next. Status: reviewing.
Stage1 base: `92698d36a976d41500942ba58eda0206df7abd0c`.

Completed: shared auth UI, validation/loading/401/eye, cookie/footer, both routes,
expiry provenance and known email in-memory. Raw stores/safeReturnPath unchanged.
Baseline account140/admin136; Stage1 account158/admin154 passed; both typecheck/lint.
Review: internal B0/I0 (minor1); external Codex round1 B0/I1 fixed without re-run
(expired email/password focus, RED→GREEN16/16 both apps). No open B/I.
Next: commit/push Stage1, create single Draft PR; Stage2 tests first CompanyPicker.
Inspect first: plan.md, stage-1.md, CompanyPicker.tsx, account LoginPage.tsx.
