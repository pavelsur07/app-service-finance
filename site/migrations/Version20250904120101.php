<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250904120101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CashTransaction and MoneyAccountDailyBalance tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_transaction (id UUID NOT NULL, company_id UUID NOT NULL, money_account_id UUID NOT NULL, counterparty_id UUID DEFAULT NULL, cashflow_category_id UUID DEFAULT NULL, direction VARCHAR(255) NOT NULL, amount NUMERIC(18, 2) NOT NULL, currency VARCHAR(3) NOT NULL, occurred_at DATE NOT NULL, booked_at DATE NOT NULL, description VARCHAR(1024) DEFAULT NULL, external_id VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_company_account_occurred ON cash_transaction (company_id, money_account_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_company_occurred ON cash_transaction (company_id, occurred_at)');
        $this->addSql('CREATE TABLE money_account_daily_balance (id UUID NOT NULL, company_id UUID NOT NULL, money_account_id UUID NOT NULL, date DATE NOT NULL, opening_balance NUMERIC(18, 2) NOT NULL, inflow NUMERIC(18, 2) NOT NULL, outflow NUMERIC(18, 2) NOT NULL, closing_balance NUMERIC(18, 2) NOT NULL, currency VARCHAR(3) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_account_date ON money_account_daily_balance (company_id, money_account_id, date)');
        $this->addSql('CREATE INDEX idx_company_date ON money_account_daily_balance (company_id, date)');
        $this->addSql('CREATE INDEX idx_account_date ON money_account_daily_balance (money_account_id, date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cash_transaction');
        $this->addSql('DROP TABLE money_account_daily_balance');
    }
}
