-- +goose Up
-- Техническое состояние fetcher'а: без денег и ПДн (services/AGENTS.md §9).
CREATE TABLE sync_job (
    job_id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    connection_ref  TEXT NOT NULL,
    resource_type   TEXT NOT NULL,
    business_period TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'running',
    trace_id        TEXT NOT NULL,
    started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at     TIMESTAMPTZ,
    error_class     TEXT
);

CREATE TABLE checkpoint (
    connection_ref  TEXT NOT NULL,
    resource_type   TEXT NOT NULL,
    business_period TEXT NOT NULL,
    cursor          TEXT NOT NULL,
    page_count      INTEGER NOT NULL DEFAULT 0,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (connection_ref, resource_type, business_period)
);

CREATE TABLE outbox_batch (
    batch_id      TEXT PRIMARY KEY,              -- Idempotency-Key (контракт v1)
    envelope_hash CHAR(64) NOT NULL,             -- sha256 канонической формы
    payload       JSONB NOT NULL,                -- обнуляется после ack (retention)
    status        TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'sent', 'acked')),
    attempts      INTEGER NOT NULL DEFAULT 0,
    next_retry_at TIMESTAMPTZ,
    sent_at       TIMESTAMPTZ,
    acked_at      TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX outbox_batch_pickup ON outbox_batch (status, next_retry_at);

-- +goose Down
DROP TABLE outbox_batch;
DROP TABLE checkpoint;
DROP TABLE sync_job;
