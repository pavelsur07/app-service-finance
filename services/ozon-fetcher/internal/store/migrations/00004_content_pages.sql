-- +goose Up
-- Страницы текущей загруженной ревизии задачи (sha256 через запятую, по
-- порядку): content_hash необратим, а страницы ревизии могут лежать в разных
-- попытках (продолжение после сбоя). Читает зависимый ресурс — начисления по
-- отправлениям берут номера из страниц заказов того же дня. Техническое
-- состояние: хеши, без содержимого.
ALTER TABLE fetch_task
    ADD COLUMN content_pages TEXT NOT NULL DEFAULT '';

-- Начало загрузки: запись первой страницы текущей загрузки (load_started_at)
-- и ревизии (content_started_at, копия при done). Зависимый ресурс по нему
-- проверяет, что ревизия заказов дня загружена целиком после конца дня, а не
-- дочитана по страницам, сохранённым раньше.
ALTER TABLE fetch_task
    ADD COLUMN load_started_at    TIMESTAMPTZ,
    ADD COLUMN content_started_at TIMESTAMPTZ;

-- +goose Down
ALTER TABLE fetch_task DROP COLUMN content_started_at, DROP COLUMN load_started_at;
ALTER TABLE fetch_task DROP COLUMN content_pages;
