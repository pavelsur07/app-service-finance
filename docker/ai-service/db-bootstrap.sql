-- БД и роль ai-service на общем инстансе PostgreSQL прода
-- (services/AGENTS.md §9: своя БД, одна роль, права только на свою БД).
-- Идемпотентно; выполняется шагом деплоя от суперпользователя app при
-- AI_SERVICE_ENABLED=true. Пароль — из окружения psql (секрет
-- AI_SERVICE_DB_PASSWORD, не в аргументах процесса), синхронизируется при
-- каждом деплое. Образец — docker/ozon-fetcher/db-bootstrap.sql.

\getenv pwd AI_SERVICE_DB_PASSWORD

SELECT 'CREATE ROLE svc_ai_service LOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'svc_ai_service')\gexec

ALTER ROLE svc_ai_service WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'pwd';

SELECT 'CREATE DATABASE ai_service OWNER svc_ai_service'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'ai_service')\gexec

-- Своя база — только для роли сервиса. К базе Symfony `app` прав на таблицы у
-- роли нет (PostgreSQL 15 не даёт PUBLIC прав в схеме public; см. ozon-fetcher).
REVOKE ALL ON DATABASE ai_service FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE ai_service TO svc_ai_service;
