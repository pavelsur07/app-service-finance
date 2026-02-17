<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure marketplace_listings table exists (rename legacy marketplace_listing table if needed)';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('marketplace_listings')) {
            return;
        }

        if ($schema->hasTable('marketplace_listing')) {
            $this->addSql('ALTER TABLE marketplace_listing RENAME TO marketplace_listings');

            return;
        }

        $this->addSql('CREATE TABLE marketplace_listings (id UUID NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, marketplace VARCHAR(255) NOT NULL, marketplace_sku VARCHAR(100) NOT NULL, price NUMERIC(10, 2) NOT NULL, discount_price NUMERIC(10, 2) DEFAULT NULL, is_active BOOLEAN NOT NULL, marketplace_data JSON DEFAULT NULL, supplier_sku VARCHAR(255) DEFAULT NULL, size VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_marketplace ON marketplace_listings (company_id, marketplace)');
        $this->addSql('CREATE INDEX idx_marketplace_sku ON marketplace_listings (marketplace, marketplace_sku)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_marketplace ON marketplace_listings (product_id, marketplace)');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_marketplace_sku_size ON marketplace_listings (company_id, marketplace, marketplace_sku, size)');

        if ($schema->hasTable('companies')) {
            $this->addSql('ALTER TABLE marketplace_listings ADD CONSTRAINT FK_LISTING_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if ($schema->hasTable('products')) {
            $this->addSql('ALTER TABLE marketplace_listings ADD CONSTRAINT FK_LISTING_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $this->addSql('DROP TABLE marketplace_listings');
    }
}
