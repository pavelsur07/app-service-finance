<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hierarchy fields to project directions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_directions ADD parent_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE project_directions ADD sort INT NOT NULL DEFAULT 0');
        $this->addSql('CREATE INDEX idx_project_directions_parent ON project_directions (parent_id)');
        $this->addSql('ALTER TABLE project_directions ADD CONSTRAINT fk_project_directions_parent FOREIGN KEY (parent_id) REFERENCES project_directions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_directions DROP CONSTRAINT fk_project_directions_parent');
        $this->addSql('DROP INDEX idx_project_directions_parent');
        $this->addSql('ALTER TABLE project_directions DROP COLUMN parent_id');
        $this->addSql('ALTER TABLE project_directions DROP COLUMN sort');
    }
}
