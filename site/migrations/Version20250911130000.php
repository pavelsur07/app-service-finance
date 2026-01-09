<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250911130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cash_transaction_auto_rule_condition table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_transaction_auto_rule_condition (id UUID NOT NULL, auto_rule_id UUID NOT NULL, field VARCHAR(255) NOT NULL, operator VARCHAR(255) NOT NULL, counterparty_id UUID DEFAULT NULL, value VARCHAR(255) DEFAULT NULL, value_to VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ctarc_rule ON cash_transaction_auto_rule_condition (auto_rule_id)');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule_condition ADD CONSTRAINT fk_ctarc_rule FOREIGN KEY (auto_rule_id) REFERENCES cash_transaction_auto_rule (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule_condition ADD CONSTRAINT fk_ctarc_counterparty FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cash_transaction_auto_rule_condition');
    }
}
