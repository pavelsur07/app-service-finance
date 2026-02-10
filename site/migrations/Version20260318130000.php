<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_sales, marketplace_costs, marketplace_returns tables';
    }

    public function up(Schema $schema): void
    {
        // -- marketplace_sales (продажи маркетплейсов)
        $this->addSql('CREATE TABLE marketplace_sales (id UUID NOT NULL, company_id UUID NOT NULL, listing_id UUID NOT NULL, product_id UUID NOT NULL, document_id UUID DEFAULT NULL, marketplace VARCHAR(255) NOT NULL, external_order_id VARCHAR(100) NOT NULL, sale_date DATE NOT NULL, quantity INT NOT NULL, price_per_unit NUMERIC(10, 2) NOT NULL, total_revenue NUMERIC(10, 2) NOT NULL, raw_data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_sale_date ON marketplace_sales (company_id, sale_date)');
        $this->addSql('CREATE INDEX idx_marketplace_order ON marketplace_sales (marketplace, external_order_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_marketplace_order ON marketplace_sales (marketplace, external_order_id)');
        $this->addSql('ALTER TABLE marketplace_sales ADD CONSTRAINT FK_SALE_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_sales ADD CONSTRAINT FK_SALE_LISTING FOREIGN KEY (listing_id) REFERENCES marketplace_listings (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_sales ADD CONSTRAINT FK_SALE_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_sales ADD CONSTRAINT FK_SALE_DOCUMENT FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // -- marketplace_costs (затраты маркетплейсов)
        $this->addSql('CREATE TABLE marketplace_costs (id UUID NOT NULL, company_id UUID NOT NULL, category_id UUID NOT NULL, product_id UUID DEFAULT NULL, sale_id UUID DEFAULT NULL, marketplace VARCHAR(255) NOT NULL, amount NUMERIC(10, 2) NOT NULL, cost_date DATE NOT NULL, description TEXT DEFAULT NULL, external_id VARCHAR(100) DEFAULT NULL, raw_data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_cost_date ON marketplace_costs (company_id, cost_date)');
        $this->addSql('CREATE INDEX idx_cost_category ON marketplace_costs (category_id)');
        $this->addSql('CREATE INDEX idx_cost_product ON marketplace_costs (product_id)');
        $this->addSql('CREATE INDEX idx_cost_sale ON marketplace_costs (sale_id)');
        $this->addSql('ALTER TABLE marketplace_costs ADD CONSTRAINT FK_COST_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_costs ADD CONSTRAINT FK_COST_CATEGORY FOREIGN KEY (category_id) REFERENCES marketplace_cost_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_costs ADD CONSTRAINT FK_COST_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_costs ADD CONSTRAINT FK_COST_SALE FOREIGN KEY (sale_id) REFERENCES marketplace_sales (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // -- marketplace_returns (возвраты маркетплейсов)
        $this->addSql('CREATE TABLE marketplace_returns (id UUID NOT NULL, company_id UUID NOT NULL, sale_id UUID DEFAULT NULL, product_id UUID NOT NULL, marketplace VARCHAR(255) NOT NULL, external_return_id VARCHAR(100) DEFAULT NULL, return_date DATE NOT NULL, quantity INT NOT NULL, refund_amount NUMERIC(10, 2) NOT NULL, return_reason VARCHAR(100) DEFAULT NULL, return_logistics_cost NUMERIC(10, 2) DEFAULT NULL, raw_data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_return_date ON marketplace_returns (company_id, return_date)');
        $this->addSql('CREATE INDEX idx_return_sale ON marketplace_returns (sale_id)');
        $this->addSql('ALTER TABLE marketplace_returns ADD CONSTRAINT FK_RETURN_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_returns ADD CONSTRAINT FK_RETURN_SALE FOREIGN KEY (sale_id) REFERENCES marketplace_sales (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_returns ADD CONSTRAINT FK_RETURN_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT FK_COST_SALE');
        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT FK_COST_PRODUCT');
        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT FK_COST_CATEGORY');
        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT FK_COST_COMPANY');
        $this->addSql('ALTER TABLE marketplace_returns DROP CONSTRAINT FK_RETURN_PRODUCT');
        $this->addSql('ALTER TABLE marketplace_returns DROP CONSTRAINT FK_RETURN_SALE');
        $this->addSql('ALTER TABLE marketplace_returns DROP CONSTRAINT FK_RETURN_COMPANY');
        $this->addSql('ALTER TABLE marketplace_sales DROP CONSTRAINT FK_SALE_DOCUMENT');
        $this->addSql('ALTER TABLE marketplace_sales DROP CONSTRAINT FK_SALE_PRODUCT');
        $this->addSql('ALTER TABLE marketplace_sales DROP CONSTRAINT FK_SALE_LISTING');
        $this->addSql('ALTER TABLE marketplace_sales DROP CONSTRAINT FK_SALE_COMPANY');
        $this->addSql('DROP TABLE marketplace_costs');
        $this->addSql('DROP TABLE marketplace_returns');
        $this->addSql('DROP TABLE marketplace_sales');
    }
}
