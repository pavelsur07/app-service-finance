<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import_log table and dedupe_hash column for cash transactions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction ADD dedupe_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE TABLE import_log (id UUID NOT NULL, company_id UUID NOT NULL, source VARCHAR(64) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL --(DC2Type:datetime_immutable)
        , finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL --(DC2Type:datetime_immutable)
        , created_count INT NOT NULL DEFAULT 0, skipped_duplicates INT NOT NULL DEFAULT 0, errors_count INT NOT NULL DEFAULT 0, preview BOOLEAN NOT NULL DEFAULT FALSE, user_id UUID DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_import_log_company_started ON import_log (company_id, started_at)');
        $this->addSql('CREATE INDEX idx_import_log_company_finished ON import_log (company_id, finished_at DESC)');
        $this->addSql('ALTER TABLE import_log ADD CONSTRAINT FK_20E12F4D979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction DROP COLUMN dedupe_hash');
        $this->addSql('ALTER TABLE import_log DROP CONSTRAINT FK_20E12F4D979B1AD6');
        $this->addSql('DROP TABLE import_log');
    }
}
