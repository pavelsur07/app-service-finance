<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250919000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project_direction relation to cash_transaction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction ADD project_direction_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ct_project_direction ON cash_transaction (project_direction_id)');
        $this->addSql('ALTER TABLE cash_transaction ADD CONSTRAINT fk_ct_project_direction FOREIGN KEY (project_direction_id) REFERENCES project_directions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_transaction DROP CONSTRAINT fk_ct_project_direction');
        $this->addSql('DROP INDEX idx_ct_project_direction');
        $this->addSql('ALTER TABLE cash_transaction DROP COLUMN project_direction_id');
    }
}
