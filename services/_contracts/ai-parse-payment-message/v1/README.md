# ai-parse-payment-message v1

Task type `parse_payment_message`, generic envelope `ai-task/v1` unchanged.
Definitions `Payload`, `Output` in schema.json; examples are normative shapes.
All keys required. `null` means unknown. Strict objects reject unknown fields.
Money is a positive exact decimal string with two decimal places; direction is
separate. Values are proposals and always require human review and confirmation.
Local item refs are unique per response; Symfony owns stable IDs/revisions.

Evidence is a literal case-sensitive Unicode substring of source text; no byte
or UTF-16 offsets. Amount evidence must occur inside text evidence. Repeated
quotes identify no unique position. Masking tokens are forbidden in evidence;
quote an unaffected fragment, otherwise return clarification. Amounts resembling
PII that are masked cannot be accepted as evidence. Never bypass masking.

Semantic validation additionally checks unique IDs/refs, directory membership,
allowed project–CFO pair, decimal normalization anchored in money evidence,
short unqualified amounts, relative dates anchored in reference_at/timezone,
correction target and incomplete grouping. No conversion, sum or allocation.
A correction without a unique known target asks a question; it cannot create
payments. `no_payment` has no items. Unknown/planned execution is not paid.
Model/schema/check failures use existing envelope rejected/failed statuses.
Clarification uses questions + needs_review, with unsupported values null.

Limits: text 16000 Unicode code points; 50 candidates/draft refs; each directory
1000; allowed pairs 10000. Caller must report oversized contexts, never truncate.
Currency is ISO-shaped; Symfony validates supported currencies and account rules.
Dates absent in text remain null; context dates/currency are visible proposals.

## Transport appendix (new endpoint, generic envelope v1 unchanged)

`GET /internal/tasks/{task_id}/result?company_id=<UUID>` uses the existing
internal bearer authorization. Returns the existing `ai-task/v1 results_page`:
`{items:[task_result],next_cursor:null}` for an unacknowledged result belonging
to that company; otherwise `{items:[],next_cursor:null}` for unknown, pending,
foreign-company or acknowledged task. No distinction leaks task existence.
The normal ack endpoint is unchanged. This read neither consumes nor acknowledges.
