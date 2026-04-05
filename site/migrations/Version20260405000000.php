<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_raw_processing_runs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_raw_processing_runs (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                raw_document_id UUID NOT NULL,
                marketplace VARCHAR(255) NOT NULL,
                document_type VARCHAR(50) NOT NULL,
                pipeline_trigger VARCHAR(255) NOT NULL,
                status VARCHAR(255) NOT NULL,
                profile_code VARCHAR(100) NOT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_error_message TEXT DEFAULT NULL,
                summary JSON DEFAULT NULL,
                details JSON DEFAULT NULL,
                retry_of_run_id UUID DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_raw_run_company_doc_started
            ON marketplace_raw_processing_runs (company_id, raw_document_id, started_at)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_raw_run_company_status_started
            ON marketplace_raw_processing_runs (company_id, status, started_at)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_raw_run_retry_of
            ON marketplace_raw_processing_runs (retry_of_run_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_raw_processing_runs');
    }
}
