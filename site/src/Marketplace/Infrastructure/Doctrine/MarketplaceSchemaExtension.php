<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;

/**
 * Маркетплейсы: назначения тегов листингам (ListingTagAssignmentRepository, DBAL), ключи и частичные индексы.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class MarketplaceSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $table = $schema->createTable('marketplace_listing_tag_assignments');
        $table->addColumn('listing_id', 'guid');
        $table->addColumn('tag_id', 'guid');
        $table->addColumn('company_id', 'guid');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('listing_id', 'tag_id')->create());
        $table->addIndex(['company_id', 'tag_id'], 'idx_listing_tag_assign_company_tag');
        $table->addIndex(['listing_id'], 'IDX_E77B3527D4619D1A');
        $table->addIndex(['tag_id'], 'IDX_E77B3527BAD26311');
        $schema->getTable('marketplace_listing_tag_assignments')->addForeignKeyConstraint('marketplace_listings', ['listing_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_listing_tag_assign_listing');
        $schema->getTable('marketplace_listing_tag_assignments')->addForeignKeyConstraint('marketplace_listing_tags', ['tag_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_listing_tag_assign_tag');

        $schema->getTable('marketplace_costs')->addForeignKeyConstraint('marketplace_raw_documents', ['raw_document_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_cost_raw_document');
        $schema->getTable('marketplace_raw_documents')->addUniqueIndex(['company_id', 'marketplace', 'document_type', 'period_from', 'period_to'], 'uniq_mrd_init_sync_period', ['where' => '((processing_status IS NULL) OR ((processing_status)::text <> \'failed\'::text))']);
        $schema->getTable('marketplace_sale_mappings')->addUniqueIndex(['company_id', 'marketplace', 'operation_type', 'amount_source'], 'uniq_active_sale_mapping_source', ['where' => 'is_active']);
        $schema->getTable('marketplace_listings')->addUniqueIndex(['company_id', 'marketplace', 'product_id'], 'uniq_company_marketplace_product', ['where' => '(product_id IS NOT NULL)']);
        $schema->getTable('marketplace_mapping_errors')->addIndex(['resolved_at'], 'idx_mapping_errors_unresolved', [], ['where' => '(resolved_at IS NULL)']);
        // uniq_mrd_init_sync_period в БД содержит ещё COALESCE(api_endpoint, '').
    }
}
