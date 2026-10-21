-- +goose Up
-- Страницы текущей загруженной ревизии задачи (sha256 через запятую, по
-- порядку): content_hash необратим, а страницы ревизии могут лежать в разных
-- попытках (продолжение после сбоя). Читает зависимый ресурс — начисления по
-- отправлениям берут номера из страниц заказов того же дня. Техническое
-- состояние: хеши, без содержимого.
ALTER TABLE fetch_task
    ADD COLUMN content_pages TEXT NOT NULL DEFAULT '';

-- +goose Down
ALTER TABLE fetch_task DROP COLUMN content_pages;
