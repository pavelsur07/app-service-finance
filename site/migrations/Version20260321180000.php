<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product purchase prices history table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('product_purchase_prices')) {
            return;
        }

        $this->addSql("CREATE TABLE product_purchase_prices (id UUID NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, effective_from DATE NOT NULL, effective_to DATE DEFAULT NULL, price_amount BIGINT NOT NULL, price_currency VARCHAR(3) DEFAULT 'RUB' NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_purchase_price_company_product_from ON product_purchase_prices (company_id, product_id, effective_from)');
        $this->addSql('CREATE INDEX idx_purchase_price_company_product_to ON product_purchase_prices (company_id, product_id, effective_to)');

        if ($schema->hasTable('companies')) {
            $this->addSql('ALTER TABLE product_purchase_prices ADD CONSTRAINT FK_PURCHASE_PRICE_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if ($schema->hasTable('products')) {
            $this->addSql('ALTER TABLE product_purchase_prices ADD CONSTRAINT FK_PURCHASE_PRICE_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('product_purchase_prices')) {
            return;
        }

        $this->addSql('DROP TABLE product_purchase_prices');
    }
}
