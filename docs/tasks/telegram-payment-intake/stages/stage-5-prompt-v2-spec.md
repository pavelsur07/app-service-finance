# Stage5 — prompt v2 schema visibility

Evidence: targeted diagnostic10cases/14calls, campaign59/82 used. Three replies
remain schema-rejected after retry, two rejected by context checks, two finance
fragments classifiedno_payment. v1 example uses directionnull but never names
valid direction enum. Core Pipeline sends system prompt, not output JSON Schema;
model has no complete formal output contract, especially with empty directories.

Change one variable: render authoritative PaymentOutput.model_json_schema() into
trusted system prompt for new versionv2. Preserve v1 verbatim and all task
checks/input/output/public v1schema/core/gateway/risk/currency/paid/date rules.
V2 template initially identical to v1; no corpus examples or intent/tense policy
changes in this experiment. JSONschema derived from live Pydantic output model,
not a second manual schema. Keep schema outside untrusted customer data block.

Acceptance:
- [x] Regression proves generated output schema absent before fix/present after.
- [x] Generated schema equals actual PaymentOutput schema; direction enums visible.
- [x] Current prompt id v2, original v1 file unchanged, HIGH/manual policy retained.
- [x] Tests/lint/evals pass; manifest hashes rendered prompt, not only template.
- [ ] Same10-case targeted run uses≤20 additional calls, campaign59+20≤82.
- [ ] No claim of full-corpus/independent improvement from selected hard subset.

Additional safe diagnostics revalidate only the already obtained response in
memory to record schema error types and trusted field paths; unknown field names
become extra_field and messages/input/context/url/response are omitted. REDmissing
schema_errors → GREEN redacted path/type fixture. No additional model invocation
for this revalidation. Targeted47tests PASS; complete gates recorded after finish.
Fresh independent review of schema rendering B0/I0/M0; privacy-delta review follows.

Final local gates:303testsPASS/1skip; eval9PASS/1skip; ruff91files and mypy55PASS. New task+helper regression47PASS. No PHP/contract/verifier/core changes.
