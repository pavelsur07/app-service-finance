<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy product relation from marketplace sales, returns and costs';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('marketplace_sales')) {
            $salesTable = $schema->getTable('marketplace_sales');

            if ($salesTable->hasColumn('product_id')) {
                $this->addSql('ALTER TABLE marketplace_sales DROP CONSTRAINT IF EXISTS FK_SALE_PRODUCT');
                $this->addSql('ALTER TABLE marketplace_sales DROP COLUMN product_id');
            }
        }

        if ($schema->hasTable('marketplace_returns')) {
            $returnsTable = $schema->getTable('marketplace_returns');

            if ($returnsTable->hasColumn('product_id')) {
                $this->addSql('ALTER TABLE marketplace_returns DROP CONSTRAINT IF EXISTS FK_RETURN_PRODUCT');
                $this->addSql('ALTER TABLE marketplace_returns DROP COLUMN product_id');
            }
        }

        if ($schema->hasTable('marketplace_costs')) {
            $costsTable = $schema->getTable('marketplace_costs');

            if ($costsTable->hasColumn('product_id')) {
                $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT IF EXISTS FK_COST_PRODUCT');
                $this->addSql('DROP INDEX IF EXISTS idx_cost_product');
                $this->addSql('ALTER TABLE marketplace_costs DROP COLUMN product_id');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('marketplace_sales')) {
            $salesTable = $schema->getTable('marketplace_sales');

            if (!$salesTable->hasColumn('product_id')) {
                $this->addSql('ALTER TABLE marketplace_sales ADD product_id UUID DEFAULT NULL');
                $this->addSql('ALTER TABLE marketplace_sales ADD CONSTRAINT FK_SALE_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            }
        }

        if ($schema->hasTable('marketplace_returns')) {
            $returnsTable = $schema->getTable('marketplace_returns');

            if (!$returnsTable->hasColumn('product_id')) {
                $this->addSql('ALTER TABLE marketplace_returns ADD product_id UUID DEFAULT NULL');
                $this->addSql('ALTER TABLE marketplace_returns ADD CONSTRAINT FK_RETURN_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            }
        }

        if ($schema->hasTable('marketplace_costs')) {
            $costsTable = $schema->getTable('marketplace_costs');

            if (!$costsTable->hasColumn('product_id')) {
                $this->addSql('ALTER TABLE marketplace_costs ADD product_id UUID DEFAULT NULL');
                $this->addSql('CREATE INDEX IF NOT EXISTS idx_cost_product ON marketplace_costs (product_id)');
                $this->addSql('ALTER TABLE marketplace_costs ADD CONSTRAINT FK_COST_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            }
        }
    }
}
