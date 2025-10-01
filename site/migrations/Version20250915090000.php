<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250915090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project direction reference to cash transaction auto rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD project_direction_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ctar_project_direction ON cash_transaction_auto_rule (project_direction_id)');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD CONSTRAINT fk_ctar_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ctar_project_direction');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP CONSTRAINT fk_ctar_project_direction');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN project_direction_id');
    }
}
