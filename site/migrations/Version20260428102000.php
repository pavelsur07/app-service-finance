<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428102000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inventory: create stock snapshots table and unique index with NULLS NOT DISTINCT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_stock_snapshots (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                snapshot_session_id UUID NOT NULL,
                snapshot_date DATE NOT NULL,
                snapshot_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                listing_id UUID DEFAULT NULL,
                product_id UUID DEFAULT NULL,
                location_id UUID NOT NULL,
                status VARCHAR NOT NULL,
                quantity NUMERIC(14, 3) NOT NULL,
                source VARCHAR NOT NULL,
                raw_snapshot_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

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

        $this->addSql('CREATE INDEX idx_inventory_stock_company_date ON inventory_stock_snapshots (company_id, snapshot_date)');
        $this->addSql('CREATE INDEX idx_inventory_stock_company_product_date ON inventory_stock_snapshots (company_id, product_id, snapshot_date)');
        $this->addSql('CREATE INDEX idx_inventory_stock_company_listing_date ON inventory_stock_snapshots (company_id, listing_id, snapshot_date)');
        $this->addSql('CREATE INDEX idx_inventory_stock_company_location_date ON inventory_stock_snapshots (company_id, location_id, snapshot_date)');
        $this->addSql('CREATE INDEX idx_inventory_stock_session ON inventory_stock_snapshots (snapshot_session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_inventory_stock_snapshot_day_item');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_stock_company_date');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_stock_company_product_date');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_stock_company_listing_date');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_stock_company_location_date');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_stock_session');
        $this->addSql('DROP TABLE IF EXISTS inventory_stock_snapshots');
    }
}
