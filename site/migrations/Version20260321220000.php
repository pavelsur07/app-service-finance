<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_returns schema with MarketplaceReturn entity mapping';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_returns')) {
            return;
        }

        $table = $schema->getTable('marketplace_returns');

        $this->addSql('ALTER TABLE marketplace_returns DROP CONSTRAINT IF EXISTS FK_RETURN_RAW_DOCUMENT');
        $this->addSql('DROP INDEX IF EXISTS idx_return_raw_document');

        if ($table->hasColumn('return_logistics_cost')) {
            $this->addSql('ALTER TABLE marketplace_returns DROP COLUMN return_logistics_cost');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_returns')) {
            return;
        }

        $table = $schema->getTable('marketplace_returns');

        if (!$table->hasColumn('return_logistics_cost')) {
            $this->addSql('ALTER TABLE marketplace_returns ADD return_logistics_cost NUMERIC(10, 2) DEFAULT NULL');
        }

        if ($schema->hasTable('marketplace_raw_documents')) {
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_return_raw_document ON marketplace_returns (raw_document_id)');
            $this->addSql('ALTER TABLE marketplace_returns ADD CONSTRAINT FK_RETURN_RAW_DOCUMENT FOREIGN KEY (raw_document_id) REFERENCES marketplace_raw_documents (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }
}
