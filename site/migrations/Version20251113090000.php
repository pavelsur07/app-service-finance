<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251113090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add counterparty reference to cash transaction auto rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD counterparty_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ctar_counterparty ON cash_transaction_auto_rule (counterparty_id)');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD CONSTRAINT fk_ctar_counterparty FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ctar_counterparty');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP CONSTRAINT fk_ctar_counterparty');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN counterparty_id');
    }
}
