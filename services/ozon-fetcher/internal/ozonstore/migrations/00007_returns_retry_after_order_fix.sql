-- +goose Up
-- Возвраты (ozon-10-returns.md): проверка «id по возрастанию» отвергала дни
-- кабинета с FBS, где id внутри страницы не упорядочены (прод 24.10.2026), —
-- дни ушли в failed_permanent. Исправленный разбор порядок не проверяет;
-- дни возвращаются в очередь с первой страницы.
UPDATE fetch_task
SET status = 'pending', next_run_at = now(), attempts = 0, cursor = NULL, next_page_no = 1, page_hashes = '',
    last_error_class = NULL, updated_at = now()
WHERE resource_type = 'ozon.returns' AND status = 'failed_permanent' AND last_error_class = 'invalid_response';

-- +goose Down
-- Повтор — штатная загрузка; возвращать дни в ошибку не нужно.
SELECT 1;
