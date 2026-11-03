<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\MicrosecondDateTimeImmutableType;
use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;

/**
 * Ingestion: частичные индексы очередей, хранение времени в `timestamp(6)`.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class IngestionSchemaExtension implements SchemaExtension
{
    private const MICROSECOND_COLUMNS = [
        'ingest_category_group_rules' => ['created_at', 'updated_at'],
        'ingest_cursors' => ['last_fetched_at', 'created_at', 'updated_at'],
        'ingest_external_categories' => ['first_seen_at', 'last_seen_at', 'created_at', 'updated_at'],
        'ingest_external_category_company_mappings' => ['created_at', 'updated_at'],
        'ingest_external_category_mapping_audit' => ['created_at'],
        'ingest_external_category_mappings' => ['created_at', 'updated_at'],
        'ingest_financial_transactions' => ['external_updated_at', 'occurred_at', 'created_at', 'updated_at'],
        'ingest_normalization_issues' => ['resolved_at', 'created_at'],
        'ingest_ozon_accrual_type_groups' => ['disputed_at', 'created_at', 'updated_at'],
        'ingest_ozon_accrual_types' => ['first_seen_at', 'removed_at', 'version_fetched_at', 'created_at', 'updated_at'],
        'ingest_raw_records' => ['last_seen_at', 'created_at', 'updated_at'],
        'ingest_sync_jobs' => ['started_at', 'finished_at', 'created_at', 'updated_at'],
        'ingestion_credentials' => ['expires_at', 'created_at', 'updated_at'],
        'ingestion_tenant_probes' => ['created_at'],
        'system_counterparties' => ['created_at'],
    ];

    public function extend(Schema $schema): void
    {
        $schema->getTable('ingest_raw_records')->addIndex(['last_seen_at'], 'idx_ingest_raw_record_retention', [], ['where' => '(payload_pruned_at IS NULL)']);
        $schema->getTable('ingest_raw_records')->addIndex(['payload_deletion_attempted_at', 'id'], 'idx_ingest_raw_record_pending_deletion', [], ['where' => '((payload_pruned_at IS NOT NULL) AND (payload_deleted_at IS NULL))']);
        $schema->getTable('ingest_orders')->addIndex(['company_id', 'source', 'connection_ref', 'status_refresh_attempted_at', 'id'], 'idx_ingest_order_refresh_queue', [], ['where' => '((refresh_stopped_at IS NULL) AND ((status)::text <> ALL ((ARRAY[\'delivered\'::character varying, \'cancelled\'::character varying, \'returned\'::character varying])::text[])))']);
        // idx_ingest_raw_record_pending_deletion и idx_ingest_order_refresh_queue в БД начинаются с CASE … END.

        // Миграции создали эти колонки как timestamp(6), маппинг — datetime_immutable (пишет до секунды).
        // Описываем хранение как в БД; конвертация в PHP по-прежнему из маппинга.
        foreach (self::MICROSECOND_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $schema->getTable($table)->getColumn($column)->setType(Type::getType(MicrosecondDateTimeImmutableType::NAME));
            }
        }
    }
}
