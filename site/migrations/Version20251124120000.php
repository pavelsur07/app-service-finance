<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create finance_loan and finance_loan_payment_schedule tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE finance_loan (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(255) NOT NULL, lender_name VARCHAR(255) DEFAULT NULL, principal_amount NUMERIC(18, 2) NOT NULL, remaining_principal NUMERIC(18, 2) NOT NULL, interest_rate NUMERIC(8, 4) DEFAULT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, payment_day_of_month SMALLINT DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL --(DC2Type:datetime_immutable), updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL --(DC2Type:datetime_immutable), PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_loan_company_status ON finance_loan (company_id, status)');
        $this->addSql('ALTER TABLE finance_loan ADD CONSTRAINT FK_FINANCE_LOAN_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_loan_payment_schedule (id UUID NOT NULL, loan_id UUID NOT NULL, due_date DATE NOT NULL, total_payment_amount NUMERIC(18, 2) NOT NULL, principal_part NUMERIC(18, 2) NOT NULL, interest_part NUMERIC(18, 2) NOT NULL, fee_part NUMERIC(18, 2) NOT NULL, is_paid BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL --(DC2Type:datetime_immutable), updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL --(DC2Type:datetime_immutable), PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_loan_payment_schedule_loan ON finance_loan_payment_schedule (loan_id)');
        $this->addSql('CREATE INDEX idx_finance_loan_payment_schedule_due_date ON finance_loan_payment_schedule (due_date)');
        $this->addSql('ALTER TABLE finance_loan_payment_schedule ADD CONSTRAINT FK_FINANCE_LOAN_PAYMENT_SCHEDULE_LOAN FOREIGN KEY (loan_id) REFERENCES finance_loan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_loan_payment_schedule DROP CONSTRAINT FK_FINANCE_LOAN_PAYMENT_SCHEDULE_LOAN');
        $this->addSql('ALTER TABLE finance_loan DROP CONSTRAINT FK_FINANCE_LOAN_COMPANY');
        $this->addSql('DROP TABLE finance_loan_payment_schedule');
        $this->addSql('DROP TABLE finance_loan');
    }
}
