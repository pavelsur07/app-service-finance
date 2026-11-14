# Stage 1 — contract specification and acceptance

Base: `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`. Risk MEDIUM.
Scope: a new immutable `ai-parse-payment-message/v1` payload/output schema,
corpus review, synthetic contexts and compatibility evidence; no financial writes.

Approved conservative runtime decisions: only explicitly paid items may later
be confirmed; planned/unknown execution stays unresolved. Short unqualified
amounts require scale clarification. Multiple mentions never imply summation or
payment grouping. Multi-category allocation remains unresolved in v1. Required
Cash fields and account ownership are checked by Symfony. Dates/currency supplied
by context remain visible proposals; personal/third-party payment asks for source
of funds and reimbursement context. No compensation or old-record edit is inferred.

Evidence uses exact case-sensitive Unicode substrings, without offsets. Repeated
identical substrings are not claimed as distinct locations: local refs identify
candidates, not source offsets. Text evidence containing masking tokens fails
closed; the model must quote an unaffected money/purpose span instead. A quote
cannot establish a guessed monetary scale. Input limits are 16000 characters,
1000 entries per directory, 10000 allowed pairs and 50 draft refs; excess is
rejected, never truncated. Output limit is 50 candidates.

Acceptance checklist:
- [x] All 41 unique source cases reviewed; all annotated spans present.
- [x] Source partial annotations are facts/constraints, never a saved-data oracle.
- [x] Questions recorded for short amounts, grouping, personal funds, future payment.
- [x] New schema/examples validate; wrong types, extra fields, bad money/limits fail.
- [x] Generic published ai-task/v1 unchanged; echo/categorization remain compatible.
- [x] Synthetic A/B directories and family-separated prompt/holdout documented.

Checks: baseline `make ai-service-test`; new schema tests red then green;
existing contract/unit suite. Live LLM is excluded.
