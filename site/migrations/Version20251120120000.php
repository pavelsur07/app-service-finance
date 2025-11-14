<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop deprecated document type column and related database artifacts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_documents_company_type_date');
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_enum_check');
        $this->addSql('ALTER TABLE documents DROP COLUMN type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE documents ADD type VARCHAR(255) DEFAULT 'OTHER'");
        $this->addSql("UPDATE documents SET type = 'OTHER' WHERE type IS NULL");
        $this->addSql('ALTER TABLE documents ALTER COLUMN type SET NOT NULL');
        $this->addSql('CREATE INDEX idx_documents_company_type_date ON documents (company_id, type, date DESC)');
    }
}
