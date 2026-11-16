<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;

/**
 * МойСклад: составные ключи `(company_id, connection_id, …)` — строки только своего подключения и компании.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class MoySkladSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $runs = $schema->createTable('moysklad_mapping_diagnostic_runs');
        foreach (['id', 'company_id', 'connection_id'] as $column) {
            $runs->addColumn($column, 'guid');
        }
        $runs->addColumn('marketplace', 'string', ['length' => 20]);
        $runs->addColumn('size_characteristic_id', 'string', ['length' => 255, 'notnull' => false]);
        $runs->addColumn('rule_version', 'string', ['length' => 40]);
        foreach (['settings_fingerprint', 'input_fingerprint'] as $column) {
            $runs->addColumn($column, 'string', ['length' => 64]);
        }
        $runs->addColumn('status', 'string', ['length' => 16]);
        $runs->addColumn('requested_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $runs->addColumn('finished_at', 'datetime_immutable_utc_ms', ['precision' => 3, 'notnull' => false]);
        $runs->addColumn('after_listing_id', 'guid', ['notnull' => false]);
        $runs->addColumn('processed', 'integer', ['default' => 0]);
        $runs->addColumn('total', 'integer');
        $runs->addColumn('counts', 'jsonb', ['default' => '{}']);
        $runs->addColumn('error_reason', 'string', ['length' => 64, 'notnull' => false]);
        $runs->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $runs->addUniqueIndex(['company_id', 'id'], 'uniq_ms_diagnostic_tenant_id');
        $runs->addIndex(['company_id', 'connection_id', 'marketplace', 'requested_at', 'id'], 'idx_ms_diagnostic_scope');
        $runs->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_diagnostic_connection');
        $rows = $schema->createTable('moysklad_mapping_diagnostic_rows');
        foreach (['company_id', 'run_id', 'listing_id'] as $column) {
            $rows->addColumn($column, 'guid');
        }
        $rows->addColumn('status', 'string', ['length' => 32]);
        $rows->addColumn('reasons', 'jsonb');
        $rows->addColumn('manual_review', 'boolean');
        $rows->addColumn('payload', 'jsonb');
        $rows->addColumn('checked_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $rows->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'run_id', 'listing_id')->create());
        $rows->addIndex(['company_id', 'run_id', 'status', 'listing_id'], 'idx_ms_diagnostic_rows_status');
        $rows->addForeignKeyConstraint('moysklad_mapping_diagnostic_runs', ['company_id', 'run_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_diagnostic_row_run');
        $reviews = $schema->createTable('moysklad_mapping_package_reviews');
        foreach (['company_id', 'connection_id', 'listing_id', 'actor_user_id'] as $column) {
            $reviews->addColumn($column, 'guid');
        }
        $reviews->addColumn('revision', 'integer');
        $reviews->addColumn('source_fingerprint', 'string', ['length' => 64]);
        $reviews->addColumn('target_fingerprint', 'string', ['length' => 64]);
        $reviews->addColumn('checked_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $reviews->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'connection_id', 'listing_id', 'revision', 'source_fingerprint', 'target_fingerprint')->create());
        $reviews->addForeignKeyConstraint('moysklad_listing_mapping_revisions', ['company_id', 'connection_id', 'listing_id', 'revision'], ['company_id', 'connection_id', 'listing_id', 'revision'], ['onDelete' => 'RESTRICT'], 'fk_ms_review_revision');
        $settings = $schema->createTable('moysklad_mapping_settings');
        $settings->addColumn('company_id', 'guid');
        $settings->addColumn('connection_id', 'guid');
        $settings->addColumn('marketplace', 'string', ['length' => 20]);
        $settings->addColumn('wb_trailing_w', 'boolean', ['default' => false]);
        $settings->addColumn('version', 'integer', ['default' => 0]);
        $settings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'connection_id', 'marketplace')->create());
        $settings->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_settings_connection');
        $schema->getTable('moysklad_listing_mapping_revisions')->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_mapping_connection');
        $schema->getTable('moysklad_listing_mapping_revisions')->addForeignKeyConstraint('moysklad_products', ['company_id', 'connection_id', 'product_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'RESTRICT'], 'fk_ms_mapping_product');
        $schema->getTable('moysklad_listing_mapping_revisions')->addForeignKeyConstraint('moysklad_variants', ['company_id', 'connection_id', 'variant_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'RESTRICT'], 'fk_ms_mapping_variant');
        $schema->getTable('moysklad_products')->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_products_connection');
        $schema->getTable('moysklad_stores')->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_stores_connection');
        $schema->getTable('moysklad_stock_snapshot_lines')->addForeignKeyConstraint('moysklad_stores', ['company_id', 'connection_id', 'store_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_stock_lines_store');
        $schema->getTable('moysklad_stock_snapshot_lines')->addForeignKeyConstraint('moysklad_stock_snapshots', ['company_id', 'connection_id', 'snapshot_id'], ['company_id', 'connection_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_stock_lines_snapshot');
        $schema->getTable('moysklad_stock_snapshot_lines')->addForeignKeyConstraint('moysklad_products', ['company_id', 'connection_id', 'product_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_stock_lines_product');
        $schema->getTable('moysklad_stock_snapshot_lines')->addForeignKeyConstraint('moysklad_variants', ['company_id', 'connection_id', 'variant_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_stock_lines_variant');
        $schema->getTable('moysklad_counterparties')->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_counterparties_connection');
        $schema->getTable('moysklad_variants')->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_variants_connection');
        $schema->getTable('moysklad_variants')->addForeignKeyConstraint('moysklad_products', ['company_id', 'connection_id', 'product_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_variants_product');
        $schema->getTable('moysklad_sync_cursors')->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_sync_cursors_connection');
        $schema->getTable('moysklad_stock_snapshots')->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_stock_snapshots_connection');
        $schema->getTable('moysklad_sync_runs')->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_moysklad_sync_runs_connection');
    }
}
