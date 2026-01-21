<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cash file import jobs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_file_import_jobs (id UUID NOT NULL, company_id UUID NOT NULL, money_account_id UUID NOT NULL, import_log_id UUID DEFAULT NULL, source VARCHAR(32) NOT NULL, filename VARCHAR(255) NOT NULL, file_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, mapping JSON NOT NULL, options JSON NOT NULL DEFAULT \'[]\', PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_cash_file_import_jobs_status ON cash_file_import_jobs (status)');
        $this->addSql('CREATE INDEX idx_cash_file_import_jobs_filehash ON cash_file_import_jobs (file_hash)');
        $this->addSql('CREATE INDEX idx_cash_file_import_jobs_company ON cash_file_import_jobs (company_id)');
        $this->addSql('CREATE INDEX idx_cash_file_import_jobs_import_log ON cash_file_import_jobs (import_log_id)');
        $this->addSql('ALTER TABLE cash_file_import_jobs ADD CONSTRAINT fk_cash_file_import_jobs_company FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cash_file_import_jobs ADD CONSTRAINT fk_cash_file_import_jobs_money_account FOREIGN KEY (money_account_id) REFERENCES money_account (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cash_file_import_jobs ADD CONSTRAINT fk_cash_file_import_jobs_import_log FOREIGN KEY (import_log_id) REFERENCES import_log (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN cash_file_import_jobs.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN cash_file_import_jobs.started_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN cash_file_import_jobs.finished_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_file_import_jobs DROP CONSTRAINT fk_cash_file_import_jobs_company');
        $this->addSql('ALTER TABLE cash_file_import_jobs DROP CONSTRAINT fk_cash_file_import_jobs_money_account');
        $this->addSql('ALTER TABLE cash_file_import_jobs DROP CONSTRAINT fk_cash_file_import_jobs_import_log');
        $this->addSql('DROP TABLE cash_file_import_jobs');
    }
}
