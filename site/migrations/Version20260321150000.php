<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_system and deleted_at fields for marketplace_cost_categories';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_cost_categories')) {
            return;
        }

        $table = $schema->getTable('marketplace_cost_categories');

        if (!$table->hasColumn('is_system')) {
            $this->addSql('ALTER TABLE marketplace_cost_categories ADD is_system BOOLEAN DEFAULT FALSE NOT NULL');
        }

        if (!$table->hasColumn('deleted_at')) {
            $this->addSql('ALTER TABLE marketplace_cost_categories ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_cost_categories')) {
            return;
        }

        $table = $schema->getTable('marketplace_cost_categories');

        if ($table->hasColumn('deleted_at')) {
            $this->addSql('ALTER TABLE marketplace_cost_categories DROP COLUMN deleted_at');
        }

        if ($table->hasColumn('is_system')) {
            $this->addSql('ALTER TABLE marketplace_cost_categories DROP COLUMN is_system');
        }
    }
}
