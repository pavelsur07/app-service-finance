# Live-eval action — first approved execution completed

The runner is `python -m ai_service.evals.payment_intake`. It uses the existing
TaskSpec, gateway, masking and Pipeline; it does not boot Symfony, open a database,
write payments or read production credentials. Its default is an offline manifest
of all 41 anonymized source cases, grouped by existing families. All families stay
in holdout; no source example is injected into the prompt.

```bash
docker compose --profile services run --rm services-ai-service-dev \
  python -m ai_service.evals.payment_intake \
  --output evals/parse_payment_message/holdout-manifest.json
```

This command makes zero network calls even if credentials exist. The delivered
manifest records source/review/schema/prompt/runner hashes and IDs, with no text.
To inspect integration offline, explicitly choose `stub/payment-intake`,
`config/routes.dev.yaml`, positive call/token/budget limits and zero token prices.
Stub output proves wiring and limits, not recognition quality.

## Approval inputs

A live run requires a separately named approval covering provider/model, gateway,
41 anonymized cases, at most 82 calls, declared input/output maxima, timeout and
budget estimate. The owner has now approved the proposed DeepSeek model and bounded300RUB trial.
The runner reads `PAYMENT_INTAKE_EVAL_TOKEN`, not service Settings. On14November
the owner explicitly approved this trial and reusing the existing gateway key;
a second credential is not required. An authorized app-host entrypoint may alias
existing AI_GATEWAY_TOKEN to that variable only in process memory. Do not print
or copy the key into the dev machine. [Current preflight](live-eval-preflight-2026-11-14.md)
records the actual access blocker and the fixed source bundle.

Approved: `deepseek/deepseek-flash` through the existing gateway, 82 calls maximum,
65536 declared input guard and 16000 output tokens per call, 60-second timeout,
budget estimate300RUB. Current peak tariff and CBR rate were verified14November;
reserve rates are24.65/98.59RUB per1M and the82-call reservation estimate261.82RUB.
The owner subsequently authorized a one-off root-SSH isolated execution;
first run completed41cases/45calls. See [results](live-eval-result-2026-11-14.md). The old31October
configured rates24.17/96.67 are retained only as historical proposal data.
The alternative existing `openai/gpt-4.1-mini` route
requires its own explicit choice and price/budget approval.

Once an authorized app-host execution entrypoint is available:

```bash
python -m ai_service.evals.payment_intake \
  --execute --routes config/routes.yaml --model deepseek/deepseek-flash \
  --gateway-url https://ai.vashfindir.ru \
  --max-calls 82 --max-input-tokens 65536 --max-output-tokens 16000 \
  --timeout 60 --budget-rub 300 --input-price 24.65 --output-price 98.59
```

No fallback provider or gateway retry runs. The selected DeepSeek route uses
OpenAiCompatibleAdapter and one _http.post_json/client.post per delegation,
without an SDK retry loop. The existing Anthropic adapter also sets max_retries=0. Pipeline's existing second schema
attempt counts against the same cap. Before each call the runner reserves the
configured input/output maxima, including failed calls; reservation is not refunded.
The call count and transmitted output-token cap are enforced. The input guard
uses UTF-8 request bytes plus framing allowance; it is **not an exact tokenizer**.
Unknown provider accounting, hidden input overhead or incorrect declared prices
can exceed the estimate. The report explicitly marks cost as **not a hard provider
billing guarantee**; gateway/account-side enforcement is needed for a monetary
hard ceiling. A returned usage count exceeding declared maxima stops further calls.

## What the report establishes

The report contains only IDs/families, stable errors/verdicts, counters,
response/prompt/model-alias hashes, token usage, measured latency and Decimal cost
estimates. No source text, response text, trace, questions, descriptions or token
value is persisted. Failed/rejected cases are missing **verified** candidates;
this is not a raw pre-verification extraction recall claim.

Partial labels support annotated monetary-mention coverage, literal amounts where
scale is unambiguous, explicitly labelled directions, correction intent, structural
question presence and scale abstention. They do not establish overall accuracy,
precision, financial date accuracy, analytics accuracy or question quality; these
fields remain null. Unannotated extra predictions cannot be called false positives.
Repeated event fragments are grouped, not treated as independent training examples.

Existing adapters discard provider-returned model IDs and expose only their
configured alias. The report prints that safe configured alias, hashes the actual
LlmResponse model field and response bytes, and states this provenance limitation.
`provider_weights_hash` remains null; no alias hash is represented as a hash of
model weights. Missing usage on provider failures can make known-usage cost partial.
Live model quality and pilot readiness remain pending until the approved run and
its separately agreed acceptance criteria have been reviewed.

Current14November execution/result and scorer correction: [live report](live-eval-result-2026-11-14.md). Earlier access-block notes describe the pre-approval state.
