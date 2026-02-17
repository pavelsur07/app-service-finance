<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure products table exists for marketplace_listings joins';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('products')) {
            return;
        }

        $this->addSql('CREATE TABLE products (id UUID NOT NULL, company_id UUID NOT NULL, sku VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, purchase_price NUMERIC(10, 2) NOT NULL, weight_kg NUMERIC(8, 3) DEFAULT NULL, dimensions JSON DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_sku ON products (company_id, sku)');

        if ($schema->hasTable('companies')) {
            $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_PRODUCT_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('products')) {
            return;
        }

        $this->addSql('DROP TABLE products');
    }
}
