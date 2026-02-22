<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Normalize marketplace_listings.size values: NULL -> 'UNKNOWN'";
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $table = $schema->getTable('marketplace_listings');

        if (!$table->hasColumn('size')) {
            return;
        }

        $this->addSql("UPDATE marketplace_listings SET size = 'UNKNOWN' WHERE size IS NULL");
        $this->addSql("ALTER TABLE marketplace_listings ALTER COLUMN size SET DEFAULT 'UNKNOWN'");
        $this->addSql('ALTER TABLE marketplace_listings ALTER COLUMN size SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $table = $schema->getTable('marketplace_listings');

        if (!$table->hasColumn('size')) {
            return;
        }

        $this->addSql('ALTER TABLE marketplace_listings ALTER COLUMN size DROP NOT NULL');
        $this->addSql('ALTER TABLE marketplace_listings ALTER COLUMN size DROP DEFAULT');
    }
}
