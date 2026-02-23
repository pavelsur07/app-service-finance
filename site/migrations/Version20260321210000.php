<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable name column to marketplace_listings';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $table = $schema->getTable('marketplace_listings');

        if (!$table->hasColumn('name')) {
            $this->addSql('ALTER TABLE marketplace_listings ADD name VARCHAR(500) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $table = $schema->getTable('marketplace_listings');

        if ($table->hasColumn('name')) {
            $this->addSql('ALTER TABLE marketplace_listings DROP COLUMN name');
        }
    }
}
