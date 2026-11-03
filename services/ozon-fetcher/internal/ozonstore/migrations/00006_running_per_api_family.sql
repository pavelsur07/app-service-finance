-- +goose Up
-- Одна running-задача на (кабинет, семейство API): реклама (ozon.ads.*) —
-- свой ключ Performance, свои лимиты и отчёты до 10 мин и дольше; в общем
-- слоте она задерживала бы заказы и начисления
-- (docs/tasks/marketplace-data-ingestion/stages/ozon-6-ads-sync.md §3).
-- ClaimNext проверяет то же выражение.
DROP INDEX fetch_task_one_running_per_connection;
CREATE UNIQUE INDEX fetch_task_one_running_per_connection_family
    ON fetch_task (connection_ref, (resource_type LIKE 'ozon.ads.%'))
    WHERE status = 'running';

-- +goose Down
DROP INDEX fetch_task_one_running_per_connection_family;
CREATE UNIQUE INDEX fetch_task_one_running_per_connection ON fetch_task (connection_ref) WHERE status = 'running';
