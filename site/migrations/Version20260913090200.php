<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913090200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store explicit selected API scopes and independently owner-enabled resources with empty defaults.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE api_keys ADD selected_scopes JSON DEFAULT '[]' NOT NULL, ADD enabled_resources JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping permissions loses owner choices; use a forward fix.');
    }
}
