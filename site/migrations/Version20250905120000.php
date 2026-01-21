<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cash file import profiles table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_file_import_profile (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(64) NOT NULL, mapping JSON NOT NULL, options JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_cash_file_import_profile_company ON cash_file_import_profile (company_id)');
        $this->addSql('CREATE INDEX idx_cash_file_import_profile_company_type ON cash_file_import_profile (company_id, type)');
        $this->addSql('ALTER TABLE cash_file_import_profile ADD CONSTRAINT FK_cash_file_import_profile_company FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_file_import_profile DROP CONSTRAINT FK_cash_file_import_profile_company');
        $this->addSql('DROP TABLE cash_file_import_profile');
    }
}
