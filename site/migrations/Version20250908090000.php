<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250908090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cash transaction auto rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_transaction_auto_rule (id UUID NOT NULL, company_id UUID NOT NULL, cashflow_category_id UUID NOT NULL, name VARCHAR(255) NOT NULL, action VARCHAR(255) NOT NULL, operation_type VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ctar_company ON cash_transaction_auto_rule (company_id)');
        $this->addSql('CREATE INDEX idx_ctar_category ON cash_transaction_auto_rule (cashflow_category_id)');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD CONSTRAINT FK_CTAR_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD CONSTRAINT FK_CTAR_CATEGORY FOREIGN KEY (cashflow_category_id) REFERENCES cashflow_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP CONSTRAINT FK_CTAR_COMPANY');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP CONSTRAINT FK_CTAR_CATEGORY');
        $this->addSql('DROP TABLE cash_transaction_auto_rule');
    }
}
