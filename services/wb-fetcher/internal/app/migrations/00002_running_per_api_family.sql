-- +goose Up
-- Одна running-задача на (кабинет, семейство API): реклама (wb.ads.*) — другой хост,
-- свои лимиты и свой доступ токена; в общем слоте она задерживала бы отчёт о продажах
-- (docs/tasks/marketplace-profit-pipeline/stages/stage-8.md, 8.4). ClaimNext проверяет
-- то же выражение (store.Options.SeparateSlotPrefix).
DROP INDEX fetch_task_one_running_per_connection;
CREATE UNIQUE INDEX fetch_task_one_running_per_connection_family
    ON fetch_task (connection_ref, (resource_type LIKE 'wb.ads.%'))
    WHERE status = 'running';

-- +goose Down
DROP INDEX fetch_task_one_running_per_connection_family;
CREATE UNIQUE INDEX fetch_task_one_running_per_connection ON fetch_task (connection_ref) WHERE status = 'running';
