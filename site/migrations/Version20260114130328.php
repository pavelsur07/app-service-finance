<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260114130328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bank connection and import cursor tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_connection (id SERIAL NOT NULL, company_id UUID NOT NULL, bank_code VARCHAR(32) NOT NULL, api_key VARCHAR(255) NOT NULL, base_url VARCHAR(255) NOT NULL, is_active BOOLEAN DEFAULT TRUE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_bank_connection_company ON bank_connection (company_id)');
        $this->addSql('ALTER TABLE bank_connection ADD CONSTRAINT FK_bank_connection_company FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE bank_import_cursor (id SERIAL NOT NULL, company_id UUID NOT NULL, bank_code VARCHAR(32) NOT NULL, account_number VARCHAR(64) NOT NULL, last_imported_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_bank_import_cursor_company ON bank_import_cursor (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_bank_account ON bank_import_cursor (company_id, bank_code, account_number)');
        $this->addSql('ALTER TABLE bank_import_cursor ADD CONSTRAINT FK_bank_import_cursor_company FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_import_cursor DROP CONSTRAINT FK_bank_import_cursor_company');
        $this->addSql('DROP TABLE bank_import_cursor');

        $this->addSql('ALTER TABLE bank_connection DROP CONSTRAINT FK_bank_connection_company');
        $this->addSql('DROP TABLE bank_connection');
    }
}
