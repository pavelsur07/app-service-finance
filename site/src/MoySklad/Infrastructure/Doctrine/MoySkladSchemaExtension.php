<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
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
