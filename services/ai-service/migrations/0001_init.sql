-- ai-service: очередь задач, результаты для идемпотентности и выдачи Symfony, трассы.
-- Forward-only (services/AGENTS.md §9). Исключение §9 для ai-service: трассы
-- хранят суммы и замаскированные назначения, TTL 30 дней.

CREATE TABLE ai_task (
    id               uuid PRIMARY KEY,
    task_type        text        NOT NULL,
    contract_version text        NOT NULL,
    company_id       uuid        NOT NULL,
    idempotency_key  text        NOT NULL,
    request_hash     text        NOT NULL,
    requested_by     text        NOT NULL,
    trace_id         uuid        NOT NULL,
    -- Вход задачи (может содержать ПДн) живёт только до финализации.
    payload          jsonb,
    status           text        NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued', 'running', 'done', 'acked')),
    attempts         integer     NOT NULL DEFAULT 0,
    available_at     timestamptz NOT NULL DEFAULT now(),
    lease_until      timestamptz,
    last_error       text,
    requested_at     timestamptz NOT NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ai_task_idempotency_key_uniq UNIQUE (idempotency_key)
);

CREATE INDEX ai_task_claim_idx ON ai_task (available_at, created_at) WHERE status IN ('queued', 'running');

CREATE TABLE ai_result (
    task_id        uuid PRIMARY KEY REFERENCES ai_task (id),
    seq            bigserial   NOT NULL UNIQUE,
    status         text        NOT NULL CHECK (status IN ('accepted', 'needs_review', 'rejected', 'failed')),
    output         jsonb,
    checks         jsonb       NOT NULL DEFAULT '[]',
    confidence     numeric(5, 4),
    models         jsonb       NOT NULL DEFAULT '[]',
    prompt_version text,
    input_tokens   integer     NOT NULL DEFAULT 0,
    output_tokens  integer     NOT NULL DEFAULT 0,
    error_code     text,
    completed_at   timestamptz NOT NULL DEFAULT now(),
    acked_at       timestamptz
);

CREATE INDEX ai_result_unacked_idx ON ai_result (seq) WHERE acked_at IS NULL;

CREATE TABLE ai_trace (
    id         bigserial PRIMARY KEY,
    task_id    uuid        NOT NULL,
    company_id uuid        NOT NULL,
    trace_id   uuid        NOT NULL,
    task_type  text        NOT NULL,
    data       jsonb       NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX ai_trace_created_at_idx ON ai_trace (created_at);
CREATE INDEX ai_trace_company_idx ON ai_trace (company_id);
CREATE INDEX ai_trace_task_idx ON ai_trace (task_id);
