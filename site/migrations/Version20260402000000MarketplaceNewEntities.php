<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402000000MarketplaceNewEntities extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_advertising_costs and marketplace_orders tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_advertising_costs (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                listing_id UUID NOT NULL,
                marketplace VARCHAR(50) NOT NULL,
                date DATE NOT NULL,
                advertising_type VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                analytics_data JSONB NOT NULL DEFAULT '{}',
                external_campaign_id VARCHAR(255) NOT NULL DEFAULT '',
                raw_data JSONB,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT uq_mp_adv_cost_company_listing_date_type_campaign
                    UNIQUE (company_id, listing_id, date, advertising_type, external_campaign_id),
                CONSTRAINT chk_mp_adv_cost_marketplace
                    CHECK (marketplace IN ('wildberries','ozon','yandex_market','sber_megamarket')),
                CONSTRAINT chk_mp_adv_cost_advertising_type
                    CHECK (advertising_type IN ('cpc','other','external')),
                CONSTRAINT chk_mp_adv_cost_amount_non_negative
                    CHECK (amount >= 0)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_mp_adv_cost_company ON marketplace_advertising_costs (company_id)');
        $this->addSql('CREATE INDEX idx_mp_adv_cost_company_date ON marketplace_advertising_costs (company_id, date)');
        $this->addSql('CREATE INDEX idx_mp_adv_cost_listing_date ON marketplace_advertising_costs (listing_id, date)');

        $this->addSql('COMMENT ON COLUMN marketplace_advertising_costs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN marketplace_advertising_costs.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN marketplace_advertising_costs.date IS \'(DC2Type:date_immutable)\'');

        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_orders (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                listing_id UUID NOT NULL,
                marketplace VARCHAR(50) NOT NULL,
                external_order_id VARCHAR(100) NOT NULL,
                order_date DATE NOT NULL,
                quantity INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL,
                raw_document_id UUID,
                raw_data JSONB,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT uq_mp_order_marketplace_external_id
                    UNIQUE (company_id, marketplace, external_order_id),
                CONSTRAINT chk_mp_order_marketplace
                    CHECK (marketplace IN ('wildberries','ozon','yandex_market','sber_megamarket')),
                CONSTRAINT chk_mp_order_status
                    CHECK (status IN ('ordered','delivered','returned','cancelled')),
                CONSTRAINT chk_mp_order_quantity_positive
                    CHECK (quantity > 0)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_mp_order_company ON marketplace_orders (company_id)');
        $this->addSql('CREATE INDEX idx_mp_order_company_date ON marketplace_orders (company_id, order_date)');
        $this->addSql('CREATE INDEX idx_mp_order_listing_date ON marketplace_orders (listing_id, order_date)');
        $this->addSql('CREATE INDEX idx_mp_order_company_status ON marketplace_orders (company_id, status)');

        $this->addSql('COMMENT ON COLUMN marketplace_orders.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN marketplace_orders.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN marketplace_orders.order_date IS \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_orders');
        $this->addSql('DROP TABLE marketplace_advertising_costs');
    }
}
