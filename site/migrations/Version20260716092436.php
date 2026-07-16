<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716092436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add exact money account condition to Cash transaction auto rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction_auto_rule_condition ADD money_account_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ctarc_money_account ON cash_transaction_auto_rule_condition (money_account_id)');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule_condition ADD CONSTRAINT FK_B362E0EFB4D8145A FOREIGN KEY (money_account_id) REFERENCES money_account (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction_auto_rule_condition DROP CONSTRAINT FK_B362E0EFB4D8145A');
        $this->addSql('DROP INDEX idx_ctarc_money_account');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule_condition DROP money_account_id');
    }
}
