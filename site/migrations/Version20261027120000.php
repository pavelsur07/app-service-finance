<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BankSync: счета банка в подключении и их привязка к денежным счетам; Cash: признак
 * «выписка из банка по API» у денежного счёта. Новая таблица и nullable-колонка — данные
 * не трогаются.
 */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BankSync: bank_sync_account (bank accounts and links to money accounts); money_account.bank_api_source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_sync_account (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, account_number VARCHAR(64) NOT NULL, currency VARCHAR(3) NOT NULL, bic VARCHAR(20) DEFAULT NULL, description VARCHAR(128) DEFAULT NULL, external_id VARCHAR(64) DEFAULT NULL, enabled_in_bank BOOLEAN NOT NULL, present_in_bank BOOLEAN NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, money_account_id UUID DEFAULT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_bank_sync_account_number ON bank_sync_account (company_id, connection_id, account_number)');
        $this->addSql('CREATE INDEX idx_bank_sync_account_connection ON bank_sync_account (connection_id)');
        // Один денежный счёт получает выписку только из одного счёта банка.
        $this->addSql('CREATE UNIQUE INDEX uniq_bank_sync_account_money_account ON bank_sync_account (company_id, money_account_id) WHERE money_account_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_bank_sync_account_money_account ON bank_sync_account (money_account_id)');
        $this->addSql('ALTER TABLE bank_sync_account ADD CONSTRAINT fk_bank_sync_account_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_sync_account ADD CONSTRAINT fk_bank_sync_account_connection FOREIGN KEY (connection_id) REFERENCES bank_sync_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_sync_account ADD CONSTRAINT fk_bank_sync_account_money_account FOREIGN KEY (money_account_id) REFERENCES money_account (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE money_account ADD bank_api_source VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE money_account DROP bank_api_source');
        $this->addSql('DROP TABLE bank_sync_account');
    }
}
