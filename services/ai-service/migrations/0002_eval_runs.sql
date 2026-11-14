-- Technical eval jobs and resource usage only. No company, business task or payload.
CREATE TABLE ai_eval_run (
    run_id uuid PRIMARY KEY,
    suite text NOT NULL,
    run_date date NOT NULL,
    trace_id uuid NOT NULL,
    idempotency_key text NOT NULL UNIQUE,
    configuration_fingerprint text NOT NULL,
    request_hash text NOT NULL,
    status text NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued', 'running', 'passed', 'failed', 'unavailable', 'incomplete', 'skipped_budget')),
    attempt integer NOT NULL DEFAULT 0 CHECK (attempt >= 0),
    lease_until timestamptz,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    completed_at timestamptz,
    error_code text,
    report jsonb NOT NULL DEFAULT '{}',
    UNIQUE (suite, run_date)
);
CREATE INDEX ai_eval_run_claim_idx ON ai_eval_run (created_at, run_id)
    WHERE status IN ('queued', 'running');

CREATE TABLE ai_eval_budget (
    period_kind text NOT NULL CHECK (period_kind IN ('month', 'day')),
    period_start date NOT NULL,
    spent numeric NOT NULL DEFAULT 0 CHECK (spent >= 0),
    reserved numeric(30, 8) NOT NULL DEFAULT 0 CHECK (reserved >= 0),
    PRIMARY KEY (period_kind, period_start)
);

CREATE TABLE ai_eval_call (
    call_id uuid PRIMARY KEY,
    run_id uuid NOT NULL REFERENCES ai_eval_run (run_id),
    attempt integer NOT NULL CHECK (attempt > 0),
    model text NOT NULL,
    requested_amount numeric NOT NULL CHECK (requested_amount > 0),
    amount numeric(30, 8) NOT NULL CHECK (amount > 0),
    day_start date NOT NULL,
    month_start date NOT NULL,
    actual_cost numeric CHECK (actual_cost >= 0),
    input_tokens bigint CHECK (input_tokens >= 0),
    output_tokens bigint CHECK (output_tokens >= 0),
    created_at timestamptz NOT NULL,
    settled_at timestamptz
);
CREATE INDEX ai_eval_call_run_model_idx ON ai_eval_call (run_id, model);
