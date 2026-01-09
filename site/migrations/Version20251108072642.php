<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251108072642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE wildberries_import_log (id UUID NOT NULL, company_id UUID NOT NULL, source VARCHAR(64) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_count INT NOT NULL, skipped_duplicates INT NOT NULL, errors_count INT NOT NULL, preview BOOLEAN NOT NULL, user_id UUID DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_6354EAEB979B1AD6 ON wildberries_import_log (company_id)');
        $this->addSql('CREATE INDEX idx_wb_import_log_company_started ON wildberries_import_log (company_id, started_at)');
        $this->addSql('CREATE INDEX idx_wb_import_log_company_finished ON wildberries_import_log (company_id, finished_at)');
        $this->addSql('CREATE INDEX idx_wb_import_log_source ON wildberries_import_log (source)');
        $this->addSql('COMMENT ON COLUMN wildberries_import_log.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN wildberries_import_log.finished_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE wildberries_import_log ADD CONSTRAINT FK_6354EAEB979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE wildberries_import_log DROP CONSTRAINT FK_6354EAEB979B1AD6');
        $this->addSql('DROP TABLE wildberries_import_log');
    }
}
