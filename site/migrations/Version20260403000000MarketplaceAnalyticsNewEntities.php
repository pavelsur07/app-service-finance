<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260403000000MarketplaceAnalyticsNewEntities extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add listing_daily_snapshots and unit_economy_cost_mappings tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE listing_daily_snapshots (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                listing_id UUID NOT NULL,
                marketplace VARCHAR(50) NOT NULL,
                snapshot_date DATE NOT NULL,
                revenue DECIMAL(15, 2) NOT NULL DEFAULT '0.00',
                refunds DECIMAL(15, 2) NOT NULL DEFAULT '0.00',
                sales_quantity INTEGER NOT NULL DEFAULT 0,
                returns_quantity INTEGER NOT NULL DEFAULT 0,
                orders_quantity INTEGER NOT NULL DEFAULT 0,
                delivered_quantity INTEGER NOT NULL DEFAULT 0,
                avg_sale_price DECIMAL(10, 2) NOT NULL DEFAULT '0.00',
                cost_price DECIMAL(10, 2) DEFAULT NULL,
                total_cost_price DECIMAL(15, 2) DEFAULT NULL,
                cost_breakdown JSONB NOT NULL DEFAULT '{}',
                advertising_details JSONB NOT NULL DEFAULT '{}',
                data_quality JSONB NOT NULL DEFAULT '[]',
                calculated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE listing_daily_snapshots
                ADD CONSTRAINT uq_snapshot_company_listing_date
                    UNIQUE (company_id, listing_id, snapshot_date)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE listing_daily_snapshots
                ADD CONSTRAINT chk_snapshot_marketplace
                    CHECK (marketplace IN ('wildberries', 'ozon', 'yandex_market', 'sber_megamarket'))
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE listing_daily_snapshots
                ADD CONSTRAINT chk_snapshot_revenue_non_negative
                    CHECK (revenue >= 0)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE listing_daily_snapshots
                ADD CONSTRAINT chk_snapshot_refunds_non_negative
                    CHECK (refunds >= 0)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE listing_daily_snapshots
                ADD CONSTRAINT chk_snapshot_sales_quantity_non_negative
                    CHECK (sales_quantity >= 0)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE listing_daily_snapshots
                ADD CONSTRAINT chk_snapshot_returns_quantity_non_negative
                    CHECK (returns_quantity >= 0)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE listing_daily_snapshots
                ADD CONSTRAINT chk_snapshot_orders_quantity_non_negative
                    CHECK (orders_quantity >= 0)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE listing_daily_snapshots
                ADD CONSTRAINT chk_snapshot_delivered_quantity_non_negative
                    CHECK (delivered_quantity >= 0)
        SQL);

        $this->addSql('CREATE INDEX idx_snapshot_company ON listing_daily_snapshots (company_id)');
        $this->addSql('CREATE INDEX idx_snapshot_company_date ON listing_daily_snapshots (company_id, snapshot_date)');
        $this->addSql('CREATE INDEX idx_snapshot_company_marketplace_date ON listing_daily_snapshots (company_id, marketplace, snapshot_date)');
        $this->addSql('CREATE INDEX idx_snapshot_listing_date ON listing_daily_snapshots (listing_id, snapshot_date)');

        $this->addSql(<<<'SQL'
            CREATE TABLE unit_economy_cost_mappings (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                marketplace VARCHAR(50) NOT NULL,
                cost_category_code VARCHAR(50) NOT NULL,
                unit_economy_cost_type VARCHAR(50) NOT NULL,
                is_system BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD CONSTRAINT uq_cost_mapping_company_marketplace_code
                    UNIQUE (company_id, marketplace, cost_category_code)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD CONSTRAINT chk_cost_mapping_marketplace
                    CHECK (marketplace IN ('wildberries', 'ozon', 'yandex_market', 'sber_megamarket'))
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD CONSTRAINT chk_cost_mapping_unit_economy_cost_type
                    CHECK (unit_economy_cost_type IN (
                        'logistics_to',
                        'logistics_back',
                        'storage',
                        'advertising_cpc',
                        'advertising_other',
                        'advertising_external',
                        'commission',
                        'other'
                    ))
        SQL);

        $this->addSql('CREATE INDEX idx_cost_mapping_company ON unit_economy_cost_mappings (company_id)');
        $this->addSql('CREATE INDEX idx_cost_mapping_company_marketplace ON unit_economy_cost_mappings (company_id, marketplace)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE unit_economy_cost_mappings');
        $this->addSql('DROP TABLE listing_daily_snapshots');
    }
}
