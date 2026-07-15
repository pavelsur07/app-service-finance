<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lifecycle and revision metadata to Cash transaction auto rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD revision INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD disabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD created_by_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD updated_by_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD disabled_by_user_id UUID DEFAULT NULL');
        $this->addSql('UPDATE cash_transaction_auto_rule SET created_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP');
        $this->addSql('UPDATE cash_transaction_auto_rule SET disabled_at = CURRENT_TIMESTAMP WHERE is_active = FALSE');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ALTER created_at SET NOT NULL');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ALTER updated_at SET NOT NULL');
        $this->addSql("COMMENT ON COLUMN cash_transaction_auto_rule.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN cash_transaction_auto_rule.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN cash_transaction_auto_rule.disabled_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN revision');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN created_at');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN updated_at');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN disabled_at');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN created_by_user_id');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN updated_by_user_id');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN disabled_by_user_id');
    }
}
