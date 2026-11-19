<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project code, description and company directory revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_directions ADD code VARCHAR(15) DEFAULT NULL, ADD description VARCHAR(300) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_direction_company_code ON project_directions (company_id, code)');
        $this->addSql('ALTER TABLE companies ADD project_direction_revision UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP project_direction_revision');
        $this->addSql('DROP INDEX uniq_project_direction_company_code');
        $this->addSql('ALTER TABLE project_directions DROP code, DROP description');
    }
}
