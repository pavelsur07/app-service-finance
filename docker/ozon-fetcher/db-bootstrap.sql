-- БД и роль сервиса ozon-fetcher на общем инстансе PostgreSQL прода
-- (services/AGENTS.md §9: своя БД, одна роль, права только на свою БД).
-- Идемпотентно; выполняется шагом деплоя от суперпользователя app при
-- OZON_FETCHER_ENABLED=true. Пароль — из окружения psql (секрет
-- OZON_FETCHER_DB_PASSWORD, не в аргументах процесса), синхронизируется при
-- каждом деплое.

\getenv pwd OZON_FETCHER_DB_PASSWORD

SELECT 'CREATE ROLE svc_ozon_fetcher LOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'svc_ozon_fetcher')\gexec

ALTER ROLE svc_ozon_fetcher WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'pwd';

SELECT 'CREATE DATABASE ozon_fetcher OWNER svc_ozon_fetcher'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'ozon_fetcher')\gexec

-- Своя база — только для роли сервиса.
REVOKE ALL ON DATABASE ozon_fetcher FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE ozon_fetcher TO svc_ozon_fetcher;
-- К базе Symfony `app` роль подключиться может (CONNECT выдан PUBLIC; отзывать
-- его у PUBLIC нельзя — на нём держатся другие роли, в т. ч. read-only), но
-- прав на её таблицы у роли нет: PostgreSQL 15 не даёт PUBLIC прав в схеме
-- public, таблицы Symfony принадлежат app (проверено 17.10.2026 на postgres:15:
-- SELECT из таблицы app → permission denied).
