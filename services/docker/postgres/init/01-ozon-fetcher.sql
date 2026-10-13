-- Dev-only: учётные данные исключительно для локального compose-профиля services.
-- Prod: БД и роль создаёт владелец на общем инстансе PostgreSQL
-- (см. services/AGENTS.md §9; доступ сервиса — только env DB_DSN).
CREATE ROLE svc_ozon_fetcher LOGIN PASSWORD 'ozon_fetcher_dev';
CREATE DATABASE ozon_fetcher OWNER svc_ozon_fetcher;
