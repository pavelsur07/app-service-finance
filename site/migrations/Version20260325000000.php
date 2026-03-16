<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_job_logs table for async job tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_job_logs (
                id          UUID         NOT NULL,
                company_id  UUID         NOT NULL,
                job_type    VARCHAR(50)  NOT NULL,
                status      VARCHAR(20)  NOT NULL,
                started_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                summary     JSON         DEFAULT NULL,
                details     JSON         DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_job_logs.started_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_job_logs.finished_at
                IS '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_job_log_company_type
                ON marketplace_job_logs (company_id, job_type, started_at)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS marketplace_job_logs');
    }
}
