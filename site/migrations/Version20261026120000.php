<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Банковская интеграция переезжает из Cash в модуль BankSync: таблицы подключений и курсоров
 * создаются заново, токен — только шифротекстом. Старые таблицы на проде пусты (проверено
 * 26.10.2026); если строки появились — миграция останавливается, а не теряет их.
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BankSync: bank_sync_connection and bank_sync_import_cursor replace empty cash_bank_* tables';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT (SELECT COUNT(*) FROM cash_bank_connection) + (SELECT COUNT(*) FROM cash_bank_import_cursor)') > 0,
            'cash_bank_connection or cash_bank_import_cursor has rows: move them to BankSync before dropping.',
        );

        $this->addSql('CREATE TABLE bank_sync_connection (id UUID NOT NULL, company_id UUID NOT NULL, bank_code VARCHAR(32) NOT NULL, base_url VARCHAR(255) NOT NULL, credentials_encrypted TEXT NOT NULL, credentials_key_version VARCHAR(32) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_bank_sync_connection_company_bank ON bank_sync_connection (company_id, bank_code)');
        $this->addSql('ALTER TABLE bank_sync_connection ADD CONSTRAINT fk_bank_sync_connection_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE bank_sync_import_cursor (id UUID NOT NULL, company_id UUID NOT NULL, bank_code VARCHAR(32) NOT NULL, account_number VARCHAR(64) NOT NULL, last_imported_date DATE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_bank_sync_import_cursor_account ON bank_sync_import_cursor (company_id, bank_code, account_number)');
        $this->addSql('ALTER TABLE bank_sync_import_cursor ADD CONSTRAINT fk_bank_sync_import_cursor_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');

        $this->addSql('DROP TABLE cash_bank_connection');
        $this->addSql('DROP TABLE cash_bank_import_cursor');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bank_sync_connection');
        $this->addSql('DROP TABLE bank_sync_import_cursor');

        $this->addSql('CREATE TABLE cash_bank_connection (id UUID NOT NULL, company_id UUID NOT NULL, bank_code VARCHAR(32) NOT NULL, api_key VARCHAR(255) NOT NULL, base_url VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_B43A74ED979B1AD6 ON cash_bank_connection (company_id)');
        $this->addSql('COMMENT ON COLUMN cash_bank_connection.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN cash_bank_connection.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE cash_bank_connection ADD CONSTRAINT FK_B43A74ED979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE cash_bank_import_cursor (id UUID NOT NULL, company_id UUID NOT NULL, bank_code VARCHAR(32) NOT NULL, account_number VARCHAR(64) NOT NULL, last_imported_date DATE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FEBA0CA0979B1AD6 ON cash_bank_import_cursor (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bank_import_cursor_company_bank_account ON cash_bank_import_cursor (company_id, bank_code, account_number)');
        $this->addSql('COMMENT ON COLUMN cash_bank_import_cursor.last_imported_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN cash_bank_import_cursor.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE cash_bank_import_cursor ADD CONSTRAINT FK_FEBA0CA0979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
