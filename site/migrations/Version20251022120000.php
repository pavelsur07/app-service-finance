<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create payment_plan_match table for automatic plan to transaction matching';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_plan_match (id CHAR(36) NOT NULL, company_id CHAR(36) NOT NULL, plan_id CHAR(36) NOT NULL, transaction_id CHAR(36) NOT NULL, matched_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_plan_match_transaction ON payment_plan_match (transaction_id)');
        $this->addSql('CREATE INDEX idx_payment_plan_match_company_plan ON payment_plan_match (company_id, plan_id)');
        $this->addSql('CREATE INDEX idx_payment_plan_match_company_transaction ON payment_plan_match (company_id, transaction_id)');
        $this->addSql('ALTER TABLE payment_plan_match ADD CONSTRAINT FK_B1641A0979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_plan_match ADD CONSTRAINT FK_B1641A04B32C4A FOREIGN KEY (plan_id) REFERENCES payment_plan (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_plan_match ADD CONSTRAINT FK_B1641A02FC0CB0F FOREIGN KEY (transaction_id) REFERENCES cash_transaction (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_plan_match');
    }
}
