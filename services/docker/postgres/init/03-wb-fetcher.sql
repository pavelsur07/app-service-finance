-- Dev-only: учётные данные исключительно для локального compose-профиля services.
-- Prod: БД и роль создаёт владелец на общем инстансе PostgreSQL
-- (см. services/AGENTS.md §9; доступ сервиса — только env DB_DSN).
CREATE ROLE svc_wb_fetcher LOGIN PASSWORD 'wb_fetcher_dev';
CREATE DATABASE wb_fetcher OWNER svc_wb_fetcher;
