<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Durable tenant-scoped mapping diagnostics runs and per-listing results; preserves last completed report.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_mapping_diagnostic_runs (
                id UUID NOT NULL PRIMARY KEY, company_id UUID NOT NULL, connection_id UUID NOT NULL,
                marketplace VARCHAR(20) NOT NULL, size_characteristic_id VARCHAR(255) DEFAULT NULL,
                rule_version VARCHAR(40) NOT NULL, settings_fingerprint VARCHAR(64) NOT NULL,
                input_fingerprint VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL,
                requested_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT NULL,
                after_listing_id UUID DEFAULT NULL, processed INT NOT NULL DEFAULT 0, total INT NOT NULL,
                counts JSONB NOT NULL DEFAULT '{}', error_reason VARCHAR(64) DEFAULT NULL,
                CONSTRAINT uniq_ms_diagnostic_tenant_id UNIQUE (company_id, id),
                CONSTRAINT fk_ms_diagnostic_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT,
                CONSTRAINT chk_ms_diagnostic_marketplace CHECK (marketplace IN ('ozon', 'wildberries')),
                CONSTRAINT chk_ms_diagnostic_status CHECK (status IN ('running', 'completed', 'stale', 'failed')),
                CONSTRAINT chk_ms_diagnostic_progress CHECK (processed >= 0 AND total >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ms_diagnostic_scope ON moysklad_mapping_diagnostic_runs (company_id, connection_id, marketplace, requested_at DESC, id DESC)');
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_mapping_diagnostic_rows (
                company_id UUID NOT NULL, run_id UUID NOT NULL, listing_id UUID NOT NULL,
                status VARCHAR(32) NOT NULL, reasons JSONB NOT NULL, manual_review BOOLEAN NOT NULL,
                payload JSONB NOT NULL, checked_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (company_id, run_id, listing_id),
                CONSTRAINT fk_ms_diagnostic_row_run FOREIGN KEY (company_id, run_id) REFERENCES moysklad_mapping_diagnostic_runs (company_id, id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ms_diagnostic_rows_status ON moysklad_mapping_diagnostic_rows (company_id, run_id, status, listing_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Diagnostics audit must be preserved; use a forward migration.');
    }
}
