<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260214120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Wildberries commissioner XLSX reports table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('wildberries_commissioner_xlsx_reports')) {
            return;
        }

        $this->addSql('CREATE TABLE wildberries_commissioner_xlsx_reports (id UUID NOT NULL, company_id UUID NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, original_filename VARCHAR(255) NOT NULL, storage_path VARCHAR(255) NOT NULL, file_hash VARCHAR(64) NOT NULL, headers_hash VARCHAR(64) DEFAULT NULL, format_status VARCHAR(32) DEFAULT NULL, status VARCHAR(32) NOT NULL, rows_total INT NOT NULL DEFAULT 0, rows_parsed INT NOT NULL DEFAULT 0, errors_count INT NOT NULL DEFAULT 0, warnings_count INT NOT NULL DEFAULT 0, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, errors_json JSON DEFAULT NULL, warnings_json JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_xlsx_company ON wildberries_commissioner_xlsx_reports (company_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_wb_commissioner_xlsx_status ON wildberries_commissioner_xlsx_reports (status)');
        $this->addSql("COMMENT ON COLUMN wildberries_commissioner_xlsx_reports.period_start IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN wildberries_commissioner_xlsx_reports.period_end IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN wildberries_commissioner_xlsx_reports.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN wildberries_commissioner_xlsx_reports.processed_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_wb_commissioner_xlsx_company') THEN ALTER TABLE wildberries_commissioner_xlsx_reports ADD CONSTRAINT fk_wb_commissioner_xlsx_company FOREIGN KEY (company_id) REFERENCES \"companies\" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$;");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('wildberries_commissioner_xlsx_reports')) {
            return;
        }

        $this->addSql('DROP TABLE wildberries_commissioner_xlsx_reports');
    }
}
