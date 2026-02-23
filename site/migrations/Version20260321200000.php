<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_sales schema with MarketplaceSale entity mapping';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_sales')) {
            return;
        }

        $table = $schema->getTable('marketplace_sales');

        if ($table->hasColumn('product_id') && $table->getColumn('product_id')->getNotnull()) {
            $this->addSql('ALTER TABLE marketplace_sales ALTER COLUMN product_id DROP NOT NULL');
        }

        $this->addSql('ALTER TABLE marketplace_sales DROP CONSTRAINT IF EXISTS FK_SALE_RAW_DOCUMENT');
        $this->addSql('DROP INDEX IF EXISTS idx_sale_raw_document');

        $this->addSql('DROP INDEX IF EXISTS uniq_marketplace_order');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_marketplace_srid ON marketplace_sales (marketplace, external_order_id)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_sales')) {
            return;
        }

        $this->addSql('DROP INDEX IF EXISTS uniq_marketplace_srid');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_marketplace_order ON marketplace_sales (marketplace, external_order_id)');

        if ($schema->hasTable('marketplace_raw_documents')) {
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_sale_raw_document ON marketplace_sales (raw_document_id)');
            $this->addSql('ALTER TABLE marketplace_sales ADD CONSTRAINT FK_SALE_RAW_DOCUMENT FOREIGN KEY (raw_document_id) REFERENCES marketplace_raw_documents (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        $table = $schema->getTable('marketplace_sales');
        if ($table->hasColumn('product_id') && !$table->getColumn('product_id')->getNotnull()) {
            $this->addSql('DELETE FROM marketplace_sales WHERE product_id IS NULL');
            $this->addSql('ALTER TABLE marketplace_sales ALTER COLUMN product_id SET NOT NULL');
        }
    }
}
