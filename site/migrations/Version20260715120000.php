<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add priority and active state to Cash transaction auto rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD priority INT DEFAULT 100 NOT NULL');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD is_active BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('CREATE INDEX idx_ctar_company_active_priority ON cash_transaction_auto_rule (company_id, is_active, priority)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ctar_company_active_priority');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN priority');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN is_active');
    }
}
