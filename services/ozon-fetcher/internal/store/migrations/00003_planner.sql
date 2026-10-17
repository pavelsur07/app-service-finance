-- +goose Up
-- Проход планировщика с перезагрузкой — один раз за дату на кабинет (этап 11):
-- рестарт и второй экземпляр не дублируют перезагрузку, а кабинет, появившийся
-- в снимке позже за тот же день, свою перезагрузку получает.
CREATE TABLE planner_run (
    kind           TEXT        NOT NULL,
    run_date       DATE        NOT NULL,
    connection_ref TEXT        NOT NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (kind, run_date, connection_ref)
);

-- Отказы ключа (401/403) подряд — по кабинету, не по задаче: иначе каждая
-- из ~90 задач кабинета сначала получает свой «первый» 401 (найдено на стенде).
ALTER TABLE connection_state
    ADD COLUMN auth_failures INTEGER NOT NULL DEFAULT 0;

-- Перезагрузка пришла, пока задача в работе: применяется после текущей
-- попытки (загрузка с первой страницы), lease не прерывается.
ALTER TABLE fetch_task
    ADD COLUMN refresh_pending BOOLEAN NOT NULL DEFAULT false;

-- +goose Down
ALTER TABLE fetch_task DROP COLUMN refresh_pending;
ALTER TABLE connection_state DROP COLUMN auth_failures;
DROP TABLE planner_run;
