<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create products, marketplace_listings, marketplace_cost_categories tables';
    }

    public function up(Schema $schema): void
    {
        // -- products (каталог товаров)
        $this->addSql('CREATE TABLE products (id UUID NOT NULL, company_id UUID NOT NULL, sku VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, purchase_price NUMERIC(10, 2) NOT NULL, weight_kg NUMERIC(8, 3) DEFAULT NULL, dimensions JSON DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_sku ON products (company_id, sku)');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_PRODUCT_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // -- marketplace_listings (листинги на маркетплейсах)
        $this->addSql('CREATE TABLE marketplace_listings (id UUID NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, marketplace VARCHAR(255) NOT NULL, marketplace_sku VARCHAR(100) NOT NULL, price NUMERIC(10, 2) NOT NULL, discount_price NUMERIC(10, 2) DEFAULT NULL, is_active BOOLEAN NOT NULL, marketplace_data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_marketplace ON marketplace_listings (company_id, marketplace)');
        $this->addSql('CREATE INDEX idx_marketplace_sku ON marketplace_listings (marketplace, marketplace_sku)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_marketplace ON marketplace_listings (product_id, marketplace)');
        $this->addSql('ALTER TABLE marketplace_listings ADD CONSTRAINT FK_LISTING_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_listings ADD CONSTRAINT FK_LISTING_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // -- marketplace_cost_categories (справочник затрат)
        $this->addSql('CREATE TABLE marketplace_cost_categories (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(50) NOT NULL, pl_category_id UUID DEFAULT NULL, description TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_cost_category_company ON marketplace_cost_categories (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_code ON marketplace_cost_categories (company_id, code)');
        $this->addSql('ALTER TABLE marketplace_cost_categories ADD CONSTRAINT FK_COST_CAT_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_cost_categories ADD CONSTRAINT FK_COST_CAT_PL_CATEGORY FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listings DROP CONSTRAINT FK_LISTING_PRODUCT');
        $this->addSql('ALTER TABLE marketplace_listings DROP CONSTRAINT FK_LISTING_COMPANY');
        $this->addSql('ALTER TABLE marketplace_cost_categories DROP CONSTRAINT FK_COST_CAT_PL_CATEGORY');
        $this->addSql('ALTER TABLE marketplace_cost_categories DROP CONSTRAINT FK_COST_CAT_COMPANY');
        $this->addSql('ALTER TABLE products DROP CONSTRAINT FK_PRODUCT_COMPANY');
        $this->addSql('DROP TABLE marketplace_listings');
        $this->addSql('DROP TABLE marketplace_cost_categories');
        $this->addSql('DROP TABLE products');
    }
}
