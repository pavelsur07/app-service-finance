<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add soft-delete audit fields to P&L documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE documents ADD deleted_by VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE documents ADD delete_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN documents.deleted_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_documents_company_deleted_at ON documents (company_id, deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_documents_company_deleted_at');
        $this->addSql('ALTER TABLE documents DROP deleted_at');
        $this->addSql('ALTER TABLE documents DROP deleted_by');
        $this->addSql('ALTER TABLE documents DROP delete_reason');
    }
}
