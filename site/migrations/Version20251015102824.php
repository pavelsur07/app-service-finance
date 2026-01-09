<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251015102824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment plan and recurrence rule tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_recurrence_rule (id UUID NOT NULL, company_id UUID NOT NULL, frequency VARCHAR(16) NOT NULL, "interval" INT DEFAULT 1 NOT NULL, by_day VARCHAR(32) DEFAULT NULL, day_of_month INT DEFAULT NULL, until DATE DEFAULT NULL, active BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_payment_recurrence_company_active ON payment_recurrence_rule (company_id, active)');
        $this->addSql('ALTER TABLE payment_recurrence_rule ADD CONSTRAINT FK_PAYMENT_RECURRENCE_RULE_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE payment_plan (id UUID NOT NULL, company_id UUID NOT NULL, money_account_id UUID DEFAULT NULL, cashflow_category_id UUID NOT NULL, counterparty_id UUID DEFAULT NULL, recurrence_rule_id UUID DEFAULT NULL, planned_at DATE NOT NULL, amount NUMERIC(14, 2) NOT NULL, status VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_payment_plan_company_planned_at ON payment_plan (company_id, planned_at)');
        $this->addSql('CREATE INDEX idx_payment_plan_company_status ON payment_plan (company_id, status)');
        $this->addSql('CREATE INDEX idx_payment_plan_company_category ON payment_plan (company_id, cashflow_category_id)');
        $this->addSql('CREATE INDEX idx_payment_plan_company_account ON payment_plan (company_id, money_account_id)');
        $this->addSql('ALTER TABLE payment_plan ADD CONSTRAINT FK_PAYMENT_PLAN_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_plan ADD CONSTRAINT FK_PAYMENT_PLAN_MONEY_ACCOUNT FOREIGN KEY (money_account_id) REFERENCES "money_account" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_plan ADD CONSTRAINT FK_PAYMENT_PLAN_CASHFLOW_CATEGORY FOREIGN KEY (cashflow_category_id) REFERENCES "cashflow_categories" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_plan ADD CONSTRAINT FK_PAYMENT_PLAN_COUNTERPARTY FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_plan ADD CONSTRAINT FK_PAYMENT_PLAN_RECURRENCE_RULE FOREIGN KEY (recurrence_rule_id) REFERENCES payment_recurrence_rule (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_plan DROP CONSTRAINT FK_PAYMENT_PLAN_RECURRENCE_RULE');
        $this->addSql('ALTER TABLE payment_plan DROP CONSTRAINT FK_PAYMENT_PLAN_COMPANY');
        $this->addSql('ALTER TABLE payment_plan DROP CONSTRAINT FK_PAYMENT_PLAN_MONEY_ACCOUNT');
        $this->addSql('ALTER TABLE payment_plan DROP CONSTRAINT FK_PAYMENT_PLAN_CASHFLOW_CATEGORY');
        $this->addSql('ALTER TABLE payment_plan DROP CONSTRAINT FK_PAYMENT_PLAN_COUNTERPARTY');
        $this->addSql('DROP TABLE payment_plan');
        $this->addSql('ALTER TABLE payment_recurrence_rule DROP CONSTRAINT FK_PAYMENT_RECURRENCE_RULE_COMPANY');
        $this->addSql('DROP TABLE payment_recurrence_rule');
    }
}
