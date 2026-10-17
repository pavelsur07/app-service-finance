-- +goose Up
-- Проход планировщика с перезагрузкой — один раз за дату (этап 11):
-- рестарт и второй экземпляр сервиса не дублируют перезагрузку.
CREATE TABLE planner_run (
    kind       TEXT        NOT NULL,
    run_date   DATE        NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (kind, run_date)
);

-- Отказы ключа (401/403) подряд — по кабинету, не по задаче: иначе каждая
-- из ~90 задач кабинета сначала получает свой «первый» 401 (найдено на стенде).
ALTER TABLE connection_state
    ADD COLUMN auth_failures INTEGER NOT NULL DEFAULT 0;

-- +goose Down
ALTER TABLE connection_state DROP COLUMN auth_failures;
DROP TABLE planner_run;
