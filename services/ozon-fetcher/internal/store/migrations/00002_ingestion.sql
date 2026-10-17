-- +goose Up
-- Журнал загрузок raw (docs/tasks/ozon-fetcher-ingestion, этап 7).
-- Только техническое состояние: без сумм, ПДн и ключей (services/AGENTS.md §9).

-- Что должно быть загружено. period_key: YYYY-MM-DD | YYYY-MM | ref.
CREATE TABLE fetch_task (
    connection_ref   TEXT        NOT NULL,
    resource_type    TEXT        NOT NULL,
    period_key       TEXT        NOT NULL,
    status           TEXT        NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'running', 'done', 'not_ready', 'failed_retryable', 'failed_permanent')),
    priority         SMALLINT    NOT NULL DEFAULT 0,
    attempts         INTEGER     NOT NULL DEFAULT 0,
    next_run_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    lease_owner      TEXT,
    lease_until      TIMESTAMPTZ,
    attempt_id       UUID,                 -- текущая попытка (running)
    cursor           TEXT,                 -- курсор следующей страницы
    next_page_no     INTEGER     NOT NULL DEFAULT 1,
    page_hashes      TEXT        NOT NULL DEFAULT '', -- sha256 страниц текущей загрузки через запятую
    content_hash     CHAR(64),             -- sha256 последней успешной загрузки
    revision         INTEGER     NOT NULL DEFAULT 0,
    last_error_class TEXT,
    last_success_at  TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (connection_ref, resource_type, period_key),
    CHECK ((status = 'running') = (lease_until IS NOT NULL))
);

-- Выдача готовых задач.
CREATE INDEX fetch_task_ready ON fetch_task (next_run_at, priority DESC)
    WHERE status IN ('pending', 'not_ready', 'failed_retryable');
-- Один кабинет — один воркер: гарантия на уровне БД.
CREATE UNIQUE INDEX fetch_task_one_running_per_connection ON fetch_task (connection_ref)
    WHERE status = 'running';
-- Просроченные lease.
CREATE INDEX fetch_task_lease ON fetch_task (lease_until) WHERE status = 'running';

-- Журнал попыток, append-only.
CREATE TABLE fetch_attempt (
    attempt_id     UUID        PRIMARY KEY,
    connection_ref TEXT        NOT NULL,
    resource_type  TEXT        NOT NULL,
    period_key     TEXT        NOT NULL,
    trace_id       TEXT        NOT NULL,
    lease_owner    TEXT        NOT NULL,
    started_at     TIMESTAMPTZ NOT NULL,
    finished_at    TIMESTAMPTZ,
    outcome        TEXT,                   -- done | unchanged | <класс ошибки> | released
    http_status    INTEGER,
    pages          INTEGER     NOT NULL DEFAULT 0,
    bytes          BIGINT      NOT NULL DEFAULT 0
);
CREATE INDEX fetch_attempt_task ON fetch_attempt (connection_ref, resource_type, period_key, started_at DESC);

-- Объект S3 на страницу попытки.
CREATE TABLE raw_object (
    attempt_id   UUID        NOT NULL REFERENCES fetch_attempt (attempt_id),
    page_no      INTEGER     NOT NULL,
    storage_uri  TEXT        NOT NULL,
    sha256       CHAR(64)    NOT NULL,     -- от несжатых байтов
    size_bytes   BIGINT      NOT NULL,
    stored_bytes BIGINT      NOT NULL,     -- после gzip
    content_type TEXT        NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (attempt_id, page_no)
);

-- Состояние кабинета: честная очередь и пауза (auth).
CREATE TABLE connection_state (
    connection_ref  TEXT        PRIMARY KEY,
    last_served_at  TIMESTAMPTZ,
    suspended_until TIMESTAMPTZ,
    suspend_reason  TEXT,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Лимит пары (кабинет, endpoint): вариант A, решение владельца 2026-10-17.
CREATE TABLE connection_limit (
    connection_ref TEXT          NOT NULL,
    endpoint       TEXT          NOT NULL,
    rps_override   NUMERIC(10,4) CHECK (rps_override IS NULL OR rps_override > 0),
    rps_current    NUMERIC(10,4) CHECK (rps_current IS NULL OR rps_current > 0),
    updated_at     TIMESTAMPTZ   NOT NULL DEFAULT now(),
    PRIMARY KEY (connection_ref, endpoint)
);

-- Ресурс, остановленный для всех кабинетов (obsolete_endpoint).
CREATE TABLE resource_stop (
    resource_type TEXT        PRIMARY KEY,
    reason        TEXT        NOT NULL,
    stopped_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- +goose Down
DROP TABLE resource_stop;
DROP TABLE connection_limit;
DROP TABLE connection_state;
DROP TABLE raw_object;
DROP TABLE fetch_attempt;
DROP TABLE fetch_task;
