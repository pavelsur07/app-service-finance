# Stage 5 work item — conservative field handling in prompt v3

Observed RED evidence: the immutable v2 run (source4160b4e08) rejects009 for
date_unanchored,011 for paid_unanchored,016 for direction_contradiction and022
for null descriptions. All are existing anonymized development cases. The
remaining campaign allowance is11calls of82; no fresh full-corpus run fits.

Root causes established from source and safe diagnostics:

- The global instruction 'unknown=null' conflicts with required string fields.
- An adjective referring to today is outside the verifier's exact date tokens.
- A past-tense verb in another paragraph is not a local paid anchor.
- An arithmetic plus between expense amounts can conflict with inferred outflow.

Change only the trusted prompt: retain all amounts as independent candidates;
unknown description is the factual string 'Назначение не указано', unknown
execution status is unknown, nullable fields can be null. Explicitly require the
existing exact local date/paid rules and an unknown direction/question when
symbols and context are ambiguous. A bare list of monetary expressions is a
payment draft with missing attributes, as required by the reviewed corpus.
Never postprocess or silently repair model output. Keep v1/v2 immutable and
the generated schema, verifier, public v1 contract, PHP consumer and HIGH risk.

Acceptance checklist:

- [x] New immutable v3 selected; v1/v2 byte-identical to their committed versions.
- [x] Existing Python lint/tests/evals pass, output schema and verifier unchanged.
- [x] Offline manifest updated with current rendered prompt/source hashes.
- [ ] Same-provider run on009/011/016/022 and026(control), at most10calls, campaign
      total<=81/82. Never repeat after an uncertain outcome.
- [ ] Record every case's safe verdict/check code/count and campaign balance.
- [ ] Do not infer full-corpus quality, precision or generalization from tuning.
- [ ] Final read-only metadata confirms existing API/worker unchanged and no own
      disposable eval container remains.

Model prompt prose is validated by the actual live consumer, not a test that
searches for copied instructions. Existing automated schema/pipeline tests
protect the unchanged contract; the observed v2 run supplies RED evidence.
If quality is insufficient, keep the feature OFF and report the precise gaps
and additional acceptance work required. No merge/deploy/pilot is authorized.

Local gates:303testsPASS/1skip,eval9PASS/1skip,ruff92files/mypy55PASS.
Independent focused review B0/I0/M0. The first offline manifest command used an
incorrect container path and returned runner_error/FileNotFoundError (no network);
corrected relative path succeeded with41cases/0calls. Verifier/schema/older
prompts byte-compared against HEAD and unchanged. No PHP changes or gate rerun.
