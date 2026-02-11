<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_raw_documents table and link raw_document_id to marketplace sales/costs/returns';
    }

    public function up(Schema $schema): void
    {
        $hasCompaniesTable = $schema->hasTable('companies');

        $this->addSql('CREATE TABLE marketplace_raw_documents (id UUID NOT NULL, company_id UUID NOT NULL, marketplace VARCHAR(255) NOT NULL, document_type VARCHAR(50) NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL, raw_data JSON NOT NULL, api_endpoint VARCHAR(255) NOT NULL, records_count INT NOT NULL, records_created INT NOT NULL, records_skipped INT NOT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sync_notes TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_synced ON marketplace_raw_documents (company_id, synced_at)');
        $this->addSql('CREATE INDEX idx_marketplace_type ON marketplace_raw_documents (marketplace, document_type)');

        if ($hasCompaniesTable) {
            $this->addSql('ALTER TABLE marketplace_raw_documents ADD CONSTRAINT FK_RAW_DOCUMENT_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        $this->addSql('ALTER TABLE marketplace_sales ADD raw_document_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_costs ADD raw_document_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_returns ADD raw_document_id UUID DEFAULT NULL');

        $this->addSql('CREATE INDEX idx_sale_raw_document ON marketplace_sales (raw_document_id)');
        $this->addSql('CREATE INDEX idx_cost_raw_document ON marketplace_costs (raw_document_id)');
        $this->addSql('CREATE INDEX idx_return_raw_document ON marketplace_returns (raw_document_id)');

        $this->addSql('ALTER TABLE marketplace_sales ADD CONSTRAINT FK_SALE_RAW_DOCUMENT FOREIGN KEY (raw_document_id) REFERENCES marketplace_raw_documents (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_costs ADD CONSTRAINT FK_COST_RAW_DOCUMENT FOREIGN KEY (raw_document_id) REFERENCES marketplace_raw_documents (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE marketplace_returns ADD CONSTRAINT FK_RETURN_RAW_DOCUMENT FOREIGN KEY (raw_document_id) REFERENCES marketplace_raw_documents (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_returns DROP CONSTRAINT IF EXISTS FK_RETURN_RAW_DOCUMENT');
        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT IF EXISTS FK_COST_RAW_DOCUMENT');
        $this->addSql('ALTER TABLE marketplace_sales DROP CONSTRAINT IF EXISTS FK_SALE_RAW_DOCUMENT');
        $this->addSql('ALTER TABLE marketplace_raw_documents DROP CONSTRAINT IF EXISTS FK_RAW_DOCUMENT_COMPANY');

        $this->addSql('DROP INDEX IF EXISTS idx_return_raw_document');
        $this->addSql('DROP INDEX IF EXISTS idx_cost_raw_document');
        $this->addSql('DROP INDEX IF EXISTS idx_sale_raw_document');

        $this->addSql('ALTER TABLE marketplace_returns DROP COLUMN IF EXISTS raw_document_id');
        $this->addSql('ALTER TABLE marketplace_costs DROP COLUMN IF EXISTS raw_document_id');
        $this->addSql('ALTER TABLE marketplace_sales DROP COLUMN IF EXISTS raw_document_id');

        $this->addSql('DROP TABLE marketplace_raw_documents');
    }
}
