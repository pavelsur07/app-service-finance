<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_raw_processing_step_runs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_raw_processing_step_runs (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                processing_run_id UUID NOT NULL,
                step VARCHAR(255) NOT NULL,
                status VARCHAR(255) NOT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                processed_count INT NOT NULL DEFAULT 0,
                failed_count INT NOT NULL DEFAULT 0,
                skipped_count INT NOT NULL DEFAULT 0,
                created_entities_json JSON DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                details_json JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_step_run_company_run
            ON marketplace_raw_processing_step_runs (company_id, processing_run_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_step_run_run_step
            ON marketplace_raw_processing_step_runs (processing_run_id, step)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_step_run_per_run
            ON marketplace_raw_processing_step_runs (processing_run_id, step)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_raw_processing_step_runs');
    }
}
