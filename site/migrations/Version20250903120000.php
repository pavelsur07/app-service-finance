<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create money_account table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE money_account (id UUID NOT NULL, company_id UUID NOT NULL, type VARCHAR(255) NOT NULL, name VARCHAR(150) NOT NULL, currency CHAR(3) NOT NULL, is_active BOOLEAN NOT NULL, is_default BOOLEAN NOT NULL, opening_balance NUMERIC(18, 2) NOT NULL, opening_balance_date DATE NOT NULL, current_balance NUMERIC(18, 2) NOT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, bank_name VARCHAR(150) DEFAULT NULL, account_number VARCHAR(64) DEFAULT NULL, iban VARCHAR(34) DEFAULT NULL, bic VARCHAR(20) DEFAULT NULL, corr_account VARCHAR(64) DEFAULT NULL, location VARCHAR(150) DEFAULT NULL, responsible_person VARCHAR(150) DEFAULT NULL, provider VARCHAR(100) DEFAULT NULL, wallet_id VARCHAR(100) DEFAULT NULL, meta JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_type ON money_account (company_id, type)');
        $this->addSql('CREATE INDEX idx_company_currency_active ON money_account (company_id, currency, is_active)');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_name ON money_account (company_id, name)');
        $this->addSql('ALTER TABLE money_account ADD CONSTRAINT FK_money_account_company FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN money_account.opening_balance_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN money_account.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE money_account');
    }
}
