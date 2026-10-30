-- Dev-only: учётные данные исключительно для локального compose-профиля services.
-- Prod: БД ai_service и роль svc_ai_service создаёт деплой (отдельное одобрение,
-- AGENTS.md §3.3); доступ сервиса — только env AI_SERVICE_DB_DSN.
CREATE ROLE svc_ai_service LOGIN PASSWORD 'ai_service_dev';
CREATE DATABASE ai_service OWNER svc_ai_service;
CREATE DATABASE ai_service_test OWNER svc_ai_service;
