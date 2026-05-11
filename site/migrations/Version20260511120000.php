<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reserved quantity and enum-based mapping status to inventory stock snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE inventory_stock_snapshots ADD reserved_quantity NUMERIC(14, 3) DEFAULT 0 NOT NULL");
        $this->addSql("ALTER TABLE inventory_stock_snapshots ADD source_sku VARCHAR(100) DEFAULT NULL");
        $this->addSql("ALTER TABLE inventory_stock_snapshots ADD source_offer_id VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE inventory_stock_snapshots ADD fulfillment_type VARCHAR(50) DEFAULT NULL");
        $this->addSql("ALTER TABLE inventory_stock_snapshots ADD mapping_status VARCHAR(50) DEFAULT 'unmapped' NOT NULL");
        $this->addSql('DROP INDEX IF EXISTS uniq_inventory_stock_snapshot_day_item');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_inventory_stock_snapshot_day_item
            ON inventory_stock_snapshots (
                company_id,
                snapshot_date,
                source,
                source_sku,
                fulfillment_type,
                location_id,
                status
            )
            NULLS NOT DISTINCT
        SQL);

        $this->addSql("CREATE INDEX idx_inventory_stock_company_source_snapshot_at ON inventory_stock_snapshots (company_id, source, snapshot_at)");
        $this->addSql("CREATE INDEX idx_inventory_stock_company_source_sku ON inventory_stock_snapshots (company_id, source_sku)");
        $this->addSql("CREATE INDEX idx_inventory_stock_company_mapping_status ON inventory_stock_snapshots (company_id, mapping_status)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_stock_company_mapping_status');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_stock_company_source_sku');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_stock_company_source_snapshot_at');
        $this->addSql('DROP INDEX IF EXISTS uniq_inventory_stock_snapshot_day_item');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_inventory_stock_snapshot_day_item
            ON inventory_stock_snapshots (
                company_id,
                snapshot_date,
                listing_id,
                product_id,
                location_id,
                status
            )
            NULLS NOT DISTINCT
        SQL);

        $this->addSql('ALTER TABLE inventory_stock_snapshots DROP mapping_status');
        $this->addSql('ALTER TABLE inventory_stock_snapshots DROP fulfillment_type');
        $this->addSql('ALTER TABLE inventory_stock_snapshots DROP source_offer_id');
        $this->addSql('ALTER TABLE inventory_stock_snapshots DROP source_sku');
        $this->addSql('ALTER TABLE inventory_stock_snapshots DROP reserved_quantity');
    }
}
