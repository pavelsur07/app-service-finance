<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unprocessed costs fields to marketplace_raw_documents';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_raw_documents')) {
            return;
        }

        $table = $schema->getTable('marketplace_raw_documents');

        if (!$table->hasColumn('unprocessed_costs_count')) {
            $this->addSql('ALTER TABLE marketplace_raw_documents ADD unprocessed_costs_count INT DEFAULT 0 NOT NULL');
        }

        if (!$table->hasColumn('unprocessed_cost_types')) {
            $this->addSql('ALTER TABLE marketplace_raw_documents ADD unprocessed_cost_types JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_raw_documents')) {
            return;
        }

        $table = $schema->getTable('marketplace_raw_documents');

        if ($table->hasColumn('unprocessed_cost_types')) {
            $this->addSql('ALTER TABLE marketplace_raw_documents DROP COLUMN unprocessed_cost_types');
        }

        if ($table->hasColumn('unprocessed_costs_count')) {
            $this->addSql('ALTER TABLE marketplace_raw_documents DROP COLUMN unprocessed_costs_count');
        }
    }
}
