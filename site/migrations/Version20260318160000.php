<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update marketplace_listings schema for SKU and size-level uniqueness';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $table = $schema->getTable('marketplace_listings');

        if (!$table->hasColumn('supplier_sku')) {
            $this->addSql('ALTER TABLE marketplace_listings ADD supplier_sku VARCHAR(255) DEFAULT NULL');
        }

        if (!$table->hasColumn('size')) {
            $this->addSql('ALTER TABLE marketplace_listings ADD size VARCHAR(50) DEFAULT NULL');
        }

        $this->addSql('DROP INDEX IF EXISTS uniq_product_marketplace');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_company_marketplace_sku_size ON marketplace_listings (company_id, marketplace, marketplace_sku, size)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $table = $schema->getTable('marketplace_listings');

        $this->addSql('DROP INDEX IF EXISTS uniq_company_marketplace_sku_size');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_product_marketplace ON marketplace_listings (product_id, marketplace)');

        if ($table->hasColumn('size')) {
            $this->addSql('ALTER TABLE marketplace_listings DROP COLUMN size');
        }

        if ($table->hasColumn('supplier_sku')) {
            $this->addSql('ALTER TABLE marketplace_listings DROP COLUMN supplier_sku');
        }
    }
}
