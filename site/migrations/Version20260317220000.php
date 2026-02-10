<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add system_code to cashflow_categories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cashflow_categories ADD system_code VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cashflow_categories DROP system_code');
    }
}
