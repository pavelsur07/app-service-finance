<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250910121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create project_directions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_directions (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_project_directions_company ON project_directions (company_id)');
        $this->addSql('ALTER TABLE project_directions ADD CONSTRAINT fk_project_directions_company FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_directions');
    }
}
