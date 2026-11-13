-- +goose Up
-- Публикация фактов (services/_contracts/source-fact-envelope/v1): уже
-- загруженные дни начислений ставятся в outbox_batch текущей ревизией — Symfony
-- получит историю без повторной загрузки из Ozon. Дальше пачку ставит
-- FinishTask при каждой новой ревизии; batch_id стабилен, повтор безвреден.
INSERT INTO outbox_batch (batch_id, envelope_hash, payload)
SELECT connection_ref || ':' || resource_type || ':' || period_key || ':r' || revision,
       content_hash,
       jsonb_build_object('connection_ref', connection_ref, 'resource_type', resource_type,
                          'period_key', period_key, 'revision', revision, 'pages', content_pages)
FROM fetch_task
WHERE resource_type = 'ozon.finance.accrual-by-day' AND revision > 0 AND content_hash IS NOT NULL AND content_pages <> ''
ON CONFLICT (batch_id) DO NOTHING;

-- +goose Down
-- Пачки — указатели на ревизии; неподтверждённые снимаются, подтверждённые остаются журналом.
DELETE FROM outbox_batch WHERE status = 'new' AND payload ->> 'resource_type' = 'ozon.finance.accrual-by-day';
