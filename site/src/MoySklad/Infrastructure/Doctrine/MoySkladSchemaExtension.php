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
        $jobs = $schema->createTable('moysklad_mapping_bulk_jobs');
        foreach (['id', 'company_id', 'connection_id'] as $column) {
            $jobs->addColumn($column, 'guid');
        }
        $jobs->addColumn('marketplace', 'string', ['length' => 20]);
        $jobs->addColumn('actor_user_id', 'guid');
        $jobs->addColumn('origin', 'string', ['length' => 8]);
        $jobs->addColumn('scope', 'string', ['length' => 12]);
        $jobs->addColumn('run_id', 'guid', ['notnull' => false]);
        $jobs->addColumn('size_characteristic_id', 'string', ['length' => 255, 'notnull' => false]);
        $jobs->addColumn('status', 'string', ['length' => 12]);
        $jobs->addColumn('after_listing_id', 'guid', ['notnull' => false]);
        $jobs->addColumn('total', 'integer', ['notnull' => false]);
        foreach (['processed', 'accepted', 'skipped', 'conflicts', 'failed'] as $column) {
            $jobs->addColumn($column, 'integer', ['default' => 0]);
        }
        $jobs->addColumn('error_reason', 'string', ['length' => 64, 'notnull' => false]);
        $jobs->addColumn('created_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $jobs->addColumn('heartbeat_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $jobs->addColumn('finished_at', 'datetime_immutable_utc_ms', ['precision' => 3, 'notnull' => false]);
        $jobs->addColumn('undo_status', 'string', ['length' => 12, 'notnull' => false]);
        $jobs->addColumn('undo_actor_user_id', 'guid', ['notnull' => false]);
        $jobs->addColumn('undone', 'integer', ['default' => 0]);
        $jobs->addColumn('undo_skipped', 'integer', ['default' => 0]);
        $jobs->addColumn('undo_finished_at', 'datetime_immutable_utc_ms', ['precision' => 3, 'notnull' => false]);
        $jobs->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $jobs->addUniqueIndex(['company_id', 'id'], 'uniq_ms_bulk_tenant_id');
        $jobs->addIndex(['company_id', 'connection_id', 'marketplace', 'created_at', 'id'], 'idx_ms_bulk_scope');
        $jobs->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_bulk_connection');
        $items = $schema->createTable('moysklad_mapping_bulk_job_items');
        foreach (['company_id', 'job_id', 'listing_id'] as $column) {
            $items->addColumn($column, 'guid');
        }
        $items->addColumn('target_type', 'string', ['length' => 8, 'notnull' => false]);
        $items->addColumn('target_external_id', 'guid', ['notnull' => false]);
        $items->addColumn('status', 'string', ['length' => 12]);
        $items->addColumn('message', 'string', ['length' => 255, 'notnull' => false]);
        $items->addColumn('revision', 'integer', ['notnull' => false]);
        $items->addColumn('undo_status', 'string', ['length' => 12, 'notnull' => false]);
        $items->addColumn('processed_at', 'datetime_immutable_utc_ms', ['precision' => 3, 'notnull' => false]);
        $items->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'job_id', 'listing_id')->create());
        $items->addIndex(['company_id', 'job_id', 'status', 'listing_id'], 'idx_ms_bulk_items_status');
        $items->addForeignKeyConstraint('moysklad_mapping_bulk_jobs', ['company_id', 'job_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_bulk_item_job');
        $auto = $schema->createTable('moysklad_mapping_auto_apply');
        foreach (['company_id', 'connection_id'] as $column) {
            $auto->addColumn($column, 'guid');
        }
        $auto->addColumn('marketplace', 'string', ['length' => 20]);
        $auto->addColumn('enabled', 'boolean', ['default' => false]);
        $auto->addColumn('actor_user_id', 'guid');
        $auto->addColumn('size_characteristic_id', 'string', ['length' => 255, 'notnull' => false]);
        $auto->addColumn('updated_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $auto->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'connection_id', 'marketplace')->create());
        $auto->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_auto_apply_connection');
        $this->storeBalance($schema);
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

    /** Склады → счета Баланса: назначения, настройки записи, оценка снимка и проводки по счёту-дню. */
    private function storeBalance(Schema $schema): void
    {
        $settings = $schema->createTable('moysklad_store_balance_settings');
        foreach (['company_id', 'connection_id', 'actor_user_id'] as $column) {
            $settings->addColumn($column, 'guid');
        }
        $settings->addColumn('counter_account_id', 'guid', ['notnull' => false]);
        $settings->addColumn('enabled', 'boolean', ['default' => false]);
        $settings->addColumn('updated_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $settings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'connection_id')->create());
        $settings->addForeignKeyConstraint('moysklad_connections', ['company_id', 'connection_id'], ['company_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_store_balance_settings_connection');

        $links = $schema->createTable('moysklad_store_balance_links');
        foreach (['company_id', 'connection_id', 'store_external_id', 'actor_user_id'] as $column) {
            $links->addColumn($column, 'guid');
        }
        $links->addColumn('balance_account_id', 'guid', ['notnull' => false]);
        $links->addColumn('excluded', 'boolean', ['default' => false]);
        $links->addColumn('updated_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $links->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'connection_id', 'store_external_id')->create());
        $links->addForeignKeyConstraint('moysklad_stores', ['company_id', 'connection_id', 'store_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'RESTRICT'], 'fk_ms_store_balance_links_store');

        $runs = $schema->createTable('moysklad_store_balance_runs');
        foreach (['company_id', 'connection_id', 'snapshot_id'] as $column) {
            $runs->addColumn($column, 'guid');
        }
        $runs->addColumn('valuation_date', 'date_immutable');
        $runs->addColumn('status', 'string', ['length' => 16]);
        $runs->addColumn('error_reason', 'string', ['length' => 64, 'notnull' => false]);
        $runs->addColumn('attempts', 'integer', ['default' => 0]);
        $runs->addColumn('created_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $runs->addColumn('finished_at', 'datetime_immutable_utc_ms', ['precision' => 3, 'notnull' => false]);
        $runs->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'snapshot_id')->create());
        $runs->addIndex(['company_id', 'connection_id', 'valuation_date'], 'idx_ms_store_balance_runs_date');
        $runs->addForeignKeyConstraint('moysklad_stock_snapshots', ['company_id', 'connection_id', 'snapshot_id'], ['company_id', 'connection_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_ms_store_balance_runs_snapshot');

        $valuations = $schema->createTable('moysklad_store_valuations');
        foreach (['company_id', 'connection_id', 'snapshot_id', 'store_external_id'] as $column) {
            $valuations->addColumn($column, 'guid');
        }
        $valuations->addColumn('valuation_date', 'date_immutable');
        $valuations->addColumn('quantity', 'decimal', ['precision' => 30, 'scale' => 10]);
        $valuations->addColumn('amount_minor', 'bigint');
        $valuations->addColumn('unpriced_positions', 'integer', ['default' => 0]);
        $valuations->addColumn('missing_positions', 'integer', ['default' => 0]);
        $valuations->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'snapshot_id', 'store_external_id')->create());
        $valuations->addIndex(['company_id', 'connection_id', 'store_external_id'], 'idx_ms_store_valuations_store');
        $valuations->addForeignKeyConstraint('moysklad_store_balance_runs', ['company_id', 'snapshot_id'], ['company_id', 'snapshot_id'], ['onDelete' => 'RESTRICT'], 'fk_ms_store_valuations_run');
        $valuations->addForeignKeyConstraint('moysklad_stores', ['company_id', 'connection_id', 'store_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'RESTRICT'], 'fk_ms_store_valuations_store');

        $postings = $schema->createTable('moysklad_store_balance_postings');
        foreach (['company_id', 'connection_id', 'balance_account_id', 'snapshot_id'] as $column) {
            $postings->addColumn($column, 'guid');
        }
        $postings->addColumn('valuation_date', 'date_immutable');
        $postings->addColumn('target_minor', 'bigint');
        $postings->addColumn('status', 'string', ['length' => 16]);
        $postings->addColumn('reason', 'string', ['length' => 255, 'notnull' => false]);
        $postings->addColumn('operation_id', 'guid', ['notnull' => false]);
        $postings->addColumn('updated_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $postings->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'connection_id', 'valuation_date', 'balance_account_id')->create());
        $postings->addIndex(['company_id', 'snapshot_id'], 'idx_ms_store_balance_postings_run');
        $postings->addForeignKeyConstraint('moysklad_store_balance_runs', ['company_id', 'snapshot_id'], ['company_id', 'snapshot_id'], ['onDelete' => 'RESTRICT'], 'fk_ms_store_balance_postings_run');

        $suggestions = $schema->createTable('moysklad_store_account_suggestions');
        foreach (['company_id', 'connection_id', 'store_external_id', 'ai_task_id'] as $column) {
            $suggestions->addColumn($column, 'guid');
        }
        $suggestions->addColumn('store_fingerprint', 'string', ['length' => 64]);
        $suggestions->addColumn('prompt_version', 'string', ['length' => 64]);
        $suggestions->addColumn('status', 'string', ['length' => 16]);
        $suggestions->addColumn('account_code', 'string', ['length' => 64, 'notnull' => false]);
        $suggestions->addColumn('excluded', 'boolean', ['default' => false]);
        $suggestions->addColumn('confident', 'boolean', ['default' => false]);
        $suggestions->addColumn('reason', 'string', ['length' => 500, 'notnull' => false]);
        $suggestions->addColumn('requested_at', 'datetime_immutable_utc_ms', ['precision' => 3]);
        $suggestions->addColumn('completed_at', 'datetime_immutable_utc_ms', ['precision' => 3, 'notnull' => false]);
        $suggestions->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('company_id', 'connection_id', 'store_external_id')->create());
        $suggestions->addUniqueIndex(['company_id', 'ai_task_id'], 'uniq_ms_store_account_suggestions_task');
        $suggestions->addForeignKeyConstraint('moysklad_stores', ['company_id', 'connection_id', 'store_external_id'], ['company_id', 'connection_id', 'external_id'], ['onDelete' => 'CASCADE'], 'fk_ms_store_account_suggestions_store');
    }
}
