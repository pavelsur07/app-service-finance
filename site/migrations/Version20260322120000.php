<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_processing_batch, marketplace_staging and marketplace_reconciliation_log tables';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_processing_batch')) {
            $this->addSql('CREATE TABLE marketplace_processing_batch (id UUID NOT NULL, company_id UUID NOT NULL, raw_document_id UUID NOT NULL, total_records INT NOT NULL, sales_records INT NOT NULL, return_records INT NOT NULL, cost_records INT NOT NULL, storno_records INT NOT NULL, processed_records INT NOT NULL, failed_records INT NOT NULL, skipped_records INT NOT NULL, status VARCHAR(20) NOT NULL, error_message TEXT DEFAULT NULL, reconciliation_data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_batch_company_status ON marketplace_processing_batch (company_id, status)');
            $this->addSql('CREATE INDEX idx_batch_raw_doc ON marketplace_processing_batch (raw_document_id)');
            $this->addSql('ALTER TABLE marketplace_processing_batch ADD CONSTRAINT fk_batch_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE marketplace_processing_batch ADD CONSTRAINT fk_batch_raw_document FOREIGN KEY (raw_document_id) REFERENCES marketplace_raw_documents (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if (!$schema->hasTable('marketplace_staging')) {
            $this->addSql('CREATE TABLE marketplace_staging (id UUID NOT NULL, company_id UUID NOT NULL, processing_batch_id UUID NOT NULL, listing_id UUID DEFAULT NULL, marketplace VARCHAR(30) NOT NULL, source_record_id VARCHAR(255) NOT NULL, record_type VARCHAR(20) NOT NULL, raw_data JSON NOT NULL, amount NUMERIC(15, 2) NOT NULL, record_date DATE NOT NULL, marketplace_sku VARCHAR(100) NOT NULL, parsed_data JSON DEFAULT NULL, linked_to_product BOOLEAN NOT NULL, processing_status VARCHAR(20) NOT NULL, validation_errors JSON DEFAULT NULL, final_entity_id UUID DEFAULT NULL, final_entity_type VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX uniq_marketplace_source_record ON marketplace_staging (marketplace, source_record_id)');
            $this->addSql('CREATE INDEX idx_staging_batch_status ON marketplace_staging (processing_batch_id, processing_status)');
            $this->addSql('CREATE INDEX idx_staging_company_status ON marketplace_staging (company_id, processing_status)');
            $this->addSql('CREATE INDEX idx_staging_mp_type_status ON marketplace_staging (marketplace, record_type, processing_status)');
            $this->addSql('CREATE INDEX idx_staging_listing ON marketplace_staging (listing_id)');
            $this->addSql('ALTER TABLE marketplace_staging ADD CONSTRAINT fk_staging_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE marketplace_staging ADD CONSTRAINT fk_staging_batch FOREIGN KEY (processing_batch_id) REFERENCES marketplace_processing_batch (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE marketplace_staging ADD CONSTRAINT fk_staging_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if (!$schema->hasTable('marketplace_reconciliation_log')) {
            $this->addSql('CREATE TABLE marketplace_reconciliation_log (id UUID NOT NULL, processing_batch_id UUID NOT NULL, check_type VARCHAR(50) NOT NULL, passed BOOLEAN NOT NULL, details JSON NOT NULL, checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_recon_batch_type ON marketplace_reconciliation_log (processing_batch_id, check_type)');
            $this->addSql('CREATE INDEX idx_recon_passed ON marketplace_reconciliation_log (passed)');
            $this->addSql('ALTER TABLE marketplace_reconciliation_log ADD CONSTRAINT fk_recon_batch FOREIGN KEY (processing_batch_id) REFERENCES marketplace_processing_batch (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('marketplace_reconciliation_log')) {
            $this->addSql('ALTER TABLE marketplace_reconciliation_log DROP CONSTRAINT IF EXISTS fk_recon_batch');
            $this->addSql('DROP TABLE marketplace_reconciliation_log');
        }

        if ($schema->hasTable('marketplace_staging')) {
            $this->addSql('ALTER TABLE marketplace_staging DROP CONSTRAINT IF EXISTS fk_staging_listing');
            $this->addSql('ALTER TABLE marketplace_staging DROP CONSTRAINT IF EXISTS fk_staging_batch');
            $this->addSql('ALTER TABLE marketplace_staging DROP CONSTRAINT IF EXISTS fk_staging_company');
            $this->addSql('DROP TABLE marketplace_staging');
        }

        if ($schema->hasTable('marketplace_processing_batch')) {
            $this->addSql('ALTER TABLE marketplace_processing_batch DROP CONSTRAINT IF EXISTS fk_batch_raw_document');
            $this->addSql('ALTER TABLE marketplace_processing_batch DROP CONSTRAINT IF EXISTS fk_batch_company');
            $this->addSql('DROP TABLE marketplace_processing_batch');
        }
    }
}
