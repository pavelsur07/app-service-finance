<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename legacy marketplace_listing table to marketplace_listings';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('marketplace_listings') || !$schema->hasTable('marketplace_listing')) {
            return;
        }

        $this->addSql('ALTER TABLE marketplace_listing RENAME TO marketplace_listings');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('marketplace_listing') || !$schema->hasTable('marketplace_listings')) {
            return;
        }

        $this->addSql('ALTER TABLE marketplace_listings RENAME TO marketplace_listing');
    }
}
